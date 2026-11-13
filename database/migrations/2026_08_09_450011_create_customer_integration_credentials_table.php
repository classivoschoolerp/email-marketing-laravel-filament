<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-45 — per-tenant native integration credentials (spec §2.12 F-2.12-012).
 *
 * Google Sheets, Salesforce, HubSpot, Slack and the Meta Pixel Conversions API
 * are all PER-ACCOUNT integrations: the token belongs to the customer, not to
 * the install — the same reasoning that put GA4 on `customer_analytics_settings`
 * (WP-40). `credentials` uses the `encrypted` cast.
 *
 * GA4 (F-4.2-012) and Stripe (§3.26.3) are deliberately NOT duplicated here:
 * they already have their own homes and a second copy would be a second answer
 * to "is it configured?".
 *
 * `unique(customer_id, integration)` — one configuration per integration per
 * tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_integration_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            $table->string('integration', 32);   // google_sheets|salesforce|hubspot|slack|meta_pixel
            $table->boolean('enabled')->default(false);
            $table->text('credentials')->nullable(); // encrypted json
            $table->json('settings')->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'integration'], 'customer_integrations_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_integration_credentials');
    }
};
