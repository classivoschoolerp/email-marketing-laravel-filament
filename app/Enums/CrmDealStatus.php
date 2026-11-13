<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * §2.13 F-2.13-003 deal outcome. Derived from the STAGE a deal sits in
 * (`crm_pipeline_stages.is_won` / `is_lost`) rather than set by hand, so the
 * board and the status can never disagree.
 */
enum CrmDealStatus: string
{
    case Open = 'open';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return __('crm.deal_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'info',
            self::Won => 'success',
            self::Lost => 'danger',
        };
    }

    public function isClosed(): bool
    {
        return $this !== self::Open;
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
