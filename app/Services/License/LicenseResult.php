<?php

declare(strict_types=1);

namespace App\Services\License;

use App\Settings\LicenseSettings;

/**
 * Outcome of a license validation attempt (spec §3.25.12, D-09).
 */
final readonly class LicenseResult
{
    /**
     * @param  list<string>  $allowedDomains
     */
    public function __construct(
        public string $status,
        public ?string $type = null,
        public ?string $expiresAt = null,
        public array $allowedDomains = [],
        public ?string $renewalUrl = null,
        public ?string $message = null,
    ) {}

    public function valid(): bool
    {
        return $this->status === LicenseSettings::STATUS_VALID;
    }

    public static function selfHosted(): self
    {
        return new self(
            status: LicenseSettings::STATUS_VALID,
            type: 'self-hosted',
            message: __('settings.license.messages.self_hosted'),
        );
    }
}
