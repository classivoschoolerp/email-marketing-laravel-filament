<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user theme selection (spec §6.3 F-6.3-002).
 *
 * Each admin and each customer picks their OWN theme; the install-wide
 * SkinSettings value is only the fallback. Stored on the account row exactly
 * the way WP-20 stored `locale` (F-6.5-005), and resolved by
 * App\Services\Themes\ThemeResolver on every panel render.
 *
 * NULL = "follow the installation / brand default" — that is the seeded
 * state, so nothing changes for existing accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('theme', 64)->nullable()->after('locale');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->string('theme', 64)->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('theme');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('theme');
        });
    }
};
