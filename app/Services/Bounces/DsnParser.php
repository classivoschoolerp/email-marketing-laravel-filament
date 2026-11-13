<?php

declare(strict_types=1);

namespace App\Services\Bounces;

use App\Services\Mailboxes\MimeMessageParser;
use App\Services\Mailboxes\MimePart;

/**
 * Bounce message parser (spec §2.9.4, WP-15).
 *
 * Primary path — RFC 3464 multipart/report (report-type=delivery-status):
 * reads Final-/Original-Recipient, Action, Status and Diagnostic-Code from
 * the message/delivery-status part, and the ORIGINAL message's Message-ID +
 * X-EMP-Campaign / X-EMP-Subscriber headers from the returned message part
 * (message/rfc822 or text/rfc822-headers).
 *
 * Attribution note (documented decision): MimeBuilder (WP-14, read-only for
 * WP-15) does not stamp X-EMP-* headers itself — the campaign engine passes
 * per-message headers through Message::$headers. This parser therefore
 * treats X-EMP-* as OPTIONAL hints and always extracts the original
 * Message-ID so the processor can join campaign_delivery_logs.message_id
 * (Schema-guarded) as the robust mapping.
 *
 * Fallback path — heuristics for non-standard bounces: recipient from
 * Final-Recipient/Original-Recipient/X-Failed-Recipients headers or the
 * first failing address in the body, SMTP status codes from the text.
 */
class DsnParser
{
    public function __construct(private readonly MimeMessageParser $mime = new MimeMessageParser) {}

    public function parse(string $raw): ParsedBounce
    {
        $message = $this->mime->parse($raw);
        $excerpt = $this->excerpt($raw);
        $subject = $message->header('subject');

        $deliveryStatus = $message->findPart('message/delivery-status');
        $original = $this->originalPart($message);

        [$campaignRef, $subscriberRef, $originalMessageId] = $this->originalReferences($original, $raw);

        if ($deliveryStatus !== null) {
            $fields = $this->parseDeliveryStatusFields($deliveryStatus->body);

            return new ParsedBounce(
                isDsn: true,
                recipient: $this->cleanAddress($fields['final-recipient'] ?? $fields['original-recipient'] ?? null),
                action: isset($fields['action']) ? strtolower($fields['action']) : null,
                status: $fields['status'] ?? null,
                diagnosticCode: $fields['diagnostic-code'] ?? null,
                originalMessageId: $originalMessageId,
                campaignRef: $campaignRef,
                subscriberRef: $subscriberRef,
                subject: $subject,
                excerpt: $excerpt,
            );
        }

        // ---- heuristic fallback for non-standard bounces ---------------------
        $body = $this->flattenText($message);

        $recipient = $this->cleanAddress($message->header('x-failed-recipients'))
            ?? $this->firstAddressFromBody($body);

        $status = null;

        if (preg_match('/\b([45]\.\d{1,3}\.\d{1,3})\b/', $body, $m) === 1) {
            $status = $m[1];
        } elseif (preg_match('/\b(4\d{2}|5\d{2})[ -]/', $body, $m) === 1) {
            $status = $m[1][0].'.0.0';
        }

        return new ParsedBounce(
            isDsn: false,
            recipient: $recipient,
            status: $status,
            originalMessageId: $originalMessageId,
            campaignRef: $campaignRef,
            subscriberRef: $subscriberRef,
            subject: $subject,
            excerpt: $excerpt,
        );
    }

    /**
     * Per-recipient fields from a message/delivery-status body. The body is
     * header-formatted groups separated by blank lines: per-message fields
     * first, then one group per recipient — we merge, letting the recipient
     * group win.
     *
     * @return array<string, string>
     */
    private function parseDeliveryStatusFields(string $body): array
    {
        $fields = [];
        $current = null;

        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $body)) as $line) {
            if (($line !== '') && ($line[0] === ' ' || $line[0] === "\t") && $current !== null) {
                $fields[$current] .= ' '.trim($line);

                continue;
            }

            $colon = strpos($line, ':');

            if ($colon === false) {
                $current = null;

                continue;
            }

            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));

            if ($name === '') {
                continue;
            }

            $current = $name;
            $fields[$name] = $value; // later (per-recipient) groups override
        }

        return $fields;
    }

    /** The returned-original part, whichever form the reporting MTA chose. */
    private function originalPart(MimePart $message): ?MimePart
    {
        foreach (['message/rfc822', 'text/rfc822-headers'] as $type) {
            $container = $message->findPart($type);

            if ($container !== null && $container->parts !== []) {
                return $container->parts[0];
            }
        }

        return null;
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: string|null}
     *                                                               [campaignRef, subscriberRef, originalMessageId]
     */
    private function originalReferences(?MimePart $original, string $raw): array
    {
        $campaignRef = $original?->header('x-emp-campaign');
        $subscriberRef = $original?->header('x-emp-subscriber');
        $originalMessageId = $this->cleanMessageId($original?->header('message-id'));

        // Some MTAs quote the original headers inline instead of attaching a
        // proper part — scan the raw source as a last resort.
        if ($campaignRef === null && preg_match('/^\s*X-EMP-Campaign:\s*(\S+)/im', $raw, $m) === 1) {
            $campaignRef = $m[1];
        }

        if ($subscriberRef === null && preg_match('/^\s*X-EMP-Subscriber:\s*(\S+)/im', $raw, $m) === 1) {
            $subscriberRef = $m[1];
        }

        return [$campaignRef, $subscriberRef, $originalMessageId];
    }

    /** Strip the "rfc822;" / "utf-8;" address-type prefix and angle brackets. */
    private function cleanAddress(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) preg_replace('/^[a-z0-9-]+\s*;\s*/i', '', trim($value)), " \t<>");

        return $value === '' || ! str_contains($value, '@') ? null : strtolower($value);
    }

    private function cleanMessageId(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value, " \t<>");

        return $value === '' ? null : $value;
    }

    /** All text/* content of the message, depth-first. */
    private function flattenText(MimePart $part): string
    {
        $chunks = [];

        if (str_starts_with($part->contentType(), 'text/') || $part->parts === []) {
            $chunks[] = $part->body;
        }

        foreach ($part->parts as $child) {
            $chunks[] = $this->flattenText($child);
        }

        return implode("\n", $chunks);
    }

    private function firstAddressFromBody(string $body): ?string
    {
        if (preg_match('/<([^\s@<>]+@[^\s@<>]+)>/', $body, $m) === 1) {
            return strtolower($m[1]);
        }

        if (preg_match('/\b([^\s@<>:;,"]+@[^\s@<>:;,"]+\.[a-z]{2,})\b/i', $body, $m) === 1) {
            return strtolower($m[1]);
        }

        return null;
    }

    private function excerpt(string $raw): string
    {
        $length = (int) config('emp-infra.bounces.excerpt_length', 1000);

        return mb_substr($raw, 0, max(100, $length));
    }
}
