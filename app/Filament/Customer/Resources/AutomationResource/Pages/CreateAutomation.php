<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\AutomationResource\Pages;

use App\Enums\AutomationNodeType;
use App\Filament\Customer\Resources\AutomationResource;
use App\Models\Automation;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

/**
 * Creating an automation picks the trigger type; the draft canvas is seeded
 * with that single trigger node and the user lands in the builder
 * (spec §2.4.2).
 */
class CreateAutomation extends CreateRecord
{
    protected static string $resource = AutomationResource::class;

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $trigger = AutomationNodeType::tryFrom((string) ($data['trigger_type'] ?? ''))
            ?? AutomationNodeType::TriggerManual;

        $config = [];

        if ($trigger === AutomationNodeType::TriggerWebhook) {
            $config['token'] = Str::random(32);
        }

        $data['canvas'] = [
            'nodes' => [[
                'uid' => 'n'.Str::lower(Str::random(7)),
                'type' => $trigger->value,
                'label' => null,
                'config' => $config,
                'edges' => [],
            ]],
        ];

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        /** @var Automation $record */
        $record = $this->record;

        return AutomationResource::getUrl('builder', ['record' => $record]);
    }
}
