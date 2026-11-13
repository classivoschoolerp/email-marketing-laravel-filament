<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-08 — subscriber imports (spec §2.2.7). One row per import run; the
 * queued RunImportJob updates the counters in place so the Filament poll
 * widget can render live progress + ETA (F-2.2.7-011/012). `options` carries
 * the per-source configuration (mapping, delimiter, duplicate strategy, …);
 * database credentials inside it are encrypted at the model layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_jobs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('list_id')->constrained('lists')->cascadeOnDelete();

            // csv|paste|url|list|database (F-2.2.7-001..005)
            $table->string('source', 16);
            // pending|processing|completed|completed_with_errors|failed
            $table->string('status', 32)->default('pending');

            $table->json('options')->nullable();

            // Uploaded/downloaded CSV on the local disk (customers/{uid}/imports/).
            $table->string('file_path')->nullable();
            // Downloadable error report CSV (F-2.2.7-012).
            $table->string('error_file_path')->nullable();

            // Live progress counters (F-2.2.7-011/012).
            $table->unsignedInteger('total_rows')->nullable();
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);

            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'list_id']);
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_jobs');
    }
};
