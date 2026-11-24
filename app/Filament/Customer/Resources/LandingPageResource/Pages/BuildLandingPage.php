<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\LandingPageResource\Pages;

use App\Filament\Customer\Resources\LandingPageResource;
use App\Filament\Customer\Resources\LandingPageResource\Concerns\HasLandingRevisions;
use App\Models\LandingPage;
use App\Models\LandingPageVariant;
use App\Services\Landing\LandingBlocks;
use App\Services\Landing\LandingStyles;
use App\Support\Templates\BuilderBlocksConfig;
use Filament\Actions;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The drag-and-drop landing-page builder (spec §2.6 F-2.6-002, F-2.6-013).
 *
 * THE SAME EDITOR AS §2.5.4 — `resources/js/template-builder.js` runs in
 * "landing" mode, which is why no new JavaScript package was added for this
 * feature. What the mode changes is exactly what has to change: CSS is not
 * inlined (a browser is not Outlook), the block palette comes from
 * {@see LandingBlocks} instead of the newsletter preset's email blocks, and
 * the canvas is styled with the landing stylesheet so the editor shows what
 * the visitor will see. Autosave, undo/redo, image upload, the saved-blocks
 * library and the desktop/tablet/mobile preview (F-2.6-013) are the existing
 * machinery, untouched.
 *
 * `?variant={uid}` edits one A/B arm instead of the page (F-2.6-004); the
 * autosave payload carries the same uid, so the two documents can never be
 * written over each other.
 */
class BuildLandingPage extends Page
{
    use HasLandingRevisions;
    use InteractsWithRecord;

    protected static string $resource = LandingPageResource::class;

    protected static string $view = 'filament.customer.landing.builder';

    public ?string $variantUid = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(auth('customer')->user()?->can('update', $this->record) ?? false, 403);

        $this->variantUid = request()->query('variant');
    }

    public function getPage(): LandingPage
    {
        /** @var LandingPage $record */
        $record = $this->getRecord();

        return $record;
    }

    public function getVariant(): ?LandingPageVariant
    {
        if ($this->variantUid === null || trim($this->variantUid) === '') {
            return null;
        }

        return LandingPageVariant::query()
            ->where('landing_page_id', $this->getPage()->id)
            ->where('uid', $this->variantUid)
            ->first();
    }

    public function getTitle(): string|Htmlable
    {
        return __('landing.builder_page.title', ['name' => $this->getPage()->name]);
    }

    public function getSubheading(): string|Htmlable|null
    {
        $variant = $this->getVariant();

        return $variant === null
            ? __('landing.builder_page.editing_page')
            : __('landing.builder_page.editing_variant', ['name' => $variant->name]);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $page = $this->getPage();
        $variant = $this->getVariant();
        $document = $variant ?? $page;

        return [
            'builderConfig' => [
                // The landing branch of the shared builder.
                'mode' => 'landing',
                'saveUrl' => route('landing.builder.save', ['uid' => $page->uid]),
                'assetUrl' => route('landing.builder.assets', ['uid' => $page->uid]),
                'variantUid' => $variant?->uid,
                'csrfToken' => csrf_token(),
                'autosaveMs' => (int) config('emp-templates.autosave_debounce_ms', 2000),
                'contentHtml' => $document->content_html,
                'contentJson' => $document->content_json,
                'googleFonts' => [],
                // Merge tags are an EMAIL concept; a landing page has no
                // recipient, so the tag menu is deliberately empty here.
                'mergeTags' => [],
                'landingBlocks' => LandingBlocks::all(),
                'canvasCss' => LandingStyles::css(),
                'blocks' => BuilderBlocksConfig::endpoints(),
                'i18n' => [
                    'saved' => __('landing.builder_page.saved'),
                    'saving' => __('landing.builder_page.saving'),
                    'save_failed' => __('landing.builder_page.save_failed'),
                    'merge_tags' => __('landing.builder_page.merge_tags'),
                    'fonts_placeholder' => '',
                    'blocks' => BuilderBlocksConfig::strings(),
                    'mobile' => [
                        'hide_on_mobile' => __('templates.builder_page.hide_on_mobile'),
                        'hide_on_mobile_hint' => __('templates.builder_page.hide_on_mobile_hint'),
                        'nothing_selected' => __('templates.builder_page.nothing_selected'),
                    ],
                ],
            ],
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back')
                ->label(__('landing.actions.back_to_index'))
                ->icon('heroicon-m-arrow-left')
                ->color('gray')
                ->url(LandingPageResource::getUrl('index')),

            Actions\Action::make('gallery')
                ->label(__('landing.actions.gallery'))
                ->icon('heroicon-m-swatch')
                ->color('gray')
                ->url(fn (): string => LandingPageResource::getUrl('gallery', ['record' => $this->getRecord()])),

            Actions\Action::make('settings')
                ->label(__('landing.actions.settings'))
                ->icon('heroicon-m-adjustments-horizontal')
                ->color('gray')
                ->url(fn (): string => LandingPageResource::getUrl('edit', ['record' => $this->getRecord()])),

            $this->revisionsAction(),
        ];
    }
}
