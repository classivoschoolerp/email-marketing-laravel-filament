<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-3.26.1-009 — metered / overage pricing per 1,000 emails (§3.26.1, WP-46).
 *
 * Two separate things the spec row asks for, kept as separate columns
 * because they are separate products:
 *
 * 1. BLOCK SIZE for the metered models (`per-list` / `per-send`).
 *    WP-27C priced metered plans strictly PER UNIT. `metered_unit_size` is
 *    how many units one priced block contains — 1 (the default, byte-for-byte
 *    the pre-WP-46 behaviour) or 1000 for "per 1,000 emails".
 *
 *    ROUNDING DIRECTION — decided, documented, tested: a partial block is a
 *    WHOLE block (ceiling). blocks = ceil(billable / size), computed as
 *    intdiv(billable + size - 1, size) so it stays integer arithmetic. One
 *    email over the allowance on a per-1,000 plan therefore costs one full
 *    block. Flooring would make the last partial block free on every single
 *    invoice, which is the operator silently giving away up to 999 emails a
 *    month per tenant; charging the partial block is the industry norm
 *    (Mailgun, SendGrid, SES all bill started blocks) and is visible on the
 *    invoice as an explicit block quantity.
 *
 * 2. OVERAGE on a FIXED-price plan. Distinct from a metered plan: the
 *    customer buys a flat monthly price that INCLUDES `overage_included`
 *    emails, and anything beyond that is billed at `overage_unit_price` per
 *    `overage_unit_size` (default 1000) emails, in arrears, on renewal.
 *    Same ceiling rule, same currency contract as everything else — the
 *    `overage_unit_prices` json is the explicit {ISO => minor units}
 *    override map and a currency without an entry meters nothing rather
 *    than converting (F-3.26.1-012).
 *
 *    The usage window is the one D-93 already defines: it lives on the
 *    order (`metered_from` / `metered_to`, reused verbatim) and advances
 *    only when a renewal order COMPLETES, so a dunned renewal neither loses
 *    nor double-bills usage. `overage_included` is a PRICING threshold, not
 *    a quota — it is deliberately NOT written to customer_limits, because
 *    the whole point of overage is that sending past it is allowed and paid
 *    for. `overage_unit_price = 0` means overage is free (D-23 spirit:
 *    nothing is charged) and `overage_enabled = false` disables it entirely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_plans', function (Blueprint $table): void {
            $table->unsignedBigInteger('metered_unit_size')->default(1)->after('metered_max');

            $table->boolean('overage_enabled')->default(false)->after('metered_unit_size');
            $table->unsignedBigInteger('overage_included')->default(0)->after('overage_enabled');
            $table->unsignedBigInteger('overage_unit_price')->default(0)->after('overage_included');
            $table->json('overage_unit_prices')->nullable()->after('overage_unit_price');
            $table->unsignedBigInteger('overage_unit_size')->default(1000)->after('overage_unit_prices');
        });
    }

    public function down(): void
    {
        Schema::table('price_plans', function (Blueprint $table): void {
            $table->dropColumn([
                'metered_unit_size',
                'overage_enabled',
                'overage_included',
                'overage_unit_price',
                'overage_unit_prices',
                'overage_unit_size',
            ]);
        });
    }
};
