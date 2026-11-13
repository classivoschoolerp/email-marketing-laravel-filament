<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Detailed per-day warmup usage (spec §2.9.3 F-2.9.3-012: sent / failed /
 * reserved). One row per (progress, calendar date).
 *
 *  - `allowance` snapshots the schedule row applied on that date, so editing
 *    a plan never rewrites history.
 *  - `reserved` is capacity handed out by ServerPicker but not yet settled —
 *    it is what makes the cap hard under concurrency (F-2.9.3-009).
 *  - `sent` / `failed` mirror delivery_server_usage (the send path's single
 *    source of truth); `settled` records how much of that total has already
 *    released a reservation, so `reserved` drains as outcomes land.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warmup_daily_usage', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warmup_progress_id')->constrained('warmup_progress')->cascadeOnDelete();

            $table->date('date');
            $table->unsignedSmallInteger('day');    // plan day this date ran
            $table->unsignedInteger('allowance');

            $table->unsignedInteger('sent')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('reserved')->default(0);
            $table->unsignedInteger('settled')->default(0);

            // Traffic the server had already put through on this date BEFORE
            // the warmup day row was opened; subtracted from the mirror so a
            // plan attached mid-day does not inherit that history.
            $table->unsignedInteger('baseline_sent')->default(0);
            $table->unsignedInteger('baseline_failed')->default(0);

            // Last time capacity was reserved — stale reservations (a pick
            // that never reached the driver) expire after the configured TTL.
            $table->timestamp('reserved_at')->nullable();

            $table->timestamps();

            $table->unique(['warmup_progress_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warmup_daily_usage');
    }
};
