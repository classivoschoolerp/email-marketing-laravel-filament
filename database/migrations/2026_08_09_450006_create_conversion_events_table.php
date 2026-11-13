<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-45 — the conversion / revenue ledger (spec §2.3.4 F-2.3.4-024,
 * §2.3.8 F-2.3.8-002, §2.12 F-2.12-007, §4.2 F-4.2-009).
 *
 * ONE table, deliberately, because there is exactly one number: a conversion
 * either belongs to a campaign or to an automation, and every reader
 * (CampaignReportService, AbTestStats, AutomationStats, the §4.2 revenue
 * report) must agree to the cent. Two tables would be two answers.
 *
 * It is NOT called `campaign_track_conversions` even though it is shaped like
 * the WP-09 `campaign_track_*` log tables (uid-keyed, created_at only, no
 * tenancy trait on the row itself): an order attributed to an automation has
 * no campaign at all, and naming it after campaigns would have lied.
 *
 * Two origins:
 *   `pixel` — the goal-tracking conversion pixel / JS `emp('conversion')`
 *             call on the merchant's thank-you page (F-2.3.4-024). `value`
 *             is whatever the merchant declared, `external_ref` their order
 *             reference.
 *   `store` — a synced/webhooked order matched to a touch by
 *             RevenueAttributor (F-2.12-007). `value` is the order money.
 *
 * `unique(customer_id, origin, external_ref)` is the idempotency arbiter: a
 * thank-you page refreshed ten times, or an order re-synced nightly, records
 * ONE conversion. Rows without a reference (a conversion with no order id)
 * are exempt by the NULL rule and rely on the pixel's own dedupe key.
 *
 * `value` is integer MINOR UNITS. Never a float, ever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversion_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            $table->unsignedBigInteger('campaign_id')->nullable()->index();
            $table->string('campaign_uid', 36)->nullable()->index();
            $table->unsignedBigInteger('automation_id')->nullable()->index();
            $table->unsignedBigInteger('automation_run_id')->nullable();

            $table->unsignedBigInteger('subscriber_id')->nullable()->index();
            $table->string('subscriber_uid', 36)->nullable()->index();

            $table->unsignedBigInteger('store_connection_id')->nullable();
            $table->unsignedBigInteger('store_order_id')->nullable()->index();

            $table->string('origin', 16);                 // pixel|store
            $table->string('touch', 16)->nullable();      // click|open|automation_email|pixel
            $table->string('external_ref', 191)->nullable();
            $table->string('goal', 32)->nullable();       // purchase|signup|custom

            $table->unsignedBigInteger('value')->default(0);
            $table->char('currency', 3)->default('USD');

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['customer_id', 'origin', 'external_ref'], 'conversion_events_ref_unique');
            $table->index(['campaign_uid', 'subscriber_uid'], 'conversion_events_campaign_sub_idx');
            $table->index(['customer_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversion_events');
    }
};
