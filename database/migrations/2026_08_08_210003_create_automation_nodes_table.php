<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-21 — the PUBLISHED, executable graph (spec §2.4.2). Node linkage is an
 * adjacency map on each row: `edges` json = {branchKey: childUid} — 'next'
 * for linear nodes, 'yes'/'no' for if/else, 'case:N'/'default' for splits,
 * 'a'/'b'/… for percentage splits, 'event'/'timeout' for wait nodes. The
 * trigger node is the root (never a target of any edge).
 *
 * `uid` is the stable canvas-assigned node id — republish updates rows in
 * place by uid so runs and per-node stats survive edits. `entered_count` is
 * a cheap "reached" counter incremented by the engine each time a run
 * arrives at the node (source of truth for exact stats: automation_run_logs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_nodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('automation_id')->constrained('automations')->cascadeOnDelete();
            $table->string('uid', 36);
            $table->string('type', 48);
            $table->string('label')->nullable();
            $table->json('config')->nullable();
            $table->json('edges')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->unsignedInteger('entered_count')->default(0);
            $table->timestamps();

            $table->unique(['automation_id', 'uid']);
            $table->index(['automation_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_nodes');
    }
};
