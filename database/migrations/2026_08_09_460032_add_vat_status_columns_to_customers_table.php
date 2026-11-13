<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-3.26.6-003 — make `customers.company_vat` load-bearing (§3.26.6, WP-46).
 *
 * The column has existed since 2026_08_08_020004 but nothing ever read it:
 * it was captured on the profile and printed on the invoice. Zero-rating an
 * invoice on the strength of a string somebody typed is not acceptable, so
 * the number now carries its VERIFICATION STATE with it:
 *
 * - vat_status      none | invalid_format | valid | invalid | unverified
 *                   (VatCheckResult constants). `unverified` is a first
 *                   class state, NOT a synonym for valid: it means VIES was
 *                   unreachable, disabled, or does not cover that country,
 *                   and it never zero-rates an invoice.
 *                   NULLABLE ON PURPOSE, with no default: NULL means "never
 *                   checked", which is what every pre-WP-46 row is. Giving
 *                   it a default would assert a verification state about
 *                   rows nobody has ever looked at; VatNumberService
 *                   derives `none`/`unverified` from the stored number
 *                   instead, and neither zero-rates anything.
 * - vat_checked_at  when the status was established, so the UI can say how
 *                   stale it is and an operator can re-run the check.
 * - vat_country     the country PREFIX the number belongs to (EL for
 *                   Greece, not GR). Stored separately from
 *                   `company_country` because the number's own prefix is
 *                   the authority on which member state registered it.
 * - vat_trader_name the name VIES returned, kept as evidence of the check.
 *
 * `company_vat` itself is stored NORMALIZED from now on: upper-case, no
 * spaces or punctuation, prefix included (EuVat::canonical). Existing rows
 * are left untouched — the value is re-normalized the next time it is saved
 * or checked, and an un-normalized legacy value simply reads as unverified,
 * which is the safe direction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('vat_status', 16)->nullable()->after('company_vat');
            $table->timestamp('vat_checked_at')->nullable()->after('vat_status');
            $table->string('vat_country', 2)->nullable()->after('vat_checked_at');
            $table->string('vat_trader_name', 191)->nullable()->after('vat_country');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['vat_status', 'vat_checked_at', 'vat_country', 'vat_trader_name']);
        });
    }
};
