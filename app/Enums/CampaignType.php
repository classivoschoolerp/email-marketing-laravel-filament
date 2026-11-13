<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Campaign type (spec §2.3.2 F-2.3.2-002). Group-level availability is a
 * QuotaService permission: campaigns.regular / campaigns.autoresponder /
 * campaigns.recurring (§3.5 matrix, CustomerGroupMatrix).
 */
enum CampaignType: string
{
    case Regular = 'regular';
    case Autoresponder = 'autoresponder';
    case Recurring = 'recurring';

    public function label(): string
    {
        return __('campaigns.type.'.$this->value);
    }

    /** QuotaService permission key gating this type (§3.5). */
    public function permissionKey(): string
    {
        return 'campaigns.'.$this->value;
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

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
