<?php

declare(strict_types=1);

namespace App\Services\Bounces;

/**
 * Result of DsnParser::parse() — everything the processor needs to classify
 * and attribute a bounce (spec §2.9.4). campaignRef/subscriberRef carry the
 * raw values of the X-EMP-Campaign / X-EMP-Subscriber headers when the
 * original message (as echoed inside the DSN) had them stamped; the
 * processor resolves them uid-first, then numeric id.
 */
final class ParsedBounce
{
    public function __construct(
        public readonly bool $isDsn,
        public readonly ?string $recipient = null,
        public readonly ?string $action = null,
        public readonly ?string $status = null,
        public readonly ?string $diagnosticCode = null,
        public readonly ?string $originalMessageId = null,
        public readonly ?string $campaignRef = null,
        public readonly ?string $subscriberRef = null,
        public readonly ?string $subject = null,
        public readonly string $excerpt = '',
    ) {}

    /** Text the classification rules run against. */
    public function classificationHaystack(): string
    {
        return implode("\n", array_filter([
            $this->subject !== null ? 'subject: '.$this->subject : null,
            $this->action !== null ? 'action: '.$this->action : null,
            $this->status !== null ? 'status: '.$this->status : null,
            $this->diagnosticCode !== null ? 'diagnostic-code: '.$this->diagnosticCode : null,
            $this->excerpt,
        ], static fn (?string $v): bool => $v !== null && $v !== ''));
    }
}
