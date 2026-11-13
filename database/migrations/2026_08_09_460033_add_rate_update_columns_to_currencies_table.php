<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-3.26.5-003 — automatic exchange-rate updates (§3.26.5, WP-46).
 *
 * `currencies.exchange_rate` was write-once-by-hand. It can now be updated
 * from a provider, so each row records the PROVENANCE of the number it is
 * carrying:
 *
 * - rates_updated_at  when the rate was last written by the updater. NULL
 *                     means it has only ever been set by hand, which is a
 *                     meaningful state and not an error.
 * - rate_source       the ExchangeRateRegistry key that wrote it.
 * - rate_alert        set when an update was REFUSED because the new rate
 *                     moved further than the operator's tolerance. The old
 *                     rate is kept and this column says why, so a bad feed
 *                     shows up in the admin UI instead of silently
 *                     repricing the catalogue.
 *
 * The rate itself stays decimal(18,8) and is always written as a STRING —
 * see RateMath. It is never a float at any point in the pipeline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('currencies', function (Blueprint $table): void {
            $table->timestamp('rates_updated_at')->nullable()->after('exchange_rate');
            $table->string('rate_source', 32)->nullable()->after('rates_updated_at');
            $table->string('rate_alert', 255)->nullable()->after('rate_source');
        });
    }

    public function down(): void
    {
        Schema::table('currencies', function (Blueprint $table): void {
            $table->dropColumn(['rates_updated_at', 'rate_source', 'rate_alert']);
        });
    }
};
