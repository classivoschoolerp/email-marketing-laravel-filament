<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Sending / tracking domain verification badge (spec §2.9.7 F-2.9.7-006):
 * pending = never checked, verified = required records pass, failed = last
 * check found at least one required record missing.
 */
enum DomainStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('infra.domain_status.pending'),
            self::Verified => __('infra.domain_status.verified'),
            self::Failed => __('infra.domain_status.failed'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Verified => 'success',
            self::Failed => 'danger',
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
