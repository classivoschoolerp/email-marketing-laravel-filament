<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-42 — per-import provenance ledger (spec §2.2.7 F-2.2.7-013 "Import
 * history log with re-run/rollback").
 *
 * Rollback has to undo exactly what ONE import run did, so each run records
 * which subscriber rows it created and which it updated. `previous_values`
 * holds the RAW (storage-encoded) `subscriber_field_values` values the
 * updated row carried BEFORE the import overwrote them, keyed by field tag,
 * with null meaning "the row had no value for that field" — so a rollback
 * can restore or delete each value exactly.
 *
 * Rows are bulk-inserted per import batch and dropped once the run has been
 * rolled back, so the table only ever holds provenance for runs that can
 * still be undone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_job_subscribers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('import_job_id')->constrained('import_jobs')->cascadeOnDelete();
            $table->foreignId('subscriber_id')->constrained('subscribers')->cascadeOnDelete();

            // created|updated — the only two outcomes a rollback can undo.
            $table->string('action', 16);

            $table->json('previous_values')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['import_job_id', 'action']);
            $table->index(['customer_id', 'import_job_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_job_subscribers');
    }
};
