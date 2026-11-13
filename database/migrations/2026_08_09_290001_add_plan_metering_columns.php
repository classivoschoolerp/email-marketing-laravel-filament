<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-3.26.1-011 — metered plan billing models (WP-27C).
 *
 * `per-list` and `per-send` were stored but never metered. They now price
 * like every other model (integer minor units, no floats anywhere):
 *
 *   amount = base_price + max(0, units - metered_included) * unit price
 *
 * price_plans gains:
 * - metered_unit_price   per-unit price in the plan's base currency
 * - metered_unit_prices  json ISO => minor units, the multi-currency
 *                        override map for the unit price, mirroring
 *                        `prices` for base_price (F-3.26.1-012). A plan is
 *                        still only purchasable in a currency it prices.
 * - metered_included     units the base price already covers (allowance)
 * - metered_max          hard cap on units; 0 = unlimited (D-23). For
 *                        `per-list` PlanApplicator mirrors this onto the
 *                        customer_limits `max_lists` row so EmailListPolicy
 *                        and the API enforce it live (D-43 override chain).
 *
 * orders gains the metered window that was billed, so the accrual is
 * auditable and, crucially, gapless: the next period starts at the
 * `metered_to` of the last COMPLETED renewal order, which means a failed or
 * dunned renewal never loses or double-bills usage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_plans', function (Blueprint $table): void {
            $table->unsignedBigInteger('metered_unit_price')->default(0)->after('prices');
            $table->json('metered_unit_prices')->nullable()->after('metered_unit_price');
            $table->unsignedBigInteger('metered_included')->default(0)->after('metered_unit_prices');
            $table->unsignedBigInteger('metered_max')->default(0)->after('metered_included');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('metered_units')->nullable()->after('is_renewal');
            $table->timestamp('metered_from')->nullable()->after('metered_units');
            $table->timestamp('metered_to')->nullable()->after('metered_from');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['metered_units', 'metered_from', 'metered_to']);
        });

        Schema::table('price_plans', function (Blueprint $table): void {
            $table->dropColumn([
                'metered_unit_price',
                'metered_unit_prices',
                'metered_included',
                'metered_max',
            ]);
        });
    }
};
