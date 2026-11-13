<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three-tier role model on top of spatie/laravel-permission (spec §0.3, D-06):
 * - scope: system | global | customer
 * - customer_id: owning tenant for customer-scoped roles (null otherwise)
 * - locked: built-in system roles cannot be edited/deleted (enforced by RolePolicy)
 * - disabled_at: soft-disable that preserves role assignments (F-0.3-010)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->string('scope', 16)->default('global')->index()->after('guard_name');
            // FK constraint is a no-op on SQLite ALTER (column still added); real
            // constraint applies on MySQL 8 (D-03).
            $table->foreignId('customer_id')->nullable()->after('scope')
                ->constrained('customers')->cascadeOnDelete();
            $table->boolean('locked')->default(false)->after('customer_id');
            $table->timestamp('disabled_at')->nullable()->after('locked');
        });

        Schema::table('roles', function (Blueprint $table): void {
            // Per-customer roles may reuse a name another tenant already uses
            // (F-0.3-009). NULL customer_id rows (system/global) are additionally
            // guarded at the application layer (App\Models\Role::create) because
            // SQL treats NULLs as distinct in unique indexes.
            $table->dropUnique(['name', 'guard_name']);
            $table->unique(['name', 'guard_name', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropUnique(['name', 'guard_name', 'customer_id']);
            $table->unique(['name', 'guard_name']);
            $table->dropColumn(['scope', 'customer_id', 'locked', 'disabled_at']);
        });
    }
};
