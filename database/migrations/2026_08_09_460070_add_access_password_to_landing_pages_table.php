<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-46 — password-protected landing pages (spec §1.8 F-1.8-006).
 *
 * The column stores a BCRYPT HASH, never the plaintext: a landing-page gate
 * password is a credential like any other, and an operator with database
 * read access must not be able to read one back. `null` / empty means the
 * page is public, which is what keeps every existing page unchanged.
 *
 * There is deliberately no separate "protected" boolean — a second flag can
 * disagree with the column and would let a page claim protection while
 * holding no password (or the reverse).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landing_pages', function (Blueprint $table): void {
            $table->string('access_password', 255)->nullable()->after('robots');
        });
    }

    public function down(): void
    {
        Schema::table('landing_pages', function (Blueprint $table): void {
            $table->dropColumn('access_password');
        });
    }
};
