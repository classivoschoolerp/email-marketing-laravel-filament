<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-09 — click-tracker hits (spec §0.5 / §1.7 F-1.7-002/006). One row per
 * click; `url_id` references the campaign_track_urls row that was hit.
 *
 * `campaign_id` is a plain unsignedBigInteger (nullable, indexed), NOT an FK:
 * the campaigns table arrives with WP-11. Geo columns stay null until a GeoIP
 * provider is configured (D-10). Log table: created_at only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_track_clicks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('campaign_id')->nullable()->index();
            $table->string('campaign_uid', 36)->index();
            $table->string('subscriber_uid', 36)->index();
            $table->foreignId('url_id')->constrained('campaign_track_urls')->cascadeOnDelete();

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('device', 32)->nullable();
            $table->string('os', 64)->nullable();
            $table->string('browser', 64)->nullable();
            $table->string('client', 64)->nullable();

            $table->string('country', 64)->nullable();
            $table->string('region', 64)->nullable();
            $table->string('city', 64)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['campaign_uid', 'subscriber_uid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_track_clicks');
    }
};
