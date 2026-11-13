<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-21 — append-only journey trail: every node arrival, action, branch
 * decision, error and exit (spec §2.4.3 per-node stats + error log). Insert
 * only; UPDATED_AT is null on the model. `node_uid`/`node_type` are copied
 * onto the row so history survives node deletion on republish.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_run_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('automation_id')->constrained('automations')->cascadeOnDelete();
            $table->foreignId('run_id')->constrained('automation_runs')->cascadeOnDelete();
            $table->foreignId('node_id')->nullable()->constrained('automation_nodes')->nullOnDelete();
            $table->string('node_uid', 36)->nullable();
            $table->string('node_type', 48)->nullable();
            $table->string('action', 32); // entered|reached|executed|branch|email_sent|error|goal_met|exited|completed|failed|simulated…
            $table->json('detail')->nullable();
            $table->timestamp('created_at');

            $table->index(['automation_id', 'action']);
            $table->index(['run_id']);
            $table->index(['node_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_run_logs');
    }
};
