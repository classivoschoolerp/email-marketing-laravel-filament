<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-2.3.8-018 "Export any report to CSV/XLSX" (WP-26): the chosen output
 * format travels on the export row so the queued runner writes the same
 * format the customer picked, and the download controller can pick the
 * right content type from the stored file.
 *
 * Existing rows default to 'csv' — the format every export written before
 * this migration actually is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('export_jobs', function (Blueprint $table): void {
            $table->string('format', 8)->default('csv')->after('scope');
        });
    }

    public function down(): void
    {
        Schema::table('export_jobs', function (Blueprint $table): void {
            $table->dropColumn('format');
        });
    }
};
