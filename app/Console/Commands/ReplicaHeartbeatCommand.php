<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Database\ReplicaLag;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `replica:heartbeat` (spec §4.6 F-4.6-007, WP-46).
 *
 * Stamps one row on the PRIMARY. {@see ReplicaLag} reads that row back through
 * the replica; the difference is real end-to-end replication delay, measured
 * through the data path itself rather than asked of the server.
 *
 * Run it on the primary, once a minute, from cron — NOT from the Laravel
 * scheduler, because `routes/console.php` is owned by another work package and
 * a replica is an infrastructure choice the scheduler should not assume. The
 * exact crontab line is in docs/deploy-mysql.md.
 *
 * Doing nothing is a supported state: an install with no replica never runs
 * this, the row stays absent, lag reads as "unknown", and every report is
 * served from the primary.
 */
class ReplicaHeartbeatCommand extends Command
{
    protected $signature = 'replica:heartbeat
        {--connection= : write to this connection instead of the default (must be the PRIMARY)}';

    protected $description = 'Stamp the replication heartbeat row on the primary (§4.6 F-4.6-007).';

    public function handle(): int
    {
        $table = (string) config('emp-replica.lag.heartbeat_table', 'replica_heartbeat');

        $connection = $this->option('connection');
        $connection = is_string($connection) && $connection !== '' ? $connection : null;

        $db = DB::connection($connection);

        if (! $db->getSchemaBuilder()->hasTable($table)) {
            $this->error(__('ops.replica.heartbeat_missing_table', ['table' => $table]));

            return self::FAILURE;
        }

        $now = time();

        // upsert(): one statement, no read-then-write race between two cron
        // ticks that overlap, and portable across MySQL and SQLite (D-03).
        $db->table($table)->upsert([['id' => 1, 'beat_at' => $now]], ['id'], ['beat_at']);

        // The routing decision caches its probe; a fresh beat should be
        // visible to the next report rather than up to a TTL later.
        ReplicaLag::flush((string) config('emp-replica.connection', 'mysql_read'));

        $this->line(__('ops.replica.heartbeat_written', ['at' => (string) $now]));

        return self::SUCCESS;
    }
}
