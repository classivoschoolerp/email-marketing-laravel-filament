<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-09 — campaign unsubscribe-tracker hits (spec §0.5 / §1.7 F-1.7-003/006).
 * Records which campaign an unsubscribe originated from before handing the
 * visitor to the list unsubscribe flow (§1.5).
 *
 * `campaign_id` is a plain unsignedBigInteger (nullable, indexed), NOT an FK:
 * the campaigns table arrives with WP-11. Geo columns stay null until a GeoIP
 * provider is configured (D-10). Log table: created_at only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_track_unsubscribes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('campaign_id')->nullable()->index();
            $table->string('campaign_uid', 36)->index();
            $table->string('subscriber_uid', 36)->index();

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
        Schema::dropIfExists('campaign_track_unsubscribes');
    }
};
