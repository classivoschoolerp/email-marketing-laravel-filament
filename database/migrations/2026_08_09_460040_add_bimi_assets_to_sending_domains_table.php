<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BIMI logo + VMC per sending domain (spec §2.9.7 F-2.9.7-009, WP-46).
 *
 * WP-33 added bimi_verified / bimi_checked_at but nothing that could BUILD a
 * BIMI record: the `l=` (logo) and `a=` (Verified Mark Certificate) URLs are
 * per-domain values the customer owns, so they live on the row next to the
 * DKIM material. `bimi_findings` caches the last per-tag assessment so the
 * panel can explain WHY a record is not usable without re-running the checks
 * inside a web request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sending_domains', function (Blueprint $table): void {
            $table->string('bimi_logo_url', 512)->nullable()->after('bimi_checked_at');
            $table->string('bimi_vmc_url', 512)->nullable()->after('bimi_logo_url');
            $table->json('bimi_findings')->nullable()->after('bimi_vmc_url');
        });
    }

    public function down(): void
    {
        Schema::table('sending_domains', function (Blueprint $table): void {
            $table->dropColumn(['bimi_logo_url', 'bimi_vmc_url', 'bimi_findings']);
        });
    }
};
