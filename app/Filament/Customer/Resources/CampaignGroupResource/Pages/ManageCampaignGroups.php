<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\CampaignGroupResource\Pages;

use App\Filament\Customer\Resources\CampaignGroupResource;
use App\Models\Customer;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRecords;

class ManageCampaignGroups extends ManageRecords
{
    protected static string $resource = CampaignGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label(__('campaigns.groups.create'))
                ->mutateFormDataUsing(function (array $data): array {
                    // Defence in depth: pin the tenant (docs/ARCHITECTURE.md rule 3).
                    $user = Filament::auth()->user();

                    if ($user instanceof Customer) {
                        $data['customer_id'] = $user->id;
                    }

                    return $data;
                }),
        ];
    }
}
