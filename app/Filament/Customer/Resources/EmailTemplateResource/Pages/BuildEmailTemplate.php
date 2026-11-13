<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\EmailTemplateResource\Pages;

use App\Filament\Customer\Resources\EmailTemplateResource;
use App\Filament\Customer\Resources\EmailTemplateResource\Concerns\HasTemplateRevisions;
use App\Models\EmailTemplate;
use App\Services\Templates\MergeTags;
use App\Support\Templates\BuilderBlocksConfig;
use App\Support\Templates\BuilderStudioConfig;
use Filament\Actions;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * GrapesJS drag-and-drop builder page (spec §2.5.4, D-11). The editor itself
 * lives in resources/js/template-builder.js (vite input "template-builder");
 * this page provides the container, the config payload (save/asset endpoints,
 * merge tags, devices, fonts) and the revisions UI.
 */
class BuildEmailTemplate extends Page
{
    use HasTemplateRevisions;
    use InteractsWithRecord;

    protected static string $resource = EmailTemplateResource::class;

    protected static string $view = 'filament.customer.templates.builder';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(auth('customer')->user()?->can('update', $this->record) ?? false, 403);
    }

    public function getTemplate(): EmailTemplate
    {
        /** @var EmailTemplate $record */
        $record = $this->getRecord();

        return $record;
    }

    public function getTitle(): string|Htmlable
    {
        return __('templates.builder_page.title', ['name' => $this->getTemplate()->name]);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $template = $this->getTemplate();
        $mergeTags = app(MergeTags::class);

        return [
            'builderConfig' => [
                'saveUrl' => route('templates.builder.save', ['uid' => $template->uid]),
                'assetUrl' => route('templates.builder.assets', ['uid' => $template->uid]),
                'csrfToken' => csrf_token(),
                'autosaveMs' => (int) config('emp-templates.autosave_debounce_ms', 2000),
                'contentHtml' => $template->content_html,
                'contentJson' => $template->content_json,
                'googleFonts' => $template->google_fonts ?? [],
                'mergeTags' => $mergeTags->tags(),
                // WP-26 saved blocks + audio widget (F-2.3.3-025/-026,
                // F-2.5.4-005) — identical config in BuildCampaignContent.
                'blocks' => BuilderBlocksConfig::endpoints(),
                // WP-42 brand kit / stock library / image editor
                // (F-2.5.4-004, -010, -009) — identical config in
                // BuildCampaignContent, and deliberately the SAME tenant kit
                // and media library shared across both builders.
                ...BuilderStudioConfig::payload(auth('customer')->user()),
                'i18n' => [
                    'saved' => __('templates.builder_page.saved'),
                    'saving' => __('templates.builder_page.saving'),
                    'save_failed' => __('templates.builder_page.save_failed'),
                    'merge_tags' => __('templates.builder_page.merge_tags'),
                    'fonts_placeholder' => __('templates.builder_page.fonts_placeholder'),
                    'blocks' => BuilderBlocksConfig::strings(),
                    // F-2.5.4-006 hide-on-mobile toggle (WP-27B).
                    'mobile' => [
                        'hide_on_mobile' => __('templates.builder_page.hide_on_mobile'),
                        'hide_on_mobile_hint' => __('templates.builder_page.hide_on_mobile_hint'),
                        'nothing_selected' => __('templates.builder_page.nothing_selected'),
                    ],
                    // F-2.5.4-013 dark-mode overrides + canvas preview (WP-42).
                    'dark' => __('templates.builder_page.dark'),
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
                ->label(__('templates.actions.back_to_index'))
                ->icon('heroicon-m-arrow-left')
                ->url(EmailTemplateResource::getUrl('index'))
                ->color('gray'),
            Actions\Action::make('settings')
                ->label(__('templates.actions.template_settings'))
                ->icon('heroicon-m-adjustments-horizontal')
                ->url(fn (): string => EmailTemplateResource::getUrl('edit', ['record' => $this->record])
                    .'?settings=1'),
            $this->revisionsAction(),
        ];
    }
}
