<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-40 — per-tenant analytics integration credentials (spec §4.2 F-4.2-012).
 *
 * One row per customer. GA4 is a per-property integration: in a multi-tenant
 * install every account has its own measurement ID and its own API secret, so
 * this cannot be an operator-wide settings group. The secret is stored in an
 * `encrypted` cast column, never in a settings blob.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_analytics_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained('customers')->cascadeOnDelete();

            $table->boolean('ga4_enabled')->default(false);
            $table->string('ga4_measurement_id', 64)->nullable();
            $table->text('ga4_api_secret')->nullable();
            $table->boolean('ga4_track_opens')->default(true);
            $table->boolean('ga4_track_clicks')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_analytics_settings');
    }
};
