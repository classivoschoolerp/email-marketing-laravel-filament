<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-21 — one row per subscriber journey through an automation.
 *
 * Statuses: active | completed | exited | failed. `wake_at` is the delay /
 * timeout alarm the every-minute tick polls; `waiting_for` parks the run on
 * a named event (wait-for-event nodes, delay-until-event, goal re-checks);
 * `resume_branch` records HOW a parked run was woken ('event' vs timeout) so
 * the re-entrant node executor picks the right branch. `node_entered_at`
 * anchors delay arithmetic (due = node_entered_at + duration) making
 * advancement re-runs deterministic and idempotent.
 *
 * Re-entry (F-2.4.2-043): `entry_number` increments per re-entry so
 * unique(automation_id, subscriber_id, entry_number) blocks duplicate
 * concurrent entries at the DB level while still allowing re-entry when the
 * automation's rules do (Enroller enforces once/multiple/cooldown).
 *
 * `conversion_value` (minor units, D — goal node's configured value) sums
 * into the report's "revenue attributed" (F-2.4.3-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('automation_id')->constrained('automations')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('subscriber_id')->constrained('subscribers')->cascadeOnDelete();
            $table->foreignId('current_node_id')->nullable()->constrained('automation_nodes')->nullOnDelete();
            $table->string('status', 16)->default('active'); // active|completed|exited|failed
            $table->unsignedInteger('entry_number')->default(1);
            $table->unsignedInteger('version')->default(0);
            $table->timestamp('wake_at')->nullable();
            $table->string('waiting_for', 48)->nullable();
            $table->string('resume_branch', 24)->nullable();
            $table->timestamp('node_entered_at')->nullable();
            $table->timestamp('entered_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('exit_reason', 64)->nullable();
            $table->timestamp('goal_met_at')->nullable();
            $table->unsignedBigInteger('conversion_value')->default(0);
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['automation_id', 'subscriber_id', 'entry_number'], 'automation_runs_entry_unique');
            $table->index(['status', 'wake_at']);
            $table->index(['automation_id', 'status', 'current_node_id'], 'automation_runs_node_status_index');
            $table->index(['subscriber_id', 'status']);
            $table->index(['waiting_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
    }
};
