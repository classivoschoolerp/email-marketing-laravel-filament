<?php

declare(strict_types=1);

namespace App\Services\Privacy;

/**
 * One country's consent obligations (spec §4.5 F-4.5-003, WP-42).
 *
 * Immutable value object built by {@see ConsentRules} from
 * config/emp-consent.php. Only the three obligations that change platform
 * behaviour are modelled — see that config file for why.
 */
final readonly class ConsentRule
{
    public function __construct(
        public string $key,
        public string $law,
        public bool $requiresExpressConsent,
        public ?int $impliedConsentDays,
        public bool $requiresSenderIdentification,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $key, array $config): self
    {
        $days = $config['implied_consent_days'] ?? null;

        return new self(
            key: $key,
            law: (string) ($config['law'] ?? $key),
            requiresExpressConsent: (bool) ($config['requires_express_consent'] ?? false),
            impliedConsentDays: is_numeric($days) && (int) $days > 0 ? (int) $days : null,
            requiresSenderIdentification: (bool) ($config['requires_sender_identification'] ?? false),
        );
    }

    /** True when a subscriber without a confirmation may never be mailed. */
    public function forbidsImpliedConsent(): bool
    {
        return $this->requiresExpressConsent && $this->impliedConsentDays === null;
    }
}
