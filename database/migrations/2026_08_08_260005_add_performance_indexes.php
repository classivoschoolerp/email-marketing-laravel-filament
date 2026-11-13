<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §4.6 F-4.6-006 "Query optimisation & indexes on (customer_id, list_id,
 * status, email)" (WP-26).
 *
 * The spec's named shape already exists — `subscribers_tenant_list_status_email`
 * from the WP-06 subscribers migration. This migration closes the gaps the
 * hot paths actually hit, each one traced back to a real query. Every index
 * below was checked against `EXPLAIN QUERY PLAN` on SQLite (the dev/test
 * driver, D-03) and is documented in docs/performance-indexes.md.
 *
 *  1. campaigns (type, status, send_at) and (type, status, send_batch_id)
 *     — CampaignsSendCommand runs BOTH every single minute: the "due"
 *     scan and the stalled-wave self-heal scan. Before this the planner
 *     could only use (customer_id, type), which the tenant-free wave query
 *     cannot touch, so it was a full table scan per minute.
 *
 *  2. campaign_delivery_logs (campaign_id, status, created_at) — replaces
 *     the strictly narrower (campaign_id, status). Serves the report tabs,
 *     CampaignStatsRollup's ranged group-by and QuotaNotifier's
 *     "delivered this calendar month" count. The old two-column index is a
 *     left prefix of the new one, so dropping it costs nothing on reads and
 *     saves a write on the highest-insert-rate table in the system.
 *
 *  3. campaign_track_{opens,clicks,unsubscribes} (campaign_id, created_at)
 *     — CampaignStatsRollup filters `created_at BETWEEN` + `campaign_id IN`
 *     and groups by campaign_id; the report time-series do the same per
 *     campaign. Only single-column indexes existed.
 *
 *  4. campaign_track_clicks (url_id, subscriber_uid) — the
 *     `count(distinct subscriber_uid) where url_id = ?` unique-clicker
 *     subquery runs once per URL on the clicks tab and in topLinks.
 *
 *  5. subscribers (customer_id, status) and (customer_id, email) — tenant
 *     dashboards and quota counts span every list, so the leading
 *     list_id/customer_id+list_id indexes do not apply; the lone `email`
 *     index is not tenant-scoped.
 *
 *  6. automation_runs (status, waiting_for) and (customer_id, status) —
 *     the tick resumes runs parked on an event, and the customer UI lists
 *     runs per tenant.
 *
 *  7. orders (customer_id, created_at) — the customer billing history is
 *     always tenant + newest-first.
 *
 * MySQL note (D-03): subscribers, campaign_delivery_logs,
 * campaign_track_opens and campaign_track_clicks are monthly-partitioned by
 * `db:partition`, which requires the partition key inside every UNIQUE key.
 * Everything added here is a plain secondary index, so partitioning is
 * unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->index(['type', 'status', 'send_at'], 'campaigns_wave_due_idx');
            $table->index(['type', 'status', 'send_batch_id'], 'campaigns_wave_batch_idx');
        });

        Schema::table('campaign_delivery_logs', function (Blueprint $table): void {
            $table->index(['campaign_id', 'status', 'created_at'], 'delivery_logs_campaign_status_created_idx');
            $table->dropIndex(['campaign_id', 'status']); // left prefix of the above
        });

        Schema::table('campaign_track_opens', function (Blueprint $table): void {
            $table->index(['campaign_id', 'created_at'], 'track_opens_campaign_created_idx');
        });

        Schema::table('campaign_track_clicks', function (Blueprint $table): void {
            $table->index(['campaign_id', 'created_at'], 'track_clicks_campaign_created_idx');
            $table->index(['url_id', 'subscriber_uid'], 'track_clicks_url_subscriber_idx');
        });

        Schema::table('campaign_track_unsubscribes', function (Blueprint $table): void {
            $table->index(['campaign_id', 'created_at'], 'track_unsubs_campaign_created_idx');
        });

        Schema::table('subscribers', function (Blueprint $table): void {
            $table->index(['customer_id', 'status'], 'subscribers_customer_status_idx');
            $table->index(['customer_id', 'email'], 'subscribers_customer_email_idx');
        });

        Schema::table('automation_runs', function (Blueprint $table): void {
            $table->index(['status', 'waiting_for'], 'automation_runs_status_waiting_idx');
            $table->index(['customer_id', 'status'], 'automation_runs_customer_status_idx');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['customer_id', 'created_at'], 'orders_customer_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_customer_created_idx');
        });

        Schema::table('automation_runs', function (Blueprint $table): void {
            $table->dropIndex('automation_runs_status_waiting_idx');
            $table->dropIndex('automation_runs_customer_status_idx');
        });

        Schema::table('subscribers', function (Blueprint $table): void {
            $table->dropIndex('subscribers_customer_status_idx');
            $table->dropIndex('subscribers_customer_email_idx');
        });

        Schema::table('campaign_track_unsubscribes', function (Blueprint $table): void {
            $table->dropIndex('track_unsubs_campaign_created_idx');
        });

        Schema::table('campaign_track_clicks', function (Blueprint $table): void {
            $table->dropIndex('track_clicks_campaign_created_idx');
            $table->dropIndex('track_clicks_url_subscriber_idx');
        });

        Schema::table('campaign_track_opens', function (Blueprint $table): void {
            $table->dropIndex('track_opens_campaign_created_idx');
        });

        Schema::table('campaign_delivery_logs', function (Blueprint $table): void {
            $table->index(['campaign_id', 'status']);
            $table->dropIndex('delivery_logs_campaign_status_created_idx');
        });

        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropIndex('campaigns_wave_due_idx');
            $table->dropIndex('campaigns_wave_batch_idx');
        });
    }
};
