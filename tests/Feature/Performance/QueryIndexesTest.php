<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
 * WP-26 §4.6 F-4.6-006 — query optimisation & indexes.
 *
 * Two layers of proof:
 *  1. the indexes exist (schema assertion, driver-independent);
 *  2. SQLite's planner actually PICKS them for the real hot queries
 *     (EXPLAIN QUERY PLAN). SQLite is the dev/test driver per D-03; the
 *     same shapes are what MySQL needs in production.
 */

/** @return list<string> index names on a table (SQLite) */
function wp26_indexes(string $table): array
{
    return collect(DB::select("PRAGMA index_list('".$table."')"))
        ->pluck('name')
        ->map(strval(...))
        ->all();
}

function wp26_plan(string $sql): string
{
    return collect(DB::select('EXPLAIN QUERY PLAN '.$sql))
        ->map(fn ($row): string => (string) ($row->detail ?? ''))
        ->implode(' | ');
}

it('keeps the spec-named (customer_id, list_id, status, email) subscriber index', function (): void {
    expect(wp26_indexes('subscribers'))->toContain('subscribers_tenant_list_status_email');
});

it('adds every WP-26 composite index', function (): void {
    expect(wp26_indexes('campaigns'))->toContain('campaigns_wave_due_idx', 'campaigns_wave_batch_idx')
        ->and(wp26_indexes('campaign_delivery_logs'))->toContain('delivery_logs_campaign_status_created_idx')
        ->and(wp26_indexes('campaign_track_opens'))->toContain('track_opens_campaign_created_idx')
        ->and(wp26_indexes('campaign_track_clicks'))->toContain('track_clicks_campaign_created_idx', 'track_clicks_url_subscriber_idx')
        ->and(wp26_indexes('campaign_track_unsubscribes'))->toContain('track_unsubs_campaign_created_idx')
        ->and(wp26_indexes('subscribers'))->toContain('subscribers_customer_status_idx', 'subscribers_customer_email_idx')
        ->and(wp26_indexes('automation_runs'))->toContain('automation_runs_status_waiting_idx', 'automation_runs_customer_status_idx')
        ->and(wp26_indexes('orders'))->toContain('orders_customer_created_idx');
});

it('drops the redundant (campaign_id, status) prefix index on delivery logs', function (): void {
    expect(wp26_indexes('campaign_delivery_logs'))
        ->not->toContain('campaign_delivery_logs_campaign_id_status_index');
});

it('uses an index for the every-minute send-wave scan', function (): void {
    // CampaignsSendCommand: due campaigns.
    expect(wp26_plan("select * from campaigns where type = 'regular' and status = 'scheduled' and send_at <= '2026-01-01'"))
        ->toContain('campaigns_wave_due_idx');

    // CampaignsSendCommand: stalled-wave self-heal.
    expect(wp26_plan("select * from campaigns where type = 'regular' and status = 'sending' and send_batch_id = 'pending'"))
        ->toContain('campaigns_wave_batch_idx');
});

it('uses an index for the monthly delivered-count quota query', function (): void {
    // QuotaNotifier::monthlyUsage + the report delivery tab.
    expect(wp26_plan("select count(*) from campaign_delivery_logs where campaign_id = 1 and status = 'sent' and created_at >= '2026-08-01'"))
        ->toContain('delivery_logs_campaign_status_created_idx');
});

it('uses indexes for the rollup range scans on the tracking tables', function (): void {
    foreach ([
        'campaign_track_opens' => 'track_opens_campaign_created_idx',
        'campaign_track_clicks' => 'track_clicks_campaign_created_idx',
        'campaign_track_unsubscribes' => 'track_unsubs_campaign_created_idx',
    ] as $table => $index) {
        expect(wp26_plan(
            "select campaign_id, count(*) from {$table} where campaign_id = 1 and created_at between '2026-08-01' and '2026-08-02' group by campaign_id"
        ))->toContain($index);
    }
});

it('uses an index for the unique-clickers subquery', function (): void {
    expect(wp26_plan('select count(distinct subscriber_uid) from campaign_track_clicks where url_id = 1'))
        ->toContain('track_clicks_url_subscriber_idx');
});

it('uses tenant-scoped indexes for cross-list subscriber lookups', function (): void {
    expect(wp26_plan("select count(*) from subscribers where customer_id = 1 and status = 'confirmed'"))
        ->toContain('subscribers_customer_status_idx');

    expect(wp26_plan("select * from subscribers where customer_id = 1 and email = 'x@example.com'"))
        ->toContain('subscribers_customer_email_idx');
});

it('uses an index for the tenant billing history', function (): void {
    expect(wp26_plan('select * from orders where customer_id = 1 order by created_at desc'))
        ->toContain('orders_customer_created_idx');
});

it('uses an index for parked automation runs waiting on an event', function (): void {
    expect(wp26_plan("select * from automation_runs where status = 'waiting' and waiting_for = 'tag_added'"))
        ->toContain('automation_runs_status_waiting_idx');
});
