<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Autoresponder trigger events (spec §2.3.2 F-2.3.2-014). Evaluation happens
 * in the WP-13 sending engine; the wizard stores the configuration.
 */
enum AutoresponderTrigger: string
{
    case AfterSubscribe = 'after-subscribe';
    case AfterUnsubscribe = 'after-unsubscribe';
    case AfterCampaignOpen = 'after-campaign-open';
    case AfterCampaignSent = 'after-campaign-sent';
    case OnDateField = 'on-date-field';
    case AfterProfileUpdate = 'after-profile-update';

    public function label(): string
    {
        return __('campaigns.autoresponder.trigger.'.$this->value);
    }

    /** Triggers that reference another campaign. */
    public function needsCampaign(): bool
    {
        return in_array($this, [self::AfterCampaignOpen, self::AfterCampaignSent], true);
    }

    /** Triggers that reference a date custom field (birthday/anniversary). */
    public function needsDateField(): bool
    {
        return $this === self::OnDateField;
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
