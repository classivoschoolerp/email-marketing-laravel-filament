<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-21 — automations / journey builder (spec §2.4). One row per journey.
 *
 * `canvas` is the DRAFT graph exactly as edited in the builder (json:
 * {nodes: [{uid, type, label, config, edges}]}). Publishing validates it and
 * materializes it into `automation_nodes` (the executed graph) while
 * `version` increments and a snapshot lands in `automation_versions`
 * (F-2.4.2-002). `trigger_type` / `trigger_config` / `email_list_id` are
 * denormalized copies of the trigger node for index filtering (F-2.4.1-002)
 * and cheap tick-side polling.
 *
 * Counters (entered/active/completed/converted) are maintained by the engine
 * (Enroller / RunAdvancer) transactionally; the report page recomputes from
 * `automation_runs` where exactness matters (D-64 spirit).
 *
 * Re-entry rules (F-2.4.2-043): `once` (default), `multiple`, `cooldown`
 * (+ `reentry_cooldown_minutes`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('name');
            $table->string('status', 16)->default('draft'); // draft|active|paused
            $table->string('trigger_type', 48)->nullable();
            $table->json('trigger_config')->nullable();
            $table->foreignId('email_list_id')->nullable()->constrained('lists')->nullOnDelete();
            $table->json('canvas')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->string('reentry_mode', 16)->default('once'); // once|multiple|cooldown
            $table->unsignedInteger('reentry_cooldown_minutes')->nullable();
            $table->timestamp('segment_seeded_at')->nullable();
            $table->unsignedInteger('entered_count')->default(0);
            $table->unsignedInteger('active_count')->default(0);
            $table->unsignedInteger('completed_count')->default(0);
            $table->unsignedInteger('converted_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'status']);
            $table->index(['customer_id', 'trigger_type']);
            $table->index(['status', 'trigger_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automations');
    }
};
