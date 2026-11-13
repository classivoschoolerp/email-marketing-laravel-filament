<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle shared by warmup plans and per-server warmup progress rows
 * (spec §2.9.3 F-2.9.3-006).
 *
 * Enforcement semantics (F-2.9.3-009): Active AND Paused both cap sending —
 * pausing freezes the day counter, it does NOT lift the ceiling, because
 * over-sending during a pause is exactly what ruins a warmup. Draft and
 * Completed do not cap; to stop enforcing, complete the plan or clear
 * `delivery_servers.warmup_plan_id`.
 */
enum WarmupStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('warmup.status.draft'),
            self::Active => __('warmup.status.active'),
            self::Paused => __('warmup.status.paused'),
            self::Completed => __('warmup.status.completed'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Active => 'success',
            self::Paused => 'warning',
            self::Completed => 'info',
        };
    }

    /** Does this state impose the per-day ceiling? */
    public function enforces(): bool
    {
        return $this === self::Active || $this === self::Paused;
    }

    /** Does this state advance to the next day on the daily cron? */
    public function advances(): bool
    {
        return $this === self::Active;
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
