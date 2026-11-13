<?php

declare(strict_types=1);

namespace App\Services\Monitors;

use App\Services\Mailboxes\MimeMessageParser;
use App\Services\Mailboxes\MimePart;

/**
 * Turns a raw RFC 2822 mailbox message into the subject / body / from triple
 * monitor conditions work on (spec §2.9.6 F-2.9.6-002), reusing WP-15's
 * MimeMessageParser so multipart and encoded bodies behave the same way they
 * do for bounce and FBL processing.
 *
 * Header values are RFC 2047 decoded when the mbstring helper is available,
 * so a monitor matching "Réclamation" also matches "=?UTF-8?B?...?=".
 */
class MonitorMessageReader
{
    public function __construct(private readonly MimeMessageParser $parser) {}

    public function read(string $raw): MonitorMessage
    {
        $part = $this->parser->parse($raw);

        $subject = $this->decodeHeader($part->header('subject') ?? '');
        $from = $this->decodeHeader($part->header('from') ?? '');

        return new MonitorMessage(
            subject: $subject,
            body: $this->textBody($part),
            from: $from,
            fromEmail: $this->extractAddress($from),
        );
    }

    /** First text/plain part, falling back to text/html then the root body. */
    private function textBody(MimePart $part): string
    {
        foreach (['text/plain', 'text/html'] as $type) {
            $found = $part->findPart($type);

            if ($found !== null && trim($found->body) !== '') {
                return $found->body;
            }
        }

        return $part->body;
    }

    private function decodeHeader(string $value): string
    {
        if ($value === '' || ! str_contains($value, '=?')) {
            return $value;
        }

        if (function_exists('mb_decode_mimeheader')) {
            return mb_decode_mimeheader($value);
        }

        return $value;
    }

    /** "Name <a@b.c>" / "a@b.c" → "a@b.c" (lower-cased), or null. */
    public function extractAddress(string $header): ?string
    {
        if (preg_match('/<([^>]+)>/', $header, $matches) === 1) {
            $candidate = trim($matches[1]);
        } else {
            $candidate = trim($header);
        }

        $candidate = mb_strtolower($candidate);

        return filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false ? $candidate : null;
    }
}
