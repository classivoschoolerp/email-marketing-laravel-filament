<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-46 — execution history for a saved report (spec §4.2 F-4.2-010).
 *
 * Every run is recorded, INCLUDING the ones a guard refused: a saved report
 * whose range grew past the scan ceiling must show the operator why it stopped
 * producing numbers, not silently show nothing. `error` therefore holds the
 * refusal message verbatim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('report_definition_id')->constrained('report_definitions')->cascadeOnDelete();

            // running|completed|failed
            $table->string('status', 16)->default('running');
            // preview|manual|export|scheduled
            $table->string('trigger', 16)->default('manual');

            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->text('error')->nullable();

            $table->unsignedBigInteger('export_job_id')->nullable()->index();

            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['report_definition_id', 'created_at'], 'report_runs_definition_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_runs');
    }
};
