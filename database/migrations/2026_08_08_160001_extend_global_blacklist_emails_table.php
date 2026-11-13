<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-16 — full §3.17 blacklist module on top of the WP-09 forward-base.
 *
 * `is_domain` marks domain-level entries (F-3.17-006): the `email` column
 * then stores the bare lower-case domain ("example.com") and matching
 * covers every address at that domain. Exact-address rows keep the full
 * address. The existing unique index on `email` still applies (one row per
 * address or per domain).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('global_blacklist_emails', function (Blueprint $table): void {
            $table->boolean('is_domain')->default(false)->after('email')->index();
        });
    }

    public function down(): void
    {
        Schema::table('global_blacklist_emails', function (Blueprint $table): void {
            $table->dropColumn('is_domain');
        });
    }
};
