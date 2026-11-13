<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\CampaignResource\Pages;

use App\Enums\CampaignStatus;
use App\Enums\ExportFormat;
use App\Filament\Customer\Resources\CampaignResource;
use App\Filament\Customer\Resources\CampaignResource\Widgets\ClicksOverTimeChart;
use App\Filament\Customer\Resources\CampaignResource\Widgets\OpensOverTimeChart;
use App\Models\Campaign;
use App\Services\Campaigns\AbTest\AbTestStats;
use App\Services\Campaigns\AbTest\AbWinnerSelector;
use App\Services\Campaigns\AbTest\MultivariateAnalysis;
use App\Services\Campaigns\AbTest\SignificanceResult;
use App\Services\Reports\CampaignComparisonService;
use App\Services\Reports\CampaignReportExporter;
use App\Services\Reports\CampaignReportService;
use App\Services\Reports\ClickMapRenderer;
use App\Services\Reports\EngagementHeatTable;
use App\Services\Reports\GeoMapRenderer;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Campaign report / overview (spec §2.3.8, WP-17): header stats, charts,
 * detailed report tabs, engagement breakdowns and queued CSV exports —
 * every number computed by CampaignReportService (single source, D-64).
 * Auto-refreshes (wire:poll on the view + widget polling) while the
 * campaign is sending (F-2.3.8-021). D-29: InteractsWithRecord, never a
 * model-typed $record property.
 */
class CampaignReport extends Page
{
    use InteractsWithRecord;

    protected static string $resource = CampaignResource::class;

    protected static string $view = 'filament.customer.resources.campaign-resource.pages.campaign-report';

    /** Active detail tab (F-2.3.8-004..016); part of the URL for deep links. */
    public string $activeTab = 'opens';

    /** @var array<string, string> */
    protected $queryString = ['activeTab' => ['except' => 'opens']];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 403);

        if (! in_array($this->activeTab, $this->tabs(), true)) {
            $this->activeTab = 'opens';
        }
    }

    public function getRecord(): Campaign
    {
        /** @var Campaign $record */
        $record = $this->record;

        return $record;
    }

    public function getTitle(): string|Htmlable
    {
        return __('reports.page.title', ['name' => $this->getRecord()->name]);
    }

    public function getSubheading(): string|Htmlable|null
    {
        if ($this->getRecord()->status !== CampaignStatus::Sending) {
            return null;
        }

        // Live progress while sending (F-2.3.8-021).
        return __('reports.page.sending_progress', [
            'percent' => $this->getRecord()->sendProgressPercent(),
        ]);
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_report')
                ->label(__('reports.actions.export_full'))
                ->icon('heroicon-m-archive-box-arrow-down')
                // F-2.3.8-018 (WP-26): CSV → zip of one file per tab,
                // XLSX → a single workbook with a sheet per tab.
                ->form([
                    Radio::make('format')
                        ->label(__('reports.export.format'))
                        ->options(ExportFormat::options())
                        ->default(ExportFormat::Csv->value)
                        ->inline()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    app(CampaignReportExporter::class)->queue(
                        $this->getRecord(),
                        CampaignReportExporter::FULL,
                        $data['format'] ?? null,
                    );

                    Notification::make()->success()
                        ->title(__('reports.notifications.export_queued'))
                        ->send();
                }),

            Action::make('share_report')
                ->label(__('campaigns.actions.share_report'))
                ->icon('heroicon-m-link')
                ->visible(fn (): bool => $this->getRecord()->shareReportUrl() !== null)
                ->url(fn (): string => (string) $this->getRecord()->shareReportUrl(), shouldOpenInNewTab: true),

            // F-2.3.8-020 — print / PDF. A print-styled standalone page the
            // browser prints (or "saves as PDF"): no PDF library is vendored,
            // and the page says so instead of implying server-side rendering.
            Action::make('print_report')
                ->label(__('reports.print.action'))
                ->icon('heroicon-m-printer')
                ->url(fn (): string => route('campaigns.report.print', ['campaign' => $this->getRecord()->getRouteKey()]), shouldOpenInNewTab: true),

            Action::make('edit')
                ->label(__('campaigns.actions.edit'))
                ->visible(fn (): bool => $this->getRecord()->isEditable())
                ->url(fn (): string => CampaignResource::getUrl('edit', ['record' => $this->getRecord()])),
        ];
    }

    /** @return array<int, mixed> */
    protected function getHeaderWidgets(): array
    {
        return [
            OpensOverTimeChart::make(['record' => $this->getRecord()]),
            ClicksOverTimeChart::make(['record' => $this->getRecord()]),
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 2;
    }

    /* ================================================================ *
     *  View data
     * ================================================================ */

    /** @return array<string, int|float|null> */
    public function stats(): array
    {
        return app(CampaignReportService::class)->headerStats($this->getRecord());
    }

    public function isSending(): bool
    {
        return $this->getRecord()->status === CampaignStatus::Sending;
    }

    /** Detail tabs, in render order. @return list<string> */
    public function tabs(): array
    {
        $tabs = ['opens', 'clicks', 'top_links', 'bounces', 'unsubscribes', 'complaints', 'delivery', 'geo', 'devices'];

        // WP-35 §2.3.8-017 engagement heat table + §2.3.8-003 benchmarks.
        $tabs[] = 'heat';
        $tabs[] = 'comparison';

        return $tabs;
    }

    /* ================================================================ *
     *  WP-35 additions
     * ================================================================ */

    /** §2.3.6 F-2.3.6-009 — per-variant comparison rows (empty when not A/B). */
    public function variantStats(): array
    {
        $campaign = $this->getRecord();

        if (! $campaign->ab_enabled) {
            return [];
        }

        return app(AbTestStats::class)->table($campaign);
    }

    /** Live leader-vs-runner-up significance read-out (F-2.3.6-010/011). */
    public function variantSignificance(): ?SignificanceResult
    {
        $campaign = $this->getRecord();

        if (! $campaign->ab_enabled || $campaign->variants()->count() < 2) {
            return null;
        }

        return app(AbWinnerSelector::class)->currentSignificance($campaign);
    }

    /**
     * §2.3.6 F-2.3.6-004 (WP-46) — per-FACTOR effects of a multivariate
     * design, Holm–Bonferroni corrected. `available` is false (and the report
     * block hides itself) for an ordinary A/B campaign.
     *
     * @return array<string, mixed>
     */
    public function factorEffects(): array
    {
        return app(MultivariateAnalysis::class)->effects($this->getRecord());
    }

    /** F-2.3.6-007 "or manual" — send one variant to the remainder. */
    public function chooseWinner(int $variantId): void
    {
        $campaign = $this->getRecord();

        abort_unless(static::getResource()::canView($campaign), 403);

        $variant = $campaign->variants()->whereKey($variantId)->first();

        if ($variant === null || $campaign->ab_winner_variant_id !== null) {
            return;
        }

        app(AbWinnerSelector::class)->chooseManually($campaign, $variant);

        Notification::make()->success()->title(__('campaigns.ab.report.picked'))->send();

        $this->record = $campaign->refresh();
    }

    /** §2.3.8-003 — vs account average / previous campaign. */
    public function benchmarks(): array
    {
        return app(CampaignComparisonService::class)->benchmarks($this->getRecord());
    }

    /** §2.3.8-017 — subscriber engagement heat table + day/hour grid. */
    public function heatRows(): array
    {
        return app(EngagementHeatTable::class)->rows($this->getRecord());
    }

    public function heatGrid(): array
    {
        return app(EngagementHeatTable::class)->grid($this->getRecord());
    }

    /** @return list<array{label: string, count: int, percent: float}> */
    public function breakdown(string $dimension): array
    {
        return app(CampaignReportService::class)->breakdown($this->getRecord(), $dimension);
    }

    /** @return list<array{country: ?string, region: ?string, city: ?string, count: int, percent: float}> */
    public function geoRows(): array
    {
        return app(CampaignReportService::class)->geoBreakdown($this->getRecord());
    }

    /** @return list<array{url: string, clicks: int, unique_clickers: int, percent: float}> */
    public function topLinks(): array
    {
        return app(CampaignReportService::class)->topLinks($this->getRecord());
    }

    /**
     * Click map (F-2.3.8-005): the campaign HTML with every anchor badged
     * with its exact click count, rank and share, shaded by that share.
     * Rendered inside a sandboxed iframe by the view.
     *
     * @return array{html: string, links: list<array<string, mixed>>, total_clicks: int, anchors: int}
     */
    public function clickMap(): array
    {
        return app(ClickMapRenderer::class)->render($this->getRecord());
    }

    /**
     * Geo map (F-2.3.8-013): the tile-cartogram world map + its legend.
     *
     * @return array{svg: string, rows: list<array<string, mixed>>, total: int, mapped: int, unknown: int, max: int}
     */
    public function geoMap(): array
    {
        return app(GeoMapRenderer::class)->render($this->getRecord());
    }

    /**
     * Queued export for the blade-rendered tabs (F-2.3.8-018). The optional
     * second argument is the WP-26 format; the blade buttons pass 'csv' and
     * 'xlsx' explicitly.
     */
    public function exportTab(string $tab, ?string $format = null): void
    {
        if (! in_array($tab, CampaignReportService::TABS, true)) {
            return;
        }

        app(CampaignReportExporter::class)->queue($this->getRecord(), $tab, $format);

        Notification::make()->success()
            ->title(__('reports.notifications.export_queued'))
            ->send();
    }
}
