<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * §2.15.3 F-2.15.3-001 — the account events a customer can be notified
 * about, each toggleable per channel (WP-39).
 *
 * The defaults below are what a brand-new account gets; the
 * notification_preferences table only ever stores DEVIATIONS from them, so
 * adding a case here switches it on for every existing account without a
 * backfill migration.
 */
enum NotificationEvent: string
{
    case CampaignFinished = 'campaign_finished';
    case QuotaNearingLimit = 'quota_nearing_limit';
    case BounceSpike = 'bounce_spike';
    case ImportComplete = 'import_complete';
    case ExportReady = 'export_ready';
    case NewSubscriber = 'new_subscriber';
    case PaymentDue = 'payment_due';

    public function label(): string
    {
        return __('notifications.events.'.$this->value.'.label');
    }

    public function description(): string
    {
        return __('notifications.events.'.$this->value.'.description');
    }

    /**
     * Default state per channel for an account that has never touched its
     * preferences. "New subscriber" is off by default on both channels — on
     * a real list it is a firehose and the per-list `subscribe_notify`
     * toggle is what opts a list into it.
     */
    public function defaultFor(NotificationChannel $channel): bool
    {
        if ($this === self::NewSubscriber) {
            return false;
        }

        // Everything else is on for both channels; export/import completion
        // is the pair most people want in-app rather than by mail, but the
        // spec asks for a per-channel toggle, not a per-channel default.
        return true;
    }

    /** Billing events vanish with the F-3.25.14-001 monetization switch. */
    public function isBilling(): bool
    {
        return $this === self::PaymentDue;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
