<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-46 — let an existing §4.2 F-4.2-011 schedule row mail a SAVED custom
 * report (F-4.2-010) instead of the account PDF.
 *
 * Deliberately one nullable column on the existing table rather than a second
 * scheduler: `reports:send-scheduled`, its hourly cadence, its idempotency key
 * and its recipient rules stay the single owner of "a report goes out on a
 * timetable". NULL keeps today's behaviour byte-for-byte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_reports', function (Blueprint $table): void {
            $table->foreignId('report_definition_id')
                ->nullable()
                ->constrained('report_definitions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_reports', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('report_definition_id');
        });
    }
};
