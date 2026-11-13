<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * §2.13 F-2.13-008 — the five lifecycle stages the spec names, in order:
 * subscriber → lead → MQL → SQL → customer.
 *
 * `rank()` makes the order explicit so a transition can be reported as a
 * promotion or a demotion; both are legal (a deal falling through demotes an
 * SQL back to a lead) and both are recorded in `crm_lifecycle_events`.
 */
enum CrmLifecycleStage: string
{
    case Subscriber = 'subscriber';
    case Lead = 'lead';
    case Mql = 'mql';
    case Sql = 'sql';
    case Customer = 'customer';

    public function label(): string
    {
        return __('crm.lifecycle.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Subscriber => 'gray',
            self::Lead => 'info',
            self::Mql => 'warning',
            self::Sql => 'primary',
            self::Customer => 'success',
        };
    }

    /** Position in the funnel, 0-based. */
    public function rank(): int
    {
        return match ($this) {
            self::Subscriber => 0,
            self::Lead => 1,
            self::Mql => 2,
            self::Sql => 3,
            self::Customer => 4,
        };
    }

    public function isPromotionFrom(?self $previous): bool
    {
        return $previous === null || $this->rank() > $previous->rank();
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
