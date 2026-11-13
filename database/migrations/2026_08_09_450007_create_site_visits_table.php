<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-45 — site-tracking hits (spec §2.12 F-2.12-010, §2.4.2 F-2.4.2-016).
 *
 * PRIVACY (§4.5 / D-108 / D-100), and this is the reason the column list is
 * this short:
 *  - `path` only. The collector strips the query string BEFORE writing, so a
 *    checkout URL carrying an email or a token can never land here.
 *  - `ip` goes through App\Support\Privacy\IpAnonymizer at capture time,
 *    exactly like the WP-09 tracking endpoints.
 *  - there is NO email column and no free-form payload. A visitor is
 *    identified only by `subscriber_uid` — a value this platform itself put
 *    in the click URL — or not at all.
 *  - `visitor_key` is a random opaque token minted by the browser script and
 *    stored as a SHA-256 hash. It groups a session's page views without ever
 *    being reversible to a person.
 *
 * Log-table shape (created_at only) mirroring campaign_track_*; pruned by
 * `emp-ecommerce.site_tracking.retention_days`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('store_connection_id')->constrained('store_connections')->cascadeOnDelete();

            $table->string('event', 16);                 // page_view|product_view
            $table->string('visitor_key', 64)->nullable();
            $table->string('subscriber_uid', 36)->nullable();
            $table->unsignedBigInteger('subscriber_id')->nullable();

            $table->string('path', 512);
            $table->string('title', 255)->nullable();
            $table->string('referrer_host', 191)->nullable();

            $table->string('product_external_id', 191)->nullable();
            $table->unsignedBigInteger('store_product_id')->nullable();

            $table->string('campaign_uid', 36)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('device', 32)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['customer_id', 'created_at']);
            $table->index(['subscriber_id', 'event', 'created_at'], 'site_visits_subscriber_idx');
            $table->index(['store_connection_id', 'product_external_id'], 'site_visits_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_visits');
    }
};
