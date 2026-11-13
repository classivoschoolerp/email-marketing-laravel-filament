<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-4.3-003 broadcast announcements from admin to all customers (WP-39).
 *
 * WP-18's `announcements` table already drives the dismissible banner on
 * the customer dashboard. Broadcasting pushes the same announcement into
 * every tenant's notification bell as a database notification. The columns
 * make that a one-shot, auditable action rather than a repeatable button:
 * `broadcast_at` stamps when it happened and `broadcast_count` how many
 * customers were reached, so a second click cannot spam the install.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table): void {
            $table->timestamp('broadcast_at')->nullable()->after('dismissible');
            $table->unsignedInteger('broadcast_count')->default(0)->after('broadcast_at');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table): void {
            $table->dropColumn(['broadcast_at', 'broadcast_count']);
        });
    }
};
