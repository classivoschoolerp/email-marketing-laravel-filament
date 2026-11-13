<?php

declare(strict_types=1);

namespace App\Sending;

use App\Models\DeliveryServer;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Crypto\DkimSigner;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message as SymfonyMessage;
use Symfony\Component\Mime\Part\AbstractPart;
use Symfony\Component\Mime\Part\Multipart\AlternativePart;
use Symfony\Component\Mime\Part\Multipart\MixedPart;
use Symfony\Component\Mime\Part\Multipart\RelatedPart;
use Symfony\Component\Mime\Part\TextPart;

/**
 * Maps the transport-agnostic Message DTO + DeliveryServer policy columns to
 * an RFC 2822 / RFC 5322 message (Symfony MIME — spec §2.9.2 common fields):
 *
 *  - Force FROM / Force REPLY-TO (F-2.9.2-027) override the message envelope
 *    with the server's identity.
 *  - Additional custom headers (F-2.9.2-032) are stamped on every message.
 *  - DKIM signing (F-2.9.2-035/036): when enabled with a private key, the
 *    message is signed with the server's key + selector for the effective
 *    From domain.
 *
 * utf8mb4 subjects/bodies (CJK + emoji) round-trip via Symfony's RFC 2047
 * header encoding — covered by the pickup-directory feature test (§0.1).
 */
class MimeBuilder
{
    public const DEFAULT_DKIM_SELECTOR = 'default';

    /** MIME subtype of the AMP for Email part: `text/x-amp-html`. */
    public const AMP_SUBTYPE = 'x-amp-html';

    /**
     * Effective From identity after Force-FROM.
     *
     * @return array{0: string, 1: string|null} [email, name]
     */
    public function effectiveFrom(Message $message, DeliveryServer $server): array
    {
        if ($server->force_from && $server->from_email !== null) {
            return [$server->from_email, $server->from_name];
        }

        $email = $message->fromEmail !== '' ? $message->fromEmail : (string) $server->from_email;

        return [$email, $message->fromName ?? $server->from_name];
    }

    /** Effective Reply-To after Force-REPLY-TO (null = no Reply-To header). */
    public function effectiveReplyTo(Message $message, DeliveryServer $server): ?string
    {
        if ($server->force_reply_to && $server->reply_to_email !== null) {
            return $server->reply_to_email;
        }

        return $message->replyTo ?? $server->reply_to_email;
    }

    /**
     * All headers to stamp: message headers first, then the server's custom
     * headers (server-level headers win on collision — they are the operator
     * policy).
     *
     * @return array<string, string>
     */
    public function effectiveHeaders(Message $message, DeliveryServer $server): array
    {
        $custom = [];

        foreach ((array) ($server->custom_headers ?? []) as $name => $value) {
            $custom[(string) $name] = (string) $value;
        }

        return array_merge($message->headers, $custom);
    }

    /** Build the full MIME message (DKIM-signed when the server says so). */
    public function build(Message $message, DeliveryServer $server): SymfonyMessage
    {
        $email = new Email;

        [$fromEmail, $fromName] = $this->effectiveFrom($message, $server);
        $email->from(new Address($fromEmail, $fromName ?? ''));

        foreach ($message->recipients() as $address => $name) {
            $email->addTo(new Address($address, $name ?? ''));
        }

        $replyTo = $this->effectiveReplyTo($message, $server);

        if ($replyTo !== null && $replyTo !== '') {
            $email->replyTo(new Address($replyTo));
        }

        $email->subject($message->subject);

        if ($message->html !== null) {
            $email->html($message->html);
        }

        // Symfony requires at least one body part.
        $email->text($message->text ?? ($message->html === null ? '' : strip_tags($message->html)));

        foreach ($this->effectiveHeaders($message, $server) as $name => $value) {
            $headers = $email->getHeaders();

            if ($headers->has($name)) {
                $headers->remove($name);
            }

            $headers->addTextHeader($name, $value);
        }

        foreach ($message->attachments as $attachment) {
            $email->attach(
                $attachment['content'],
                $attachment['name'],
                $attachment['mime'] ?? null,
            );
        }

        // Inline (CID) images — html references them as cid:{cid} (WP-13
        // embed_images option, F-2.3.4-013).
        foreach ($message->embedded as $embedded) {
            $email->embed(
                $embedded['content'],
                $embedded['cid'],
                $embedded['mime'] ?? null,
            );
        }

        $this->maybeAddAmpPart($email, $message);

        return $this->maybeSign($email, $server, $fromEmail);
    }

    /* ================================================================ *
     *  AMP for Email (spec §2.5.4 F-2.5.4-014, WP-46)
     * ================================================================ */

    /**
     * Add the `text/x-amp-html` part, in the position the AMP for Email spec
     * requires.
     *
     * THE ORDER MATTERS AND IS THE WHOLE POINT. A MIME client picks the LAST
     * alternative it understands, so the AMP part must sit BEFORE the HTML
     * part: a client that knows AMP takes the AMP part, and every client that
     * does not walks past it to the HTML and renders the ordinary email. The
     * resulting tree is
     *
     *     multipart/alternative
     *       ├── text/plain
     *       ├── text/x-amp-html      ← AMP, before HTML
     *       └── text/html
     *
     * Gmail additionally requires text/plain to be present, which the builder
     * already guarantees (Symfony needs at least one body part and build()
     * always sets a text one).
     *
     * The part is added by REBUILDING the body tree Symfony generated rather
     * than by hand-assembling headers: attachments (multipart/mixed) and CID
     * images (multipart/related) keep their exact existing structure, and a
     * message with no AMP body is byte-identical to what this class produced
     * before WP-46 — the method returns immediately in that case.
     */
    private function maybeAddAmpPart(Email $email, Message $message): void
    {
        $amp = trim((string) $message->amp);

        if ($amp === '') {
            return;
        }

        $body = $email->getBody();
        $rebuilt = $this->injectAmp($body, new TextPart($amp, 'utf-8', self::AMP_SUBTYPE));

        if ($rebuilt !== null) {
            $email->setBody($rebuilt);
        }
    }

    /**
     * Walk down the generated tree to the alternative level and insert the AMP
     * part immediately before the first text/html part.
     */
    private function injectAmp(AbstractPart $part, TextPart $ampPart): ?AbstractPart
    {
        if ($part instanceof AlternativePart) {
            $parts = [];
            $inserted = false;

            foreach ($part->getParts() as $child) {
                if (! $inserted && $child instanceof TextPart && $child->getMediaSubtype() === 'html') {
                    $parts[] = $ampPart;
                    $inserted = true;
                }

                $parts[] = $child;
            }

            if (! $inserted) {
                $parts[] = $ampPart;
            }

            return new AlternativePart(...$parts);
        }

        // multipart/related (CID images) and multipart/mixed (attachments)
        // both keep the alternative as their FIRST child.
        if ($part instanceof RelatedPart || $part instanceof MixedPart) {
            $children = $part->getParts();

            if ($children === []) {
                return null;
            }

            $first = $this->injectAmp($children[0], $ampPart);

            if ($first === null) {
                return null;
            }

            $children[0] = $first;

            return $part instanceof RelatedPart
                ? new RelatedPart(...$children)
                : new MixedPart(...$children);
        }

        // A single text or html part (plain-text-only / html-only message):
        // wrap it in an alternative so the AMP part has somewhere to live,
        // preserving the required plain → amp → html order.
        if ($part instanceof TextPart) {
            return $part->getMediaSubtype() === 'html'
                ? new AlternativePart($ampPart, $part)
                : new AlternativePart($part, $ampPart);
        }

        return null;
    }

    private function maybeSign(Email $email, DeliveryServer $server, string $fromEmail): SymfonyMessage
    {
        if (! $server->dkim_signing_enabled || $server->dkim_private_key === null) {
            return $email;
        }

        $domain = str_contains($fromEmail, '@')
            ? substr($fromEmail, (int) strrpos($fromEmail, '@') + 1)
            : $fromEmail;

        $signer = new DkimSigner(
            $server->dkim_private_key,
            $domain,
            $server->dkim_selector ?? self::DEFAULT_DKIM_SELECTOR,
        );

        return $signer->sign($email);
    }
}
