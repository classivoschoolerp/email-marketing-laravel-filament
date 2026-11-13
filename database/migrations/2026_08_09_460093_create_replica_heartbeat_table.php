<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-46 — replication-lag heartbeat (spec §4.6 F-4.6-007).
 *
 * One row, written on the PRIMARY by `php artisan replica:heartbeat` and read
 * back THROUGH the replica by {@see \App\Support\Database\ReplicaLag}. The
 * difference between the stamp and now is how long a freshly written row takes
 * to become visible to a reader — the number that decides whether a report may
 * be served off the replica at all.
 *
 * `beat_at` is a unix timestamp, not a datetime: an integer needs no timezone
 * interpretation and subtracts identically on MySQL and SQLite, which keeps
 * D-03's "no driver-specific SQL" rule intact.
 *
 * The table exists on every install and stays empty on installs with no
 * replica. An empty table reads as "lag unknown", which routes to the primary
 * — the safe answer, and the reason nothing breaks when the command is never
 * scheduled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('replica_heartbeat', function (Blueprint $table): void {
            // Fixed single row; the command upserts id=1 rather than growing
            // a log nobody reads.
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('beat_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('replica_heartbeat');
    }
};
