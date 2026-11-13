<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Route a block of read-only work at the reporting replica (spec §4.6
 * F-4.6-007, WP-46).
 *
 * WHY A WRAPPER AND NOT A CHANGE INSIDE THE REPORT SERVICES
 *
 * `App\Services\Reports\*` and `App\Services\Analytics\*` build Eloquent
 * builders against models that name no connection, so they resolve whatever
 * `DB::getDefaultConnection()` says at the moment the query runs. That is the
 * seam: point the default at the reporting connection for the duration of one
 * call and every query underneath follows, with no edit to a single reporting
 * class. Call sites opt IN; nothing is routed by default.
 *
 * THE FOUR INVARIANTS
 *
 *  1. A WRITE IS NEVER SENT TO A REPLICA. The reporting connection carries
 *     Laravel's `read`/`write` split (config/database.php `mysql_read`), so
 *     selects go to the read host and every insert/update/delete/statement
 *     goes to the write host — the primary. A job that updates its own status
 *     row inside the wrapper is therefore still writing to the primary.
 *  2. NEVER INSIDE A TRANSACTION. An open transaction lives on the primary
 *     connection object; swapping the default underneath it would put reads on
 *     a machine that cannot see the uncommitted rows, and would leave the
 *     commit on a connection nobody is looking at. Inside a transaction this
 *     helper does nothing at all.
 *  3. NEVER WHEN THE REPLICA IS STALE. {@see ReplicaLag} is consulted first;
 *     lag over the ceiling — or lag that cannot be measured — falls back to
 *     the primary.
 *  4. THE DEFAULT CONNECTION IS ALWAYS RESTORED, including when the closure
 *     throws (`finally`), because a leaked default would silently move the
 *     rest of the worker's queue onto the replica.
 *
 * ON SQLITE (dev + tests, D-03) THIS IS A NO-OP. There is no second database
 * on this machine and none is assumed: with `emp-replica.enabled` false — the
 * shipped default — {@see self::connection()} answers with the primary,
 * {@see self::run()} skips the swap entirely and the closure runs exactly as
 * if the wrapper were not there.
 *
 * HONESTY (D-14): the routing mechanism is verified end to end against two
 * real SQLite files in tests/Feature/Performance/ReadReplicaTest.php. A real
 * MySQL primary/replica pair does not exist on this machine and is NOT
 * claimed to be verified — see docs/e2e-checklist.md.
 */
final class ReadReplica
{
    /**
     * The connection name reads should use right now.
     *
     * Returns the PRIMARY (the current default) whenever the feature is off,
     * the configured connection does not exist, a transaction is open, or the
     * replica is too far behind. Callers can compare the answer to
     * `DB::getDefaultConnection()` to learn whether anything would change.
     */
    public static function connection(): string
    {
        $primary = (string) DB::getDefaultConnection();

        if (! (bool) config('emp-replica.enabled', false)) {
            return $primary;
        }

        $name = trim((string) config('emp-replica.connection', ''));

        if ($name === '' || $name === $primary) {
            return $primary;
        }

        // A name that is not in config/database.php means a half-applied
        // deploy. Degrade to the primary rather than blowing up a report.
        if (! is_array(config('database.connections.'.$name))) {
            return $primary;
        }

        // INVARIANT 1, ENFORCED RATHER THAN ASSUMED.
        //
        // "A write is never sent to a replica" is true because Laravel routes
        // writes to the `write` PDO — which only exists when the connection
        // declares a read/write split. The shipped `mysql_read` does. But the
        // most natural misconfiguration in this whole feature is an operator
        // reading "a separate connection used only by reporting" and defining
        // a plain connection aimed straight at the replica host; every write
        // inside the wrapper would then land on the replica, silently, and the
        // one promise this class makes loudest would be false.
        //
        // So a connection with no `read` block is refused outright. Falling
        // back to the primary costs a misconfigured install its performance
        // win; using it would cost correctness.
        if (! is_array(config('database.connections.'.$name.'.read'))) {
            return $primary;
        }

        // Invariant 2 — see the class docblock.
        if (self::inTransaction($primary)) {
            return $primary;
        }

        // Invariant 3.
        if (! ReplicaLag::acceptable($name)) {
            return $primary;
        }

        return $name;
    }

    /** Would a call to {@see self::run()} actually change anything right now? */
    public static function routed(): bool
    {
        return self::connection() !== DB::getDefaultConnection();
    }

    /**
     * Run read-only work with the default connection pointed at the replica.
     *
     * The closure's return value is passed straight through. When routing is
     * not available the closure is simply invoked — same value, same
     * exceptions, same everything. That equivalence is what makes it safe to
     * wrap a call site unconditionally.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $work
     * @return TReturn
     */
    public static function run(callable $work): mixed
    {
        $primary = (string) DB::getDefaultConnection();
        $replica = self::connection();

        if ($replica === $primary) {
            return $work();
        }

        DB::setDefaultConnection($replica);

        try {
            return $work();
        } finally {
            // Invariant 4. Restored even on a throw, and the replica
            // connection is NOT purged: purging here would drop a pooled PDO
            // that the next report in this worker will want.
            DB::setDefaultConnection($primary);
        }
    }

    /**
     * True when a transaction is open on the primary.
     *
     * Wrapped because asking an unconnected connection for its transaction
     * level opens a PDO, and a connection failure here must read as "do not
     * route" rather than as a report crash.
     */
    private static function inTransaction(string $primary): bool
    {
        try {
            return DB::connection($primary)->transactionLevel() > 0;
        } catch (Throwable) {
            return true;
        }
    }
}
