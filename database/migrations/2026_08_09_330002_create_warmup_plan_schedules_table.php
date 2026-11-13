<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-generated, hand-editable day-by-day schedule (spec §2.9.3
 * F-2.9.3-002). One row per plan day; `allowance` is the hard per-day send
 * budget applied to EVERY server on the plan (per-server state lives in
 * warmup_progress, F-2.9.3-011).
 *
 * `edited` marks a day an operator changed by hand: regeneration preserves
 * those rows unless it is explicitly forced, so re-saving a plan never
 * silently discards manual tuning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warmup_plan_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warmup_plan_id')->constrained('warmup_plans')->cascadeOnDelete();

            $table->unsignedSmallInteger('day');       // 1-based
            $table->unsignedInteger('allowance');      // emails permitted on this day
            $table->boolean('edited')->default(false);

            $table->timestamps();

            $table->unique(['warmup_plan_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warmup_plan_schedules');
    }
};
