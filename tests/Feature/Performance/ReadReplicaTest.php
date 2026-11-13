<?php

declare(strict_types=1);

use App\Support\Database\ReadReplica;
use App\Support\Database\ReplicaLag;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/*
 * WP-46 §4.6 F-4.6-007 — read-replica support for reporting.
 *
 * The routing MECHANISM is proved here against two real SQLite files rather
 * than asserted from a config array. Laravel's read/write split is generic
 * across drivers (Illuminate\Database\Connectors\ConnectionFactory), so a
 * primary file plus a replica file exercise the same code path a MySQL pair
 * would: selects resolve through the read PDO, everything else through the
 * write PDO. Each file gets a distinguishable row, so a query's ANSWER says
 * which machine served it.
 *
 * What this does NOT prove, and does not claim to (D-14): that a real MySQL
 * primary/replica pair replicates correctly. There is no second database
 * server on this machine — see docs/e2e-checklist.md.
 */

const WP46_PRIMARY = 'wp46_primary';
const WP46_REPLICA = 'wp46_replica';
const WP46_REPORTING = 'wp46_reporting';

/** Absolute path of a scratch SQLite file for this test run. */
function wp46_path(string $which): string
{
    return storage_path('framework/testing/wp46-'.$which.'.sqlite');
}

/**
 * Build a primary + replica pair of SQLite files and a reporting connection
 * whose reads go to the replica and whose writes go to the primary.
 *
 * The two `probe` tables carry DIFFERENT labels on purpose: that is how a
 * select proves which file answered it, without inspecting any internals.
 */
function wp46_boot(): void
{
    File::ensureDirectoryExists(dirname(wp46_path('primary')));

    foreach (['primary', 'replica'] as $which) {
        File::delete(wp46_path($which));
        touch(wp46_path($which));

        config(['database.connections.wp46_'.$which => [
            'driver' => 'sqlite',
            'database' => wp46_path($which),
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);

        DB::purge('wp46_'.$which);

        DB::connection('wp46_'.$which)->statement(
            'CREATE TABLE probe (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT NOT NULL)'
        );
        DB::connection('wp46_'.$which)->table('probe')->insert(['label' => $which]);
    }

    // The reporting connection: reads → replica file, writes → primary file.
    // Exactly the shape config/database.php gives `mysql_read`.
    config(['database.connections.'.WP46_REPORTING => [
        'driver' => 'sqlite',
        'database' => wp46_path('primary'),
        'read' => ['database' => wp46_path('replica')],
        'write' => ['database' => wp46_path('primary')],
        'sticky' => false,
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]]);

    DB::purge(WP46_REPORTING);

    config([
        'emp-replica.enabled' => true,
        'emp-replica.connection' => WP46_REPORTING,
        'emp-replica.lag.enabled' => false,
        'emp-replica.lag.probe_ttl_seconds' => 0,
    ]);
}

/**
 * Run $work with the application default pointed at the scratch PRIMARY.
 *
 * Needed because RefreshDatabase holds an open transaction on the `sqlite`
 * in-memory connection, and ReadReplica correctly refuses to route while a
 * transaction is open (invariant 2). The default is restored in `finally`, so
 * RefreshDatabase's own rollback still finds the connection it began on.
 */
function wp46_asPrimary(callable $work): mixed
{
    $original = DB::getDefaultConnection();

    DB::setDefaultConnection(WP46_PRIMARY);

    try {
        return $work();
    } finally {
        DB::setDefaultConnection($original);
    }
}

function wp46_rows(string $connection): array
{
    return DB::connection($connection)->table('probe')->pluck('label')->all();
}

beforeEach(function (): void {
    Cache::flush();
    wp46_boot();
});

afterEach(function (): void {
    foreach ([WP46_PRIMARY, WP46_REPLICA, WP46_REPORTING] as $connection) {
        DB::purge($connection);
    }

    File::delete(wp46_path('primary'));
    File::delete(wp46_path('replica'));
});

/* ------------------------------------------------------------------ *
 |  The shipped default: no replica, no behaviour change at all
 * ------------------------------------------------------------------ */

it('is a genuine no-op when no replica is configured', function (): void {
    config(['emp-replica.enabled' => false]);

    wp46_asPrimary(function (): void {
        expect(ReadReplica::connection())->toBe(WP46_PRIMARY)
            ->and(ReadReplica::routed())->toBeFalse();

        $seen = ReadReplica::run(function (): string {
            // The default connection is untouched, so this reads the PRIMARY.
            expect(DB::getDefaultConnection())->toBe(WP46_PRIMARY);

            return (string) DB::table('probe')->value('label');
        });

        expect($seen)->toBe('primary')
            ->and(DB::getDefaultConnection())->toBe(WP46_PRIMARY);
    });
});

it('is a no-op on the real application default, which has no replica connection', function (): void {
    // Nothing configured beyond the shipped config/emp-replica.php defaults.
    config(['emp-replica.enabled' => false, 'emp-replica.connection' => 'mysql_read']);

    expect(ReadReplica::connection())->toBe(DB::getDefaultConnection())
        ->and(ReadReplica::run(fn (): string => 'value'))->toBe('value');
});

it('falls back to the primary when the configured connection does not exist', function (): void {
    config(['emp-replica.connection' => 'a_connection_nobody_defined']);

    wp46_asPrimary(function (): void {
        expect(ReadReplica::connection())->toBe(WP46_PRIMARY);
    });
});

it('refuses a reporting connection that declares no read/write split', function (): void {
    // The likeliest misconfiguration: a plain connection aimed straight at the
    // replica host. Laravel would send writes THERE, because there is no write
    // PDO to route them to — so the connection is refused rather than used.
    config(['database.connections.wp46_plain' => [
        'driver' => 'sqlite',
        'database' => wp46_path('replica'),
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]]);

    config(['emp-replica.connection' => 'wp46_plain']);

    wp46_asPrimary(function (): void {
        expect(ReadReplica::connection())->toBe(WP46_PRIMARY)
            ->and(ReadReplica::routed())->toBeFalse();

        // And a write really does stay on the primary file.
        ReadReplica::run(fn () => DB::table('probe')->insert(['label' => 'plain-connection-write']));
    });

    expect(wp46_rows(WP46_PRIMARY))->toContain('plain-connection-write')
        ->and(wp46_rows(WP46_REPLICA))->not->toContain('plain-connection-write');
});

/* ------------------------------------------------------------------ *
 |  Resolution + routing
 * ------------------------------------------------------------------ */

it('resolves the configured reporting connection', function (): void {
    wp46_asPrimary(function (): void {
        expect(ReadReplica::connection())->toBe(WP46_REPORTING)
            ->and(ReadReplica::routed())->toBeTrue();
    });
});

it('sends reads inside the wrapper to the replica', function (): void {
    wp46_asPrimary(function (): void {
        $label = ReadReplica::run(function (): string {
            expect(DB::getDefaultConnection())->toBe(WP46_REPORTING);

            return (string) DB::table('probe')->value('label');
        });

        // The replica file is the only place this string exists.
        expect($label)->toBe('replica');
    });

    // …and outside the wrapper the same query still answers from the primary.
    wp46_asPrimary(function (): void {
        expect((string) DB::table('probe')->value('label'))->toBe('primary');
    });
});

it('sends a write inside the wrapper to the primary, never the replica', function (): void {
    wp46_asPrimary(function (): void {
        ReadReplica::run(function (): void {
            DB::table('probe')->insert(['label' => 'written-inside-the-wrapper']);
        });
    });

    // The whole invariant, stated as data: the row is in the primary FILE and
    // absent from the replica FILE. Two files, two real databases.
    expect(wp46_rows(WP46_PRIMARY))->toContain('written-inside-the-wrapper')
        ->and(wp46_rows(WP46_REPLICA))->not->toContain('written-inside-the-wrapper')
        ->and(wp46_rows(WP46_REPLICA))->toBe(['replica']);
});

it('passes the closure return value straight through', function (): void {
    wp46_asPrimary(function (): void {
        expect(ReadReplica::run(fn (): array => ['a' => 1]))->toBe(['a' => 1]);
    });
});

it('restores the default connection when the closure throws', function (): void {
    wp46_asPrimary(function (): void {
        expect(fn () => ReadReplica::run(function (): void {
            throw new RuntimeException('report blew up');
        }))->toThrow(RuntimeException::class, 'report blew up');

        expect(DB::getDefaultConnection())->toBe(WP46_PRIMARY);
    });
});

it('refuses to route while a transaction is open on the primary', function (): void {
    wp46_asPrimary(function (): void {
        DB::connection(WP46_PRIMARY)->beginTransaction();

        try {
            expect(ReadReplica::connection())->toBe(WP46_PRIMARY);

            $label = ReadReplica::run(fn (): string => (string) DB::table('probe')->value('label'));

            // Still the primary — the open transaction's view is preserved.
            expect($label)->toBe('primary');
        } finally {
            DB::connection(WP46_PRIMARY)->rollBack();
        }
    });
});

/* ------------------------------------------------------------------ *
 |  The lag guard — the reason this feature is not just a config change
 * ------------------------------------------------------------------ */

it('routes to the replica when the heartbeat says it is fresh', function (): void {
    config([
        'emp-replica.lag.enabled' => true,
        'emp-replica.lag.strategies' => ['heartbeat'],
        'emp-replica.lag.max_seconds' => 5.0,
    ]);

    wp46_heartbeat(time());

    wp46_asPrimary(function (): void {
        expect(ReplicaLag::seconds(WP46_REPORTING))->toBeLessThanOrEqual(2.0)
            ->and(ReadReplica::connection())->toBe(WP46_REPORTING);
    });
});

it('falls back to the primary when replication lag exceeds the threshold', function (): void {
    config([
        'emp-replica.lag.enabled' => true,
        'emp-replica.lag.strategies' => ['heartbeat'],
        'emp-replica.lag.max_seconds' => 5.0,
    ]);

    // The replica's copy of the heartbeat is two minutes old: the primary has
    // been writing rows this replica has not seen yet.
    wp46_heartbeat(time() - 120);

    wp46_asPrimary(function (): void {
        expect(ReplicaLag::seconds(WP46_REPORTING))->toBeGreaterThan(100.0)
            ->and(ReplicaLag::acceptable(WP46_REPORTING))->toBeFalse()
            ->and(ReadReplica::connection())->toBe(WP46_PRIMARY);

        // And the wrapper honours it: this read must come off the primary.
        expect(ReadReplica::run(fn (): string => (string) DB::table('probe')->value('label')))
            ->toBe('primary');
    });
});

it('treats unmeasurable lag as unacceptable and stays on the primary', function (): void {
    config([
        'emp-replica.lag.enabled' => true,
        'emp-replica.lag.strategies' => ['status', 'heartbeat'],
        'emp-replica.lag.on_unknown' => 'primary',
    ]);

    // No heartbeat table at all, and SQLite has no SHOW REPLICA STATUS: the
    // probe cannot answer. That is NOT evidence the replica is fresh.
    wp46_asPrimary(function (): void {
        expect(ReplicaLag::seconds(WP46_REPORTING))->toBeNull()
            ->and(ReplicaLag::acceptable(WP46_REPORTING))->toBeFalse()
            ->and(ReadReplica::connection())->toBe(WP46_PRIMARY);
    });
});

it('can be told to fail open on unknown lag', function (): void {
    config([
        'emp-replica.lag.enabled' => true,
        'emp-replica.lag.strategies' => ['heartbeat'],
        'emp-replica.lag.on_unknown' => 'replica',
    ]);

    wp46_asPrimary(function (): void {
        expect(ReplicaLag::seconds(WP46_REPORTING))->toBeNull()
            ->and(ReadReplica::connection())->toBe(WP46_REPORTING);
    });
});

it('reads a stopped replica (NULL Seconds_Behind_Source) as unknown, not as zero', function (): void {
    // Documented behaviour of the `status` probe. SQLite cannot answer SHOW
    // REPLICA STATUS at all, which exercises the same branch: no number.
    config(['emp-replica.lag.strategies' => ['status']]);

    expect(ReplicaLag::seconds(WP46_REPORTING))->toBeNull();
});

it('caches the lag probe for the configured ttl', function (): void {
    config([
        'emp-replica.lag.enabled' => true,
        'emp-replica.lag.strategies' => ['heartbeat'],
        'emp-replica.lag.probe_ttl_seconds' => 60,
    ]);

    wp46_heartbeat(time());

    $first = ReplicaLag::seconds(WP46_REPORTING);

    // Age the heartbeat behind the cache's back; the cached answer must hold.
    wp46_heartbeat(time() - 900, flush: false);

    expect(ReplicaLag::seconds(WP46_REPORTING))->toBe($first);

    ReplicaLag::flush(WP46_REPORTING);

    expect(ReplicaLag::seconds(WP46_REPORTING))->toBeGreaterThan(800.0);
});

/* ------------------------------------------------------------------ *
 |  Config shape that production depends on
 * ------------------------------------------------------------------ */

it('ships a mysql_read connection whose writes point at the primary host', function (): void {
    $connection = config('database.connections.mysql_read');

    expect($connection)->toBeArray()
        ->and($connection['driver'])->toBe('mysql')
        ->and($connection['read'])->toHaveKey('host')
        ->and($connection['write']['host'])->toBe([config('database.connections.mysql.host')])
        // Without a DB_READ_HOST the replica IS the primary — an install that
        // enables the flag but names no replica must still be correct.
        ->and($connection['read']['host'])->toBe([config('database.connections.mysql.host')])
        ->and($connection['sticky'])->toBeFalse()
        ->and($connection['database'])->toBe(config('database.connections.mysql.database'));
});

it('ships the feature disabled', function (): void {
    // Re-read the file rather than the mutated runtime config.
    $shipped = require base_path('config/emp-replica.php');

    expect($shipped['enabled'])->toBeFalse()
        ->and($shipped['connection'])->toBe('mysql_read')
        ->and($shipped['lag']['enabled'])->toBeTrue()
        ->and($shipped['lag']['on_unknown'])->toBe('primary');
});

/**
 * Stamp the heartbeat row on the REPLICA file (that is where it gets read).
 *
 * $flush defaults to true so each test starts from a clean measurement; the
 * caching test passes false precisely so it can age the row BEHIND the cache's
 * back and prove the cached answer still holds.
 */
function wp46_heartbeat(int $at, bool $flush = true): void
{
    $db = DB::connection(WP46_REPLICA);

    if (! $db->getSchemaBuilder()->hasTable('replica_heartbeat')) {
        $db->statement('CREATE TABLE replica_heartbeat (id INTEGER PRIMARY KEY, beat_at INTEGER NOT NULL)');
    }

    $db->table('replica_heartbeat')->upsert([['id' => 1, 'beat_at' => $at]], ['id'], ['beat_at']);

    if ($flush) {
        ReplicaLag::flush(WP46_REPORTING);
    }
}
