<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-41 — upgrade history log (spec §6.2 F-6.2-007).
 *
 * One row per attempt, dry runs included. The row is created BEFORE the first
 * destructive step and updated as the run advances, so a run killed mid-flight
 * (worker OOM, PHP timeout, someone pulling the plug during `apply_files`)
 * leaves a `running` row naming the exact step it died on. That row is the
 * entry point for the recovery path documented in docs/upgrades.md — without
 * it an operator would be staring at a half-written install with no record of
 * what was in flight.
 *
 * Install-wide, not tenant-scoped: upgrading is an operator action about the
 * installation itself, so there is deliberately no customer_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upgrade_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('uid', 36)->unique();

            // Who armed it. Nullable: `upgrade:apply` also runs from cron/CLI.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('from_version', 32);
            $table->string('to_version', 32)->nullable();
            $table->string('channel', 32)->nullable();

            // pending | running | success | failed | rolled_back | blocked
            $table->string('status', 24)->index();
            $table->boolean('dry_run')->default(false);

            // Step the run is on / died on (download, apply_files, migrate, …).
            $table->string('current_step', 32)->nullable();

            // Pre-flight results, per-step results, and the file plan
            // (added / replaced / skipped-because-protected).
            $table->json('checks')->nullable();
            $table->json('steps')->nullable();
            $table->json('file_plan')->nullable();

            // Rollback bookkeeping: snapshot directory plus the files created
            // by this run (a rollback deletes those, restores the rest).
            $table->string('snapshot_path', 1024)->nullable();
            $table->json('created_files')->nullable();

            // Backup artefacts produced by the pre-upgrade hook (F-6.2-004).
            $table->string('backup_database', 1024)->nullable();
            $table->string('backup_uploads', 1024)->nullable();

            $table->string('checksum', 128)->nullable();
            $table->unsignedBigInteger('archive_bytes')->nullable();

            $table->text('error')->nullable();
            $table->text('notes')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upgrade_runs');
    }
};
