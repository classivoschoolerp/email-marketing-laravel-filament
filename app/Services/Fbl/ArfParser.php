<?php

declare(strict_types=1);

namespace App\Services\Fbl;

use App\Services\Mailboxes\MimeMessageParser;
use App\Services\Mailboxes\MimePart;

/**
 * ARF parser (RFC 5965 Abuse Reporting Format, spec §2.9.5 F-2.9.5-002).
 * Reads the message/feedback-report part (Feedback-Type, Original-Rcpt-To,
 * Original-Mail-From) plus the embedded original message's Message-ID and
 * X-EMP-* attribution headers. Non-ARF mail in the FBL mailbox is flagged
 * isArf=false and skipped by the processor (logged at debug only).
 */
class ArfParser
{
    public function __construct(private readonly MimeMessageParser $mime = new MimeMessageParser) {}

    public function parse(string $raw): ParsedComplaint
    {
        $message = $this->mime->parse($raw);
        $excerpt = mb_substr($raw, 0, max(100, (int) config('emp-infra.bounces.excerpt_length', 1000)));

        $report = $message->findPart('message/feedback-report');

        $original = null;

        foreach (['message/rfc822', 'text/rfc822-headers'] as $type) {
            $container = $message->findPart($type);

            if ($container !== null && $container->parts !== []) {
                $original = $container->parts[0];
                break;
            }
        }

        $isArf = $report !== null
            || strtolower((string) $message->contentTypeParameter('report-type')) === 'feedback-report';

        $fields = $report !== null ? $this->parseReportFields($report->body) : [];

        $recipient = $this->cleanAddress($fields['original-rcpt-to'] ?? null)
            ?? $this->cleanAddress($original?->header('to'));

        return new ParsedComplaint(
            isArf: $isArf,
            feedbackType: isset($fields['feedback-type']) ? strtolower($fields['feedback-type']) : null,
            recipient: $recipient,
            originalMessageId: $this->cleanMessageId(
                $original?->header('message-id') ?? ($fields['original-message-id'] ?? null),
            ),
            campaignRef: $original?->header('x-emp-campaign'),
            subscriberRef: $original?->header('x-emp-subscriber'),
            excerpt: $excerpt,
        );
    }

    /** @return array<string, string> lower-cased feedback-report fields */
    private function parseReportFields(string $body): array
    {
        $fields = [];

        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $body)) as $line) {
            $colon = strpos($line, ':');

            if ($colon === false) {
                continue;
            }

            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));

            if ($name !== '') {
                $fields[$name] ??= $value;
            }
        }

        return $fields;
    }

    private function cleanAddress(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) preg_replace('/^[a-z0-9-]+\s*;\s*/i', '', trim($value)));

        if (preg_match('/<([^<>\s]+@[^<>\s]+)>/', $value, $m) === 1) {
            return strtolower($m[1]);
        }

        $value = trim($value, " \t<>\"");

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
}
