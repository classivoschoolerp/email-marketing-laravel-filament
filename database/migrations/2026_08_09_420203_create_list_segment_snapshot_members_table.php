<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-42 — frozen segment membership (spec §2.2.10 F-2.2.10-013).
 *
 * One row per subscriber captured when the segment was frozen. Tenant-owned
 * (customer_id + CustomerScope on the model) and written only by the queued
 * RefreshSegmentSnapshotJob; SegmentQueryBuilder::subscribers() reads it back
 * as a correlated EXISTS so every existing consumer — campaign targeting,
 * SMS, exports, automations — resolves a static segment from the snapshot
 * without knowing this table exists.
 *
 * Log-shaped: created_at only, no updated_at (rows are replaced, never edited).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('list_segment_snapshot_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('list_segment_id')->constrained('list_segments')->cascadeOnDelete();
            $table->foreignId('subscriber_id')->constrained('subscribers')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['list_segment_id', 'subscriber_id'], 'segment_snapshot_member_unique');
            $table->index(['customer_id', 'list_segment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('list_segment_snapshot_members');
    }
};
