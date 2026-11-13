<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\EmailListResource\Pages;

use App\Enums\CampaignStatus;
use App\Enums\ListFieldType;
use App\Filament\Customer\Resources\EmailListResource;
use App\Filament\Customer\Resources\EmailListResource\Widgets\ListGrowthChart;
use App\Filament\Customer\Resources\EmailListResource\Widgets\ListSourceChart;
use App\Filament\Customer\Resources\EmailListResource\Widgets\ListStatusOverview;
use App\Models\Campaign;
use App\Models\EmailList;
use App\Services\Reports\CampaignReportService;
use App\Services\Reports\GeoMapRenderer;
use App\Support\Geo\Countries;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;

/**
 * List overview (spec §2.2.3): status counter cards, growth chart, source
 * breakdown, country breakdown, an activity timeline and the recent campaigns
 * sent to this list with their real delivered/open/click numbers
 * (F-2.2.3-006, read through CampaignReportService). Delivery events on the
 * activity timeline are still a subscriber-side view only.
 */
class ListOverview extends Page
{
    use InteractsWithRecord;

    protected static string $resource = EmailListResource::class;

    protected static string $view = 'filament.customer.resources.email-list-resource.pages.list-overview';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 403);
    }

    public function getTitle(): string|Htmlable
    {
        return __('lists.overview.title', ['name' => $this->getRecord()->name]);
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('edit')
                ->label(__('lists.actions.edit'))
                ->url(fn (): string => EmailListResource::getUrl('edit', ['record' => $this->getRecord()])),
            Action::make('fields')
                ->label(__('lists.actions.fields'))
                ->url(fn (): string => EmailListResource::getUrl('fields', ['record' => $this->getRecord()])),
            Action::make('segments')
                ->label(__('lists.actions.segments'))
                ->url(fn (): string => EmailListResource::getUrl('segments', ['record' => $this->getRecord()])),
        ];
    }

    /** @return array<int, mixed> */
    protected function getHeaderWidgets(): array
    {
        $record = $this->getRecord();

        return [
            ListStatusOverview::make(['record' => $record]),
            ListGrowthChart::make(['record' => $record]),
            ListSourceChart::make(['record' => $record]),
        ];
    }

    public function getRecord(): EmailList
    {
        /** @var EmailList $record */
        $record = $this->record;

        return $record;
    }

    /**
     * Subscribers by country (F-2.2.3-004), aggregated from the list's first
     * Country custom field. Returns [] when the list has no country field.
     *
     * The TABLE half of F-2.2.3-004 is complete. The MAP half is not: an
     * accurate choropleth needs real per-country boundary geometry (Natural
     * Earth / world-atlas), which this install has no legitimate source for —
     * no mapping package is installed and none may be added. Hand-written
     * "country shapes" would be invented data on a page whose whole job is to
     * report facts, so the map is deliberately absent rather than faked, and
     * F-2.2.3-004 stays in-progress until a real dataset can be vendored.
     *
     * @return list<array{code: string, name: string, count: int, percent: float}>
     */
    public function countryBreakdown(): array
    {
        $record = $this->getRecord();

        $countryField = $record->fields()
            ->where('type', ListFieldType::Country->value)
            ->first();

        if ($countryField === null) {
            return [];
        }

        $rows = $countryField->values()
            ->selectRaw('value, count(*) as aggregate')
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->groupBy('value')
            ->orderByDesc('aggregate')
            ->limit(20)
            ->pluck('aggregate', 'value');

        $total = (int) $rows->sum();
        $countries = Countries::all();

        return $rows->map(fn (int|string $count, string $code): array => [
            'code' => $code,
            'name' => $countries[$code] ?? $code,
            'count' => (int) $count,
            'percent' => $total > 0 ? round(((int) $count) / $total * 100, 1) : 0.0,
        ])->values()->all();
    }

    /**
     * Country tile-cartogram for the overview (F-2.2.3-004). Reuses the exact
     * renderer the campaign geo report uses (§2.3.8) so the two screens can
     * never disagree; it is a tile cartogram (one equal-area tile per country
     * placed from its centroid), not a boundary choropleth — the caption in the
     * view says so (D-91).
     *
     * @return array{svg: string, rows: array<int, mixed>, total: int, mapped: int, unknown: int, max: int}
     */
    public function countryMap(): array
    {
        $breakdown = array_map(
            static fn (array $row): array => ['country' => $row['code'], 'count' => $row['count']],
            $this->countryBreakdown(),
        );

        return app(GeoMapRenderer::class)->renderBreakdown($breakdown);
    }

    /**
     * Recent campaigns sent to this list, with their real numbers
     * (F-2.2.3-006).
     *
     * "Sent to this list" = campaigns whose target is this list AND whose
     * send actually started (sending / sent / paused-mid-send), so drafts and
     * future schedules never appear. Every figure comes from
     * CampaignReportService — the single source of truth for §2.3.8 numbers
     * (WP-17) — so the overview can never drift from the campaign report.
     *
     * @return list<array{campaign: Campaign, delivered: int, opens: int, unique_opens: int, clicks: int, unique_clicks: int, open_rate: float, click_rate: float, sent_at: ?Carbon}>
     */
    public function recentCampaigns(int $limit = 5): array
    {
        $campaigns = Campaign::query()
            ->where('email_list_id', $this->getRecord()->id)
            ->whereIn('status', [
                CampaignStatus::Sending->value,
                CampaignStatus::Sent->value,
                CampaignStatus::Paused->value,
            ])
            ->whereNotNull('send_started_at')
            ->orderByDesc('send_started_at')
            ->limit($limit)
            ->get();

        $reports = app(CampaignReportService::class);

        return $campaigns->map(function (Campaign $campaign) use ($reports): array {
            $stats = $reports->headerStats($campaign);

            return [
                'campaign' => $campaign,
                'delivered' => $stats['delivered'],
                'opens' => $stats['opens'],
                'unique_opens' => $stats['unique_opens'],
                'clicks' => $stats['clicks'],
                'unique_clicks' => $stats['unique_clicks'],
                'open_rate' => $stats['open_rate'],
                'click_rate' => $stats['click_rate'],
                'sent_at' => $campaign->send_started_at,
            ];
        })->all();
    }

    /**
     * Recent list activity (F-2.2.3-007): subscribes, confirmations and
     * unsubscribes merged into one timeline. Campaign events join once the
     * campaigns work package records them.
     *
     * @return list<array{type: string, email: string, at: Carbon}>
     */
    public function activityTimeline(int $limit = 15): array
    {
        $record = $this->getRecord();

        $events = [];

        $recent = fn (string $column) => $record->subscribers()
            ->whereNotNull($column)
            ->orderByDesc($column)
            ->limit($limit)
            ->get(['email', $column]);

        foreach ($recent('subscribed_at') as $subscriber) {
            $events[] = ['type' => 'subscribed', 'email' => $subscriber->email, 'at' => $subscriber->subscribed_at];
        }

        foreach ($recent('confirmed_at') as $subscriber) {
            $events[] = ['type' => 'confirmed', 'email' => $subscriber->email, 'at' => $subscriber->confirmed_at];
        }

        foreach ($recent('unsubscribed_at') as $subscriber) {
            $events[] = ['type' => 'unsubscribed', 'email' => $subscriber->email, 'at' => $subscriber->unsubscribed_at];
        }

        usort($events, fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return array_slice($events, 0, $limit);
    }
}
