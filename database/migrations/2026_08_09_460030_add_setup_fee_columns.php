<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-3.26.1-008 — one-off setup fee per price plan (§3.26.1, WP-46).
 *
 * A setup fee is charged ONCE, on the customer's first paid order for a
 * plan. It is never charged on a renewal. Money is integer minor units
 * everywhere (docs/ARCHITECTURE.md) and, exactly like `base_price`/`prices`, it is
 * never exchange-rate converted: `setup_fees` is the explicit per-currency
 * override map keyed by ISO 4217 code (F-3.26.1-012 contract). A currency
 * the plan sells in but does not price a setup fee for charges NO setup fee
 * rather than a guessed one.
 *
 * price_plans gains:
 * - setup_fee            minor units in the plan's own currency; 0 = none
 * - setup_fees           json {ISO => minor units} override map
 * - setup_fee_on_change  DEFAULT FALSE. An upgrade/downgrade between plans
 *                        in the same catalogue is a continuation of an
 *                        existing relationship, not a new installation, so
 *                        the default is not to re-charge. Operators selling
 *                        genuinely per-plan onboarding flip it on per plan.
 *
 * orders gains `setup_fee`: the NET (pre-discount, pre-tax) setup-fee line
 * amount actually billed by that order. It exists so Proration can exclude
 * it from the credit base — a setup fee buys a one-time service that has
 * already been delivered, so upgrading must never refund it (D-110 keeps
 * every division an intdiv and never credits more than was paid).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_plans', function (Blueprint $table): void {
            $table->unsignedBigInteger('setup_fee')->default(0)->after('metered_max');
            $table->json('setup_fees')->nullable()->after('setup_fee');
            $table->boolean('setup_fee_on_change')->default(false)->after('setup_fees');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('setup_fee')->default(0)->after('metered_to');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('setup_fee');
        });

        Schema::table('price_plans', function (Blueprint $table): void {
            $table->dropColumn(['setup_fee', 'setup_fees', 'setup_fee_on_change']);
        });
    }
};
