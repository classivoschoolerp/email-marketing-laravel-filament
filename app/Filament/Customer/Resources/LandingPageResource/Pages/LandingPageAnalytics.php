<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\LandingPageResource\Pages;

use App\Filament\Customer\Resources\LandingPageResource;
use App\Models\LandingPage;
use App\Services\Landing\LandingPageStats;
use Filament\Actions;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Landing-page tracking report (spec §2.6 F-2.6-008): visits, unique
 * visitors, conversions and conversion rate, with the same four broken down
 * by source.
 */
class LandingPageAnalytics extends Page
{
    use InteractsWithRecord;

    protected static string $resource = LandingPageResource::class;

    protected static string $view = 'filament.customer.landing.analytics';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(auth('customer')->user()?->can('view', $this->record) ?? false, 403);
    }

    public function getTitle(): string|Htmlable
    {
        /** @var LandingPage $page */
        $page = $this->getRecord();

        return $page->name.' — '.__('landing.analytics.heading');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var LandingPage $page */
        $page = $this->getRecord();
        $stats = app(LandingPageStats::class);

        return [
            'page' => $page,
            'summary' => $stats->summary($page),
            'bySource' => $stats->bySource($page),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('variants')
                ->label(__('landing.actions.variants'))
                ->icon('heroicon-m-beaker')
                ->color('gray')
                ->url(fn (): string => LandingPageResource::getUrl('variants', ['record' => $this->getRecord()])),
            Actions\Action::make('back')
                ->label(__('landing.actions.back_to_index'))
                ->icon('heroicon-m-arrow-left')
                ->color('gray')
                ->url(LandingPageResource::getUrl('index')),
        ];
    }
}
