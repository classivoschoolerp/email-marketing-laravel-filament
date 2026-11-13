<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-server warmup state (spec §2.9.3 F-2.9.3-004/011): one row per
 * (plan, delivery server). Two servers sharing a plan ramp INDEPENDENTLY —
 * each keeps its own current_day, start date and pause state, so adding a
 * server to a running plan starts it at day 1.
 *
 * delivery_servers.warmup_plan_id (WP-14, no FK by design) stays the
 * assignment pointer; this table carries the state that assignment produces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warmup_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warmup_plan_id')->constrained('warmup_plans')->cascadeOnDelete();
            $table->foreignId('delivery_server_id')->constrained('delivery_servers')->cascadeOnDelete();

            $table->unsignedSmallInteger('current_day')->default(1);
            $table->string('status', 16)->default('active'); // active | paused | completed
            $table->date('started_on')->nullable();
            $table->date('last_advanced_on')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->unique(['warmup_plan_id', 'delivery_server_id']);
            $table->index('delivery_server_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warmup_progress');
    }
};
