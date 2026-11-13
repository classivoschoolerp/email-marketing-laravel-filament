<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\EmailListResource\Pages;

use App\Filament\Customer\Resources\EmailListResource;
use App\Models\Customer;
use Filament\Facades\Filament;
use Filament\Forms\Components\Wizard\Step;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;

/**
 * "Create list" wizard (F-2.2.1-006): one step per §2.2.2 tab. Creation is
 * quota-gated by EmailListPolicy::create (max_lists, spec §0.3).
 */
class CreateEmailList extends CreateRecord
{
    use HasWizard;

    protected static string $resource = EmailListResource::class;

    /** @return array<int, Step> */
    protected function getSteps(): array
    {
        return [
            Step::make(__('lists.tabs.general'))
                ->schema(EmailListResource::generalSchema()),
            Step::make(__('lists.tabs.defaults'))
                ->schema(EmailListResource::defaultsSchema()),
            Step::make(__('lists.tabs.notifications'))
                ->schema(EmailListResource::notificationsSchema()),
            Step::make(__('lists.tabs.company'))
                ->schema(EmailListResource::companySchema()),
            Step::make(__('lists.tabs.subscriber_actions'))
                ->schema(EmailListResource::subscriberActionsSchema()),
            Step::make(__('lists.tabs.advanced'))
                ->schema(EmailListResource::advancedSchema()),
        ];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed> */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Defence in depth: Livewire update requests may run without the
        // request tenant, so pin customer_id from the authenticated customer.
        $user = Filament::auth()->user();

        if ($user instanceof Customer) {
            $data['customer_id'] = $user->id;
        }

        return $data;
    }
}
