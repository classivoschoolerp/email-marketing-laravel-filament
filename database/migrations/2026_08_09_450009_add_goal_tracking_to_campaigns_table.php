<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-45 — goal tracking / conversion pixel on the §2.3.4 wizard step 3
 * (spec F-2.3.4-024).
 *
 * Typed columns on `campaigns`, per D-54 (campaign options are columns, not a
 * key-value bag; only list-valued options are json).
 *
 * `goal_tracking_enabled` is what makes the click redirect append the
 * `emp_cid` / `emp_sid` / `emp_sig` triple to the destination URL — the
 * merchant-side site-tracking script persists that triple in a first-party
 * cookie and replays it on the thank-you page. Off by default, so a campaign
 * that does not opt in pays nothing on the hottest endpoint in the product.
 *
 * `goal_value` is the default conversion value in integer MINOR UNITS, used
 * when the merchant's pixel reports a conversion without a value (a signup
 * goal, typically). `goal_window_days` bounds attribution for this campaign;
 * 0 falls back to `emp-ecommerce.attribution.window_days`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->boolean('goal_tracking_enabled')->default(false)->after('utm_params');
            $table->string('goal_type', 16)->default('purchase')->after('goal_tracking_enabled'); // purchase|signup|custom
            $table->unsignedBigInteger('goal_value')->default(0)->after('goal_type');
            $table->unsignedSmallInteger('goal_window_days')->default(0)->after('goal_value');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn([
                'goal_tracking_enabled',
                'goal_type',
                'goal_value',
                'goal_window_days',
            ]);
        });
    }
};
