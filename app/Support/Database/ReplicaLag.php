<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * How far behind is the reporting replica? (spec §4.6 F-4.6-007, WP-46)
 *
 * Replication lag is the entire risk of reading a replica. A report rendered
 * off a replica that is thirty seconds behind shows a customer numbers from
 * before their own last action, and there is nothing in the UI that would tell
 * them so. {@see ReadReplica} therefore asks this class before every routed
 * read and stays on the primary unless the answer is a number below the
 * configured ceiling.
 *
 * THREE OUTCOMES, and the third is the important one:
 *
 *   a number below the ceiling  → use the replica
 *   a number above the ceiling  → use the primary
 *   NULL, "cannot tell"         → use the primary (config `on_unknown`)
 *
 * "Cannot tell" covers a probe that threw, a replica whose SQL thread has
 * stopped (MySQL reports `Seconds_Behind_Source` as NULL, NOT as zero), a
 * missing heartbeat row, and a driver with no replication concept at all. None
 * of those are evidence that the replica is fresh, so none of them may be
 * rounded down to zero. This is the difference between a lag guard and a
 * decoration.
 *
 * Probes are cached for `emp-replica.lag.probe_ttl_seconds` (Laravel Cache,
 * D-04) so one report does not open one `SHOW REPLICA STATUS` per query.
 *
 * ON SQLITE (dev + tests, D-03) there is no replication and no
 * `SHOW REPLICA STATUS`. The `status` probe is skipped by driver, and unless
 * an operator has wired the heartbeat table the answer is NULL — which is
 * exactly right, because a SQLite install has no replica to be behind.
 */
final class ReplicaLag
{
    private const CACHE_PREFIX = 'emp.replica.lag.';

    /**
     * Is the replica fresh enough to serve a report right now?
     *
     * True only when the guard is disabled outright, or when a probe produced
     * a number within the ceiling.
     */
    public static function acceptable(string $connection): bool
    {
        if (! (bool) config('emp-replica.lag.enabled', true)) {
            return true;
        }

        $seconds = self::seconds($connection);

        if ($seconds === null) {
            return config('emp-replica.lag.on_unknown', 'primary') === 'replica';
        }

        return $seconds <= (float) config('emp-replica.lag.max_seconds', 5.0);
    }

    /**
     * Measured replication delay in seconds, or NULL when it cannot be
     * determined. Never throws: a broken probe must degrade the routing
     * decision, not the report.
     */
    public static function seconds(string $connection): ?float
    {
        $ttl = max(0, (int) config('emp-replica.lag.probe_ttl_seconds', 5));

        if ($ttl === 0) {
            return self::probe($connection);
        }

        // Cache::remember cannot store "no value" distinctly from null, so the
        // measurement is boxed in an array and the box is what gets cached.
        /** @var array{value: float|null} $boxed */
        $boxed = Cache::remember(
            self::CACHE_PREFIX.$connection,
            $ttl,
            static fn (): array => ['value' => self::probe($connection)],
        );

        return $boxed['value'] ?? null;
    }

    /** Drop the cached measurement — used by tests and by the heartbeat command. */
    public static function flush(string $connection): void
    {
        Cache::forget(self::CACHE_PREFIX.$connection);
    }

    private static function probe(string $connection): ?float
    {
        /** @var list<string> $strategies */
        $strategies = (array) config('emp-replica.lag.strategies', ['status', 'heartbeat']);

        foreach ($strategies as $strategy) {
            $seconds = match ((string) $strategy) {
                'status' => self::fromReplicaStatus($connection),
                'heartbeat' => self::fromHeartbeat($connection),
                default => null,
            };

            if ($seconds !== null) {
                return $seconds;
            }
        }

        return null;
    }

    /**
     * MySQL/MariaDB's own answer, computed by the replica, so it is immune to
     * clock skew between the two machines — which is why it is tried first.
     *
     * `SHOW REPLICA STATUS` is the 8.0.22+ spelling; older servers and MariaDB
     * answer `SHOW SLAVE STATUS`. Both are run against the READ pdo, because
     * the question is about the read host.
     */
    private static function fromReplicaStatus(string $connection): ?float
    {
        try {
            $db = DB::connection($connection);

            if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
                return null;
            }

            $row = self::firstRow($connection, 'SHOW REPLICA STATUS')
                ?? self::firstRow($connection, 'SHOW SLAVE STATUS');

            if ($row === null) {
                // Not a replica (an empty result set is what a primary
                // returns), or the user lacks REPLICATION CLIENT.
                return null;
            }

            $behind = $row['Seconds_Behind_Source'] ?? $row['Seconds_Behind_Master'] ?? null;

            // NULL here means replication is NOT RUNNING. Treating that as
            // zero lag would route reads at a replica that has stopped
            // receiving data entirely — the single worst reading of this
            // field, and a common one.
            if ($behind === null) {
                return null;
            }

            return (float) $behind;
        } catch (Throwable $e) {
            self::note($connection, 'status', $e);

            return null;
        }
    }

    /**
     * End-to-end delay measured through the data path itself: the primary
     * stamps `replica_heartbeat`, we read that row THROUGH the replica, and
     * the difference is how long a just-written row takes to become visible.
     *
     * Portable to any driver, but it depends on `replica:heartbeat` actually
     * running on the primary and on the two hosts agreeing about the time —
     * hence second place behind the server's own report.
     */
    private static function fromHeartbeat(string $connection): ?float
    {
        $table = (string) config('emp-replica.lag.heartbeat_table', 'replica_heartbeat');

        try {
            $db = DB::connection($connection);

            if (! $db->getSchemaBuilder()->hasTable($table)) {
                return null;
            }

            $beat = $db->table($table)->max('beat_at');

            if ($beat === null) {
                return null;
            }

            // Stored as a unix timestamp on purpose: an integer needs no
            // timezone interpretation and compares identically on every
            // driver (D-03 — no driver-specific SQL).
            return max(0.0, (float) time() - (float) $beat);
        } catch (Throwable $e) {
            self::note($connection, 'heartbeat', $e);

            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function firstRow(string $connection, string $sql): ?array
    {
        $rows = DB::connection($connection)->select($sql);

        if ($rows === []) {
            return null;
        }

        return (array) $rows[0];
    }

    private static function note(string $connection, string $strategy, Throwable $e): void
    {
        Log::debug('replica.lag.probe_failed', [
            'connection' => $connection,
            'strategy' => $strategy,
            'exception' => $e::class,
        ]);
    }
}
