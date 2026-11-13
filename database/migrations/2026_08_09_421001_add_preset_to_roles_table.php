<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-0.3-003 customer roles (Owner / Admin / Editor / Analyst / Read-only),
 * WP-42.
 *
 * The five named roles are provisioned per tenant as ordinary customer-scoped
 * roles (D-06/D-17), so everything already built on top of `roles` — the
 * RoleChecker, the per-member grant/deny overlay (F-2.15.4-002), the
 * disable-preserves-assignments rule (F-0.3-010) — keeps working untouched.
 *
 * `preset` is the only new thing: a stable key identifying which named preset
 * a role was created from. Matching on the NAME would break the moment a
 * tenant renames "Editor" to "Content" — the row would be re-provisioned and
 * the account would end up with two Editors.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->string('preset', 32)->nullable()->after('locked');
        });

        Schema::table('roles', function (Blueprint $table): void {
            $table->index(['customer_id', 'preset'], 'roles_customer_preset_index');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropIndex('roles_customer_preset_index');
        });

        Schema::table('roles', function (Blueprint $table): void {
            $table->dropColumn('preset');
        });
    }
};
