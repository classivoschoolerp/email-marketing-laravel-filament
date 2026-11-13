<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Read-replica routing for reporting (spec §4.6 F-4.6-007, WP-46)
|--------------------------------------------------------------------------
| WHY A SEPARATE NAMED CONNECTION, NOT `read`/`write` ON `mysql`
|
| Laravel supports a `read`/`write` array directly on the primary connection.
| Turning it on there load-balances EVERY select the application makes — the
| send path, the tenancy scope, the quota counters, the idempotency lookups —
| onto a machine whose data is, by definition, behind. That is a much larger
| change than "reporting may read a replica", and its failure mode is the
| nastiest one in this codebase: a send job that reads a stale
| `campaign_delivery_logs` row and sends a second copy.
|
| So the split lives on its OWN connection (`mysql_read` by default), and
| nothing uses it until App\Support\Database\ReadReplica::run() deliberately
| points the default connection at it for the duration of one read. Outside
| that wrapper the application talks to the primary exactly as it did before.
|
| The connection itself still carries Laravel's `read`/`write` arrays. That is
| what guarantees the invariant "NEVER route a write": Laravel sends selects to
| the read PDO and every insert/update/delete/statement to the write PDO, so a
| write that happens to occur inside the wrapper lands on the PRIMARY.
|
| DEFAULT IS OFF. With `enabled` false — and on SQLite, which has no replica
| concept at all — ReadReplica is a genuine no-op: the default connection is
| never swapped and the installation behaves byte-for-byte as it did before.
|
| REPLICATION LAG IS THE WHOLE RISK. See the `lag` block below.
*/

return [

    /*
     * Master switch. Off means ReadReplica::connection() always answers with
     * the primary and ReadReplica::run() calls the closure with no swap.
     */
    'enabled' => (bool) env('DB_READ_REPLICA_ENABLED', false),

    /*
     * The named connection reporting reads through. It must exist in
     * config/database.php; a missing name is treated as "no replica" rather
     * than as an error, so a half-applied deploy degrades to the primary.
     */
    'connection' => (string) env('DB_READ_REPLICA_CONNECTION', 'mysql_read'),

    /*
     * Replication lag guard.
     *
     * A replica is eventually consistent. A customer who has just triggered a
     * send and immediately opens the report would, on a lagging replica, be
     * shown numbers from before their own action — which reads as data loss,
     * not as caching. The guard measures how far behind the replica is and
     * falls back to the primary when the answer is "too far" OR "cannot
     * tell". Unknown lag is treated as unacceptable lag on purpose: a probe
     * that fails is not evidence that the replica is fresh.
     */
    'lag' => [

        'enabled' => (bool) env('DB_READ_REPLICA_LAG_GUARD', true),

        /* Seconds of replication delay still considered acceptable. */
        'max_seconds' => (float) env('DB_READ_REPLICA_MAX_LAG', 5.0),

        /*
         * The probe result is cached for this long (Laravel Cache — D-04) so
         * a report that opens twenty queries does not open twenty extra
         * `SHOW REPLICA STATUS` round trips. Keep it well under max_seconds.
         */
        'probe_ttl_seconds' => (int) env('DB_READ_REPLICA_PROBE_TTL', 5),

        /*
         * Probes are tried in order; the first one that returns a number
         * wins.
         *
         *  status    — MySQL/MariaDB `SHOW REPLICA STATUS` (8.0.22+), falling
         *              back to `SHOW SLAVE STATUS`. Needs the REPLICATION
         *              CLIENT privilege on the replica user. Zero setup
         *              otherwise. `Seconds_Behind_Source` NULL (replication
         *              stopped) is reported as "unknown", never as zero.
         *  heartbeat — read `replica_heartbeat` THROUGH the replica and
         *              compare its timestamp to now. Works on any driver and
         *              measures true end-to-end delay, but only if
         *              `php artisan replica:heartbeat` runs on the primary
         *              every minute (see docs/deploy-mysql.md).
         */
        'strategies' => ['status', 'heartbeat'],

        'heartbeat_table' => (string) env('DB_READ_REPLICA_HEARTBEAT_TABLE', 'replica_heartbeat'),

        /*
         * What to do when no probe could produce a number: 'primary' (fail
         * safe, the default and the documented recommendation) or 'replica'
         * (fail open — only for an operator who monitors lag out of band).
         */
        'on_unknown' => (string) env('DB_READ_REPLICA_ON_UNKNOWN', 'primary'),
    ],

];
