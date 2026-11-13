<?php

declare(strict_types=1);

use App\Services\Reports\CampaignReportService;
use App\Support\Tenancy\CurrentCustomer;
use Tests\Support\CampaignReportFixture;

// §2.3.8 — CampaignReportService: every number exact against the seeded
// fixture (F-2.3.8-001/005/006/011..016). Single source of truth for the
// report page, the public share page and the CSV exports.

beforeEach(function (): void {
    $this->fixture = CampaignReportFixture::build();
    $this->actingAs($this->fixture->customer, 'customer');
    app(CurrentCustomer::class)->set($this->fixture->customer);
    $this->service = app(CampaignReportService::class);
});

// F-2.3.8-001 — header stats, exact.
it('computes every header stat exactly', function (): void {
    $stats = $this->service->headerStats($this->fixture->campaign);

    expect($stats['recipients'])->toBe(5)
        ->and($stats['delivered'])->toBe(4)
        ->and($stats['failed'])->toBe(1)
        ->and($stats['skipped'])->toBe(0)
        ->and($stats['bounced'])->toBe(3)
        ->and($stats['bounced_hard'])->toBe(1)
        ->and($stats['bounced_soft'])->toBe(1)
        ->and($stats['bounced_internal'])->toBe(1)
        ->and($stats['opens'])->toBe(4)
        ->and($stats['unique_opens'])->toBe(2)
        ->and($stats['open_rate'])->toBe(50.0)
        ->and($stats['clicks'])->toBe(3)
        ->and($stats['unique_clicks'])->toBe(1)
        ->and($stats['click_rate'])->toBe(25.0)
        ->and($stats['ctor'])->toBe(50.0)
        ->and($stats['unsubscribes'])->toBe(1)
        ->and($stats['complaints'])->toBe(1)
        // WP-35 (F-1.6-002 / F-1.7-004): forward tracking is real now.
        ->and($stats['forwards'])->toBe(3)
        ->and($stats['forwarders'])->toBe(2);
});

it('falls back to delivery log counts when the counter was never stamped', function (): void {
    $this->fixture->campaign->forceFill(['delivery_total' => 0])->save();

    $stats = $this->service->headerStats($this->fixture->campaign->refresh());

    expect($stats['recipients'])->toBe(5); // 5 delivery log rows
});

// F-2.3.8-011 — opens over time, daily buckets exact.
it('builds the daily opens series with filled buckets', function (): void {
    $series = $this->service->series($this->fixture->campaign, 'opens', 'daily');

    expect($series)->toHaveCount(2)
        ->and($series[now()->subDay()->format('Y-m-d')])->toBe(1)
        ->and($series[now()->format('Y-m-d')])->toBe(3);
});

// F-2.3.8-011 — hourly buckets (48h window) exact.
it('builds the hourly opens series', function (): void {
    $series = $this->service->series($this->fixture->campaign, 'opens', 'hourly');

    expect($series)->toHaveCount(48)
        ->and($series->sum())->toBe(4)
        ->and($series[now()->subHour()->format('Y-m-d H').':00'])->toBe(2)
        ->and($series[now()->subHours(2)->format('Y-m-d H').':00'])->toBe(1);
});

// F-2.3.8-012 — clicks over time.
it('builds the daily clicks series', function (): void {
    $series = $this->service->series($this->fixture->campaign, 'clicks', 'daily');

    expect($series->sum())->toBe(3)
        ->and($series[now()->format('Y-m-d')])->toBe(3);
});

// F-2.3.8-006 — top links with % share of all clicks.
it('ranks top clicked links with exact share percentages', function (): void {
    $links = $this->service->topLinks($this->fixture->campaign);

    expect($links)->toHaveCount(2)
        ->and($links[0]['url'])->toBe('https://example.com/a')
        ->and($links[0]['clicks'])->toBe(2)
        ->and($links[0]['unique_clickers'])->toBe(1)
        ->and($links[0]['percent'])->toBe(66.7)
        ->and($links[1]['url'])->toBe('https://example.com/b')
        ->and($links[1]['clicks'])->toBe(1)
        ->and($links[1]['percent'])->toBe(33.3);
});

// F-2.3.8-014/015/016 — device / OS / browser / client breakdowns.
it('splits opens by device, os, browser and email client exactly', function (): void {
    $campaign = $this->fixture->campaign;

    expect($this->service->breakdown($campaign, 'device'))->toBe([
        ['label' => 'desktop', 'count' => 3, 'percent' => 75.0],
        ['label' => 'mobile', 'count' => 1, 'percent' => 25.0],
    ])->and($this->service->breakdown($campaign, 'os'))->toBe([
        ['label' => 'Windows', 'count' => 3, 'percent' => 75.0],
        ['label' => 'iOS', 'count' => 1, 'percent' => 25.0],
    ])->and($this->service->breakdown($campaign, 'browser'))->toBe([
        ['label' => 'Chrome', 'count' => 3, 'percent' => 75.0],
        ['label' => 'Safari', 'count' => 1, 'percent' => 25.0],
    ])->and($this->service->breakdown($campaign, 'client'))->toBe([
        ['label' => 'Gmail', 'count' => 3, 'percent' => 75.0],
        ['label' => 'Apple Mail', 'count' => 1, 'percent' => 25.0],
    ])->and($this->service->breakdown($campaign, 'nope'))->toBe([]);
});

// F-2.3.8-013 — geo rows: honest unknown bucket until GeoIP (D-10).
it('reports geo rows with the unknown bucket when no geoip data exists', function (): void {
    $geo = $this->service->geoBreakdown($this->fixture->campaign);

    expect($geo)->toHaveCount(1)
        ->and($geo[0]['country'])->toBeNull()
        ->and($geo[0]['count'])->toBe(4)
        ->and($geo[0]['percent'])->toBe(100.0);

    // With geo data present, countries aggregate by country/region/city.
    \App\Models\CampaignTrackOpen::query()
        ->where('campaign_uid', $this->fixture->campaign->uid)
        ->where('subscriber_uid', $this->fixture->subscribers[1]->uid)
        ->update(['country' => 'Lebanon', 'region' => 'Beirut', 'city' => 'Beirut']);

    $geo = $this->service->geoBreakdown($this->fixture->campaign);

    expect($geo)->toHaveCount(2)
        ->and($geo[0]['country'])->toBe('Lebanon')
        ->and($geo[0]['count'])->toBe(3)
        ->and($geo[0]['percent'])->toBe(75.0)
        ->and($geo[1]['country'])->toBeNull()
        ->and($geo[1]['count'])->toBe(1);
});

// F-2.3.8-004 — per-subscriber opens aggregation.
it('aggregates the opens tab per subscriber', function (): void {
    $rows = $this->service->opensQuery($this->fixture->campaign)
        ->orderBy('subscriber_uid')
        ->get()
        ->keyBy('subscriber_uid');

    expect($rows)->toHaveCount(2);

    $s1 = $rows[$this->fixture->subscribers[1]->uid];

    expect((int) $s1->times_opened)->toBe(3)
        ->and($s1->subscriber_email)->toBe('s1@fixture.test')
        ->and($s1->device)->toBe('desktop')
        ->and((string) $s1->first_open_at)->toBe(now()->subDay()->setTime(9, 0)->format('Y-m-d H:i:s'));

    $s2 = $rows[$this->fixture->subscribers[2]->uid];

    expect((int) $s2->times_opened)->toBe(1)
        ->and($s2->subscriber_email)->toBe('s2@fixture.test');
});

// F-2.3.8-005 — per-URL clicks + who-clicked drill-down.
it('aggregates clicks per url and drills into clickers', function (): void {
    $urls = $this->service->clicksQuery($this->fixture->campaign)
        ->orderByDesc('clicks_count')
        ->get();

    expect($urls)->toHaveCount(2)
        ->and((int) $urls[0]->clicks_count)->toBe(2)
        ->and((int) $urls[0]->unique_clickers)->toBe(1)
        ->and((int) $urls[1]->clicks_count)->toBe(1);

    $clickers = $this->service->clickersQuery($this->fixture->urlA)->get();

    expect($clickers)->toHaveCount(1)
        ->and($clickers[0]->subscriber_email)->toBe('s1@fixture.test')
        ->and((int) $clickers[0]->clicks_count)->toBe(2);
});

// F-2.3.8-007/008/009/010 — tab queries return the exact rows.
it('returns exact rows for the bounce, unsubscribe, complaint and delivery tabs', function (): void {
    $campaign = $this->fixture->campaign;

    expect($this->service->bouncesQuery($campaign)->count())->toBe(3)
        ->and($this->service->complaintsQuery($campaign)->count())->toBe(1)
        ->and($this->service->deliveryLogsQuery($campaign)->count())->toBe(5);

    $unsubs = $this->service->unsubscribesQuery($campaign)->get();

    expect($unsubs)->toHaveCount(1)
        ->and($unsubs[0]->subscriber_email)->toBe('s2@fixture.test')
        ->and($unsubs[0]->ip)->toBe('9.9.9.9')
        ->and($unsubs[0]->unsubscribe_reason)->toBe('Too many emails');

    $failed = $this->service->deliveryLogsQuery($campaign)
        ->where('status', 'failed')
        ->get();

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->subscriber->email)->toBe('s5@fixture.test')
        ->and($failed[0]->response)->toBe('SMTP 550 mailbox unavailable')
        ->and((int) $failed[0]->retries)->toBe(2);
});
