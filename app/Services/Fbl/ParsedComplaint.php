<?php

declare(strict_types=1);

namespace App\Services\Fbl;

/**
 * Result of ArfParser::parse() (spec §2.9.5): the complaint's Feedback-Type,
 * the complaining recipient and the original-message references used for
 * campaign/subscriber attribution (same semantics as ParsedBounce).
 */
final class ParsedComplaint
{
    public function __construct(
        public readonly bool $isArf,
        public readonly ?string $feedbackType = null,
        public readonly ?string $recipient = null,
        public readonly ?string $originalMessageId = null,
        public readonly ?string $campaignRef = null,
        public readonly ?string $subscriberRef = null,
        public readonly string $excerpt = '',
    ) {}
}
