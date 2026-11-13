<?php

declare(strict_types=1);

use App\Listeners\Security\RecordPasswordChange;
use App\Services\Security\PasswordExpiry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Password expiry support (spec §3.25.15 F-3.25.15-003, WP-22): stamped by
 * {@see RecordPasswordChange} on every password
 * write and read by {@see PasswordExpiry}. NULL means
 * "never recorded" — treated as the account's created_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'customers'] as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'password_changed_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->timestamp('password_changed_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['users', 'customers'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'password_changed_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('password_changed_at');
            });
        }
    }
};
