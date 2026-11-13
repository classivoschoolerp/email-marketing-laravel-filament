<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-4.6-008 archival / cold storage for old logs (WP-42).
 *
 * The index of what `logs:prune` moved out of the hot tables: one row per
 * written archive file. Without it, cold storage is a bucket of opaque
 * filenames and nobody can answer "do we still have last March's delivery
 * logs?" — which is the only question archiving exists to answer.
 *
 * Install-wide, not tenant-scoped: an archive file spans every customer,
 * because the tables it is cut from do (D-05 scopes reads of the live
 * tables, not the operator's backups).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_archives', function (Blueprint $table): void {
            $table->id();

            $table->string('table_name', 64)->index();
            $table->string('disk', 32);
            $table->string('path');

            $table->unsignedBigInteger('rows_count')->default(0);
            $table->unsignedBigInteger('bytes')->default(0);

            // Inclusive bounds of the archived rows' created_at.
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();

            // Set when the file has been read back into its table.
            $table->timestamp('restored_at')->nullable();

            $table->timestamps();

            $table->index(['table_name', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_archives');
    }
};
