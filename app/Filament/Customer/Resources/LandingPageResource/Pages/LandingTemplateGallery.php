<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\LandingPageResource\Pages;

use App\Filament\Customer\Resources\LandingPageResource;
use App\Models\LandingPage;
use App\Services\Landing\LandingPageContentSaver;
use App\Services\Landing\LandingStyles;
use App\Services\Landing\LandingTemplates;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The landing-page template gallery (spec §2.6 F-2.6-003).
 *
 * Applying a design COPIES its markup onto the page — the §3.14 rule that a
 * gallery item is a starting point, never a live link. The previous document
 * is recorded as a revision first (F-2.6-005), so choosing the wrong design
 * is one restore away rather than a lost afternoon.
 */
class LandingTemplateGallery extends Page
{
    use InteractsWithRecord;

    protected static string $resource = LandingPageResource::class;

    protected static string $view = 'filament.customer.landing.gallery';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(auth('customer')->user()?->can('update', $this->record) ?? false, 403);
    }

    public function getTitle(): string|Htmlable
    {
        return __('landing.gallery.heading');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('landing.gallery.subheading');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'designs' => LandingTemplates::all(),
            'previewCss' => LandingStyles::css(),
            'currentKey' => (string) ($this->getPage()->template_key ?? ''),
        ];
    }

    public function applyDesign(string $key): void
    {
        $page = $this->getPage();

        abort_unless(auth('customer')->user()?->can('update', $page) ?? false, 403);
        abort_unless(LandingTemplates::exists($key), 404);

        app(LandingPageContentSaver::class)->applyTemplate($page, $key);

        Notification::make()->success()->title(__('landing.notifications.design_applied'))->send();

        $this->redirect(LandingPageResource::getUrl('build', ['record' => $page]));
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('build')
                ->label(__('landing.actions.build'))
                ->icon('heroicon-m-squares-2x2')
                ->color('gray')
                ->url(fn (): string => LandingPageResource::getUrl('build', ['record' => $this->getRecord()])),
            Actions\Action::make('back')
                ->label(__('landing.actions.back_to_index'))
                ->icon('heroicon-m-arrow-left')
                ->color('gray')
                ->url(LandingPageResource::getUrl('index')),
        ];
    }

    private function getPage(): LandingPage
    {
        /** @var LandingPage $record */
        $record = $this->getRecord();

        return $record;
    }
}
