<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of one inbox-placement run (spec §2.9.9 F-2.9.9-004, WP-46).
 *
 * draft → sending (seed messages queued) → polling (mailboxes being read)
 * → completed. `failed` is only for a run that could not be sent at all;
 * seeds that never arrive are recorded as `missing` results on a COMPLETED
 * run, because that is a finding, not a failure of the tool.
 */
enum InboxPlacementStatus: string
{
    case Draft = 'draft';
    case Sending = 'sending';
    case Polling = 'polling';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('deliverability.placement.statuses.draft'),
            self::Sending => __('deliverability.placement.statuses.sending'),
            self::Polling => __('deliverability.placement.statuses.polling'),
            self::Completed => __('deliverability.placement.statuses.completed'),
            self::Failed => __('deliverability.placement.statuses.failed'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::Failed => 'danger',
            self::Polling, self::Sending => 'warning',
            self::Draft => 'gray',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Sending || $this === self::Polling;
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
