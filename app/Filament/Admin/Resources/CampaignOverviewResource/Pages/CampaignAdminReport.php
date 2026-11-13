<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CampaignOverviewResource\Pages;

use App\Filament\Admin\Resources\CampaignOverviewResource;
use App\Models\Campaign;
use App\Services\Reports\CampaignReportService;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Read-only admin rendering of any campaign's report (spec §3.7 F-3.7-002,
 * WP-18): the §2.3.8 header stats + top links from the WP-17
 * CampaignReportService — a compact stats page, deliberately not the full
 * customer report. Gated by CampaignPolicy view (`campaigns.manage`).
 */
class CampaignAdminReport extends Page
{
    use InteractsWithRecord;

    protected static string $resource = CampaignOverviewResource::class;

    protected static string $view = 'filament.admin.resources.campaign-overview-resource.pages.campaign-admin-report';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(auth('admin')->user()?->can('view', $this->getRecord()) ?? false, 403);
    }

    public function getTitle(): string|Htmlable
    {
        return __('dashboards.campaigns_overview.report.title', ['name' => $this->getRecord()->name]);
    }

    public function getRecord(): Campaign
    {
        /** @var Campaign $record */
        $record = $this->record;

        return $record;
    }

    /** @return array<string, int|float|null> */
    public function stats(): array
    {
        return app(CampaignReportService::class)->headerStats($this->getRecord());
    }

    /** @return list<array{url: string, clicks: int, unique_clickers: int, percent: float}> */
    public function topLinks(): array
    {
        return app(CampaignReportService::class)->topLinks($this->getRecord(), 10);
    }
}
