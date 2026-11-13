<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-42 — per-subscriber send-frequency preference for the public preference
 * center (spec §1.5 F-1.5-012).
 *
 * `send_frequency` holds an App\Enums\SendFrequency value; NULL means the
 * subscriber never expressed a preference and is treated exactly like
 * `every_send`. `frequency_paused_until` backs the "pause my emails for N
 * days" choice — a timestamp rather than a day count so the pause expires by
 * itself with no cron job.
 *
 * Plain nullable VARCHAR + TIMESTAMP: MySQL 8 and SQLite both take this with
 * no driver-specific syntax (D-03).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscribers', function (Blueprint $table): void {
            $table->string('send_frequency', 16)->nullable()->after('unsubscribe_reason');
            $table->timestamp('frequency_paused_until')->nullable()->after('send_frequency');
        });
    }

    public function down(): void
    {
        Schema::table('subscribers', function (Blueprint $table): void {
            $table->dropColumn(['send_frequency', 'frequency_paused_until']);
        });
    }
};
