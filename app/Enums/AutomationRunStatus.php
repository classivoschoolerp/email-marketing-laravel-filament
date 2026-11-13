<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Journey run states (spec §2.4.2/§2.4.3): `active` runs advance; `completed`
 * = reached the natural end of the flow or met a goal; `exited` = left early
 * (exit node, cross-automation unsubscribe, node removed on republish);
 * `failed` = a node raised an unrecoverable error.
 */
enum AutomationRunStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Exited = 'exited';
    case Failed = 'failed';

    public function label(): string
    {
        return __('automations.run_status.'.$this->value);
    }

    public function isTerminal(): bool
    {
        return $this !== self::Active;
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
