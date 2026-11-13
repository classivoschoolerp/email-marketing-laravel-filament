<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Campaign lifecycle status (spec §2.3.1). Transition legality is owned by
 * App\Services\Campaigns\CampaignStatusMachine — nothing outside the machine
 * may flip statuses.
 */
enum CampaignStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case PendingApproval = 'pending-approval';
    case Sending = 'sending';
    case Sent = 'sent';
    case Paused = 'paused';
    case Blocked = 'blocked';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('campaigns.status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Scheduled => 'info',
            self::PendingApproval => 'warning',
            self::Sending => 'primary',
            self::Sent => 'success',
            self::Paused => 'warning',
            self::Blocked => 'danger',
            self::Cancelled => 'gray',
        };
    }

    /** May the campaign wizard still edit the campaign? (§2.3.9 — edit until sending.) */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Scheduled, self::PendingApproval, self::Paused], true);
    }

    /** Terminal statuses — no transitions out except copy. */
    public function isTerminal(): bool
    {
        return $this === self::Sent;
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
