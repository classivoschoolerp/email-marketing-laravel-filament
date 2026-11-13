<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\SuppressionListResource\Pages;

use App\Filament\Customer\Resources\SuppressionListResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

/** §2.9.11 create named suppression list (F-2.9.11-001). */
class CreateSuppressionList extends CreateRecord
{
    protected static string $resource = SuppressionListResource::class;

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Livewire update requests bypass SetCurrentCustomer — pin the
        // tenant explicitly (same defense as SubscriberResource).
        $data['customer_id'] = Filament::auth()->id();

        return $data;
    }
}
