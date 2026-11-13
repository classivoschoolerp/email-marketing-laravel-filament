<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-45 — store connections (spec §2.12 F-2.12-001).
 *
 * One row per connected shop. `platform` is the StoreConnectorRegistry key
 * (shopify | woocommerce | magento | bigcommerce | prestashop | custom), so a
 * plugin-registered platform stores here with no schema change.
 *
 * `credentials` and `webhook_secret` use the `encrypted` cast: an API token is
 * a credential and must never sit in plaintext in a database dump (the
 * delivery-server rule, D-48, applied to shops).
 *
 * `sync_cursors` is the incremental-sync bookmark — one ISO timestamp (or
 * vendor page token) per resource, so a re-run picks up where it stopped
 * instead of re-reading the whole catalogue (F-2.12-002).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_connections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            $table->string('name');
            $table->string('platform', 32);
            $table->string('store_url', 512)->nullable();
            $table->char('currency', 3)->default('USD');

            $table->text('credentials')->nullable();     // encrypted json
            $table->text('webhook_secret')->nullable();  // encrypted string
            $table->json('settings')->nullable();
            $table->json('sync_cursors')->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('site_tracking_enabled')->default(false);
            $table->string('status', 16)->default('pending'); // pending|connected|error
            $table->string('last_error', 500)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();

            $table->index(['customer_id', 'is_active']);
            $table->index(['customer_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_connections');
    }
};
