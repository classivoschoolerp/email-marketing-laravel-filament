<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\CampaignResource\Pages;

use App\Filament\Customer\Resources\CampaignResource;
use App\Models\Campaign;
use App\Support\Templates\BuilderBlocksConfig;
use App\Support\Templates\BuilderStudioConfig;
use Filament\Actions;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * GrapesJS content builder for campaigns (spec §2.3.3 F-2.3.3-002, D-11).
 * Reuses the WP-10 template-builder JS unchanged — the page provides the
 * same container element ids and a config payload pointing at the campaign
 * save/asset endpoints (routes/modules/campaigns.php).
 */
class BuildCampaignContent extends Page
{
    use InteractsWithRecord;

    protected static string $resource = CampaignResource::class;

    protected static string $view = 'filament.customer.campaigns.builder';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(auth('customer')->user()?->can('update', $this->record) ?? false, 403);
        abort_unless($this->getCampaign()->isEditable(), 403);
    }

    public function getCampaign(): Campaign
    {
        /** @var Campaign $record */
        $record = $this->getRecord();

        return $record;
    }

    public function getTitle(): string|Htmlable
    {
        return __('campaigns.builder_page.title', ['name' => $this->getCampaign()->name]);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $campaign = $this->getCampaign();

        return [
            'builderConfig' => [
                'saveUrl' => route('campaigns.builder.save', ['uid' => $campaign->uid]),
                'assetUrl' => route('campaigns.builder.assets', ['uid' => $campaign->uid]),
                'csrfToken' => csrf_token(),
                'autosaveMs' => (int) config('emp-campaigns.autosave_debounce_ms', 2000),
                'contentHtml' => $campaign->content_html,
                'contentJson' => $campaign->content_json,
                'googleFonts' => [],
                'mergeTags' => CampaignResource::mergeTagsFor($campaign->email_list_id),
                // WP-26 saved blocks + audio widget (F-2.3.3-025/-026,
                // F-2.5.4-005) — identical config in BuildEmailTemplate, and
                // deliberately the SAME blocks, shared across both builders.
                'blocks' => BuilderBlocksConfig::endpoints(),
                // WP-42 brand kit / stock library / image editor
                // (F-2.5.4-004, -010, -009) — identical config in
                // BuildEmailTemplate, and deliberately the SAME tenant kit and
                // media library shared across both builders.
                ...BuilderStudioConfig::payload(auth('customer')->user()),
                'i18n' => [
                    'saved' => __('campaigns.builder_page.saved'),
                    'saving' => __('campaigns.builder_page.saving'),
                    'save_failed' => __('campaigns.builder_page.save_failed'),
                    'merge_tags' => __('campaigns.builder_page.merge_tags'),
                    'fonts_placeholder' => __('campaigns.builder_page.fonts_placeholder'),
                    'blocks' => BuilderBlocksConfig::strings(),
                    // F-2.5.4-006 hide-on-mobile toggle (WP-27B).
                    'mobile' => [
                        'hide_on_mobile' => __('campaigns.builder_page.hide_on_mobile'),
                        'hide_on_mobile_hint' => __('campaigns.builder_page.hide_on_mobile_hint'),
                        'nothing_selected' => __('campaigns.builder_page.nothing_selected'),
                    ],
                    // F-2.5.4-013 dark-mode overrides + canvas preview (WP-42).
                    'dark' => __('campaigns.builder_page.dark'),
                    // F-2.5.4-004/-009/-010 shared builder strings (WP-42).
                    'builder' => BuilderStudioConfig::strings(),
                ],
            ],
        ];
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
