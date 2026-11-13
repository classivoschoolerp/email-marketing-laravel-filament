<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-16 — §2.9.12 bulk verification runs (F-2.9.12-008/009). Mirrors the
 * import_jobs progress pattern: a queued VerifyBatchJob chunks through the
 * target and bumps `processed` + per-status counters so the Filament page
 * can poll a progress bar. Doubles as the per-list verification history
 * (query by list_id, F-2.9.12-009) and feeds the §2.9.12-010 "verified in
 * the last N days" campaign preflight check.
 *
 * target ∈ list|segment|subscribers|single; status ∈ pending|running|
 * completed|failed. `result_path` = downloadable result CSV on the local
 * disk. `credits_used` mirrors the customer credit counter consumption
 * (F-2.9.12-008/011).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_jobs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('list_id')->nullable()->constrained('lists')->cascadeOnDelete();
            $table->foreignId('segment_id')->nullable()->constrained('list_segments')->cascadeOnDelete();
            $table->string('target', 16)->default('list');
            // target=subscribers → the picked ids; target=single → the address.
            $table->json('subscriber_ids')->nullable();
            $table->string('single_email')->nullable();
            $table->string('provider', 32);
            $table->string('auto_action', 16)->default('tag');
            $table->string('status', 16)->default('pending')->index();

            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('deliverable_count')->default(0);
            $table->unsignedInteger('undeliverable_count')->default(0);
            $table->unsignedInteger('risky_count')->default(0);
            $table->unsignedInteger('unknown_count')->default(0);
            $table->unsignedInteger('credits_used')->default(0);

            $table->string('result_path')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['list_id', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_jobs');
    }
};
