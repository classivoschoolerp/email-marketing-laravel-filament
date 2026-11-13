<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\CampaignResource\Pages;

use App\Enums\CampaignType;
use App\Enums\TemplateBuilder;
use App\Filament\Customer\Resources\CampaignResource;
use App\Filament\Customer\Resources\CampaignResource\Concerns\HandlesCampaignContent;
use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Campaigns\CampaignQuota;
use Filament\Facades\Filament;
use Filament\Forms\Components\Wizard\Step;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;

/**
 * "New campaign" 4-step wizard (spec §2.3.2–§2.3.5). The record is created
 * as a draft at the end of the wizard; choosing "Schedule / send now" on the
 * confirmation step runs the pre-flight-gated scheduler right after.
 * Creation is quota-gated (max_campaigns) and type-gated (§3.5 matrix).
 */
class CreateCampaign extends CreateRecord
{
    use HandlesCampaignContent;
    use HasWizard;

    protected static string $resource = CampaignResource::class;

    /** @return array<int, Step> */
    protected function getSteps(): array
    {
        return CampaignResource::wizardSteps();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Defence in depth: pin the tenant from the authenticated customer.
        $user = Filament::auth()->user();

        if ($user instanceof Customer) {
            $data['customer_id'] = $user->id;

            $quota = app(CampaignQuota::class);
            $quota->assertCanCreate($user);

            if (isset($data['type'])) {
                $quota->assertTypeAllowed($user, CampaignType::from((string) $data['type']));
            }
        }

        $data = $this->stashTransientState($data);

        return $this->applyContentSource($data);
    }

    protected function afterCreate(): void
    {
        /** @var Campaign $record */
        $record = $this->getRecord();

        $this->importPendingZip($record);
        $this->syncAttachments($record);
        $this->ensureShareSecret($record);
        // WP-35: extra lists, tags and the §2.3.6 variant invariants.
        $this->syncCampaignRelations($record);
        $this->recordCampaignRevision($record);
        $this->warnOnMissingUnsubscribe($record);
        $this->applyConfirmAction($record);
    }

    protected function getRedirectUrl(): string
    {
        /** @var Campaign $record */
        $record = $this->getRecord();

        // GrapesJS campaigns land straight in the content builder.
        if ($record->builder === TemplateBuilder::GrapesJs && $record->isEditable()) {
            return CampaignResource::getUrl('content', ['record' => $record]);
        }

        return CampaignResource::getUrl('index');
    }
}
