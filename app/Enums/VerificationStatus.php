<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Email verification verdict (spec §2.9.12, F-2.9.12-006): every provider's
 * vocabulary is normalised to these four. Stored on the subscriber
 * (subscribers.verification_status) and on verification_results rows.
 */
enum VerificationStatus: string
{
    case Deliverable = 'deliverable';
    case Undeliverable = 'undeliverable';
    case Risky = 'risky';
    case Unknown = 'unknown';

    public function label(): string
    {
        return __('verification.status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Deliverable => 'success',
            self::Undeliverable => 'danger',
            self::Risky => 'warning',
            self::Unknown => 'gray',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
