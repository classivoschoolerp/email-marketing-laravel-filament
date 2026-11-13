<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\SpintaxSnippetResource\Pages;

use App\Filament\Customer\Resources\SpintaxSnippetResource;
use App\Models\Customer;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ManageRecords;

class ManageSpintaxSnippets extends ManageRecords
{
    protected static string $resource = SpintaxSnippetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label(__('campaigns.snippets.create'))
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
