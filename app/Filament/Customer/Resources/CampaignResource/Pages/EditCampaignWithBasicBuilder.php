<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\CampaignResource\Pages;

use App\Filament\Customer\Concerns\EditsBasicBlocks;
use App\Filament\Customer\Resources\CampaignResource;
use App\Models\Campaign;
use App\Support\Templates\MobileVisibility;
use Filament\Actions;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Basic / simple built-in builder for campaign content (spec §2.5.3
 * F-2.5.3-001 · §2.3.3). Same block model, same renderer and the same Blade
 * view as the template page — a campaign is just a different owner for the
 * document, exactly as D-59 widened the importers.
 *
 * Editing stops once sending starts (§2.3.9 edit-until-sending).
 */
class EditCampaignWithBasicBuilder extends Page
{
    use EditsBasicBlocks;
    use InteractsWithRecord;

    protected static string $resource = CampaignResource::class;

    protected static string $view = 'filament.customer.templates.basic-builder';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(auth('customer')->user()?->can('update', $this->record) ?? false, 403);
        abort_unless($this->getCampaign()->isEditable(), 403);

        $this->loadBlocks($this->getCampaign()->content_json);
    }

    public function getCampaign(): Campaign
    {
        /** @var Campaign $record */
        $record = $this->getRecord();

        return $record;
    }

    public function getTitle(): string|Htmlable
    {
        return __('campaigns.basic_builder.title', ['name' => $this->getCampaign()->name]);
    }

    protected function documentTitle(): string
    {
        return (string) $this->getCampaign()->subject;
    }

    protected function persistBlocks(string $html, string $json): void
    {
        $this->getCampaign()->forceFill([
            // F-2.5.4-006 parity with the GrapesJS campaign autosave endpoint.
            'content_html' => MobileVisibility::apply($html),
            'content_json' => $json,
        ])->save();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back')
                ->label(__('campaigns.actions.back_to_index'))
                ->icon('heroicon-m-arrow-left')
                ->url(CampaignResource::getUrl('index'))
                ->color('gray'),
            Actions\Action::make('settings')
                ->label(__('campaigns.actions.campaign_settings'))
                ->icon('heroicon-m-adjustments-horizontal')
                ->url(fn (): string => CampaignResource::getUrl('edit', ['record' => $this->record])),
        ];
    }
}
