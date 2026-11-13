<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a credit pack tops up (§3.26.1 F-3.26.1-013, D-73).
 */
enum CreditType: string
{
    case Sending = 'sending';
    case Verification = 'verification';

    public function label(): string
    {
        return __('billing.plans.credit_type.'.$this->value);
    }

    /** The customer_limits key the pack increments. */
    public function limitKey(): string
    {
        return match ($this) {
            self::Sending => (string) config('emp-billing.credits.sending_limit_key', 'sending_credits'),
            self::Verification => (string) config('emp-billing.credits.verification_limit_key', 'verification_credits'),
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
