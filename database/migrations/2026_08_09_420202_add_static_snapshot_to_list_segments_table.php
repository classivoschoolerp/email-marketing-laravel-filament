<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-42 — static/frozen segments (spec §2.2.10 F-2.2.10-013).
 *
 * A segment stays dynamic (F-2.2.10-012) unless `is_static` is set. Freezing
 * materialises the current matches into list_segment_snapshot_members through
 * a queued job; `snapshot_taken_at` is the proof that a snapshot exists and
 * `snapshot_count` is the frozen size shown in the UI.
 *
 * A static segment whose snapshot has never been taken matches NOTHING —
 * never the live set — mirroring D-90: a broken/absent reference must never
 * widen an audience into an unintended send.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('list_segments', function (Blueprint $table): void {
            $table->boolean('is_static')->default(false)->after('match_type');
            $table->timestamp('snapshot_taken_at')->nullable()->after('is_static');
            $table->unsignedBigInteger('snapshot_count')->default(0)->after('snapshot_taken_at');
        });
    }

    public function down(): void
    {
        Schema::table('list_segments', function (Blueprint $table): void {
            $table->dropColumn(['is_static', 'snapshot_taken_at', 'snapshot_count']);
        });
    }
};
