<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-08 — subscriber exports (spec §2.2.8). One row per export run; doubles
 * as the export history (F-2.2.8-005). Files live on the local (private)
 * disk at customers/{uid}/exports/{uuid}.csv and are served only through the
 * signed, policy-checked download route; expires_at implements the retention
 * window from §3.25.2 (exports:prune deletes past it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_jobs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('list_id')->nullable()->constrained('lists')->cascadeOnDelete();

            // all|filtered|segment|selected (F-2.2.8-001)
            $table->string('scope', 16);
            // Chosen columns: standard keys + "field:TAG" entries (F-2.2.8-002).
            $table->json('columns');
            // Filter payload for scope=filtered (status/source/search/date range).
            $table->json('filters')->nullable();
            $table->foreignId('list_segment_id')->nullable()->constrained('list_segments')->nullOnDelete();
            // Explicit ids for scope=selected.
            $table->json('subscriber_ids')->nullable();

            // pending|processing|completed|failed|expired
            $table->string('status', 32)->default('pending');
            $table->string('file_path')->nullable();

            $table->unsignedInteger('total_rows')->nullable();
            $table->unsignedInteger('processed_rows')->default(0);

            $table->text('message')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_jobs');
    }
};
