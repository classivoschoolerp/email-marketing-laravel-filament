<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-13 — daily per-campaign stats rollup (spec §0.6 F-0.6-008). Populated
 * hourly by `stats:rollup` from campaign_delivery_logs + campaign_track_*
 * tables; consumed by WP-17/18 reports and dashboards. `day` is the UTC date
 * of the underlying events; rows are recomputed idempotently (upsert) so the
 * rollup may run any number of times per hour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_stats_daily', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('campaign_id');
            $table->date('day');
            $table->unsignedInteger('sent')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('opens')->default(0);
            $table->unsignedInteger('unique_opens')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('unique_clicks')->default(0);
            $table->unsignedInteger('unsubscribes')->default(0);
            $table->timestamps();

            $table->unique(['campaign_id', 'day']);
            $table->index('day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_stats_daily');
    }
};
