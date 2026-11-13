# Production database: MySQL 8 / MariaDB 10.6+

Local development and the test suite run on SQLite (see docs/DECISIONS.md D-03). Production
must run MySQL 8 (or MariaDB 10.6+) with `utf8mb4`/`utf8mb4_unicode_ci` everywhere —
this is Laravel's default charset in `config/database.php` and no migration in this repo
overrides it.

## Required after `php artisan migrate` on MySQL

### Partitioned tables (spec §0.1)
The four high-volume tables are partitioned by month. Laravel's schema builder cannot
express partitioning, so run:

```
php artisan db:partition
```

The command is a no-op on non-MySQL drivers. It applies `PARTITION BY RANGE` (monthly,
on the created-at epoch) to:

- `subscribers`
- `campaign_delivery_logs`
- `campaign_track_opens`
- `campaign_track_clicks`

and creates 24 rolling monthly partitions plus `pMAX`. Re-running it adds any missing
future partitions (safe to schedule monthly via cron).

## Queues / Redis
Set in `.env`:

```
QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_CLIENT=phpredis
REDIS_HOST=<central redis for distributed workers>
```

Workers (Supervisor units, one per queue priority — see spec §0.4):

```
php artisan queue:work redis --queue=transactional,campaigns-high --tries=3
php artisan queue:work redis --queue=campaigns-send,automation --tries=3
php artisan queue:work redis --queue=imports,bounces,webhooks --tries=3
php artisan queue:work redis --queue=exports,maintenance --tries=3
```

Horizon: `composer require laravel/horizon` on the Linux host (needs `ext-pcntl`,
not installable on the Windows dev machine — docs/DECISIONS.md D-04). `config/horizon.php`
and the dashboard authorisation gate already ship — see [tech-stack.md](tech-stack.md).

Add a heartbeat process next to each worker pool so the §6.6 admin worker
registry can show which nodes are alive (WP-24):

```
php artisan queue:heartbeat --queues=campaigns-send,automation --interval=30
```

## Reporting read replica (spec §4.6 F-4.6-007)

Reporting is the one workload here that is happy to read data a few seconds old, and
it is also the one that scans the largest tables. Pointing it at a MySQL replica takes
that load off the machine that has to keep accepting sends.

**This is off by default and does nothing until you turn it on.** With
`DB_READ_REPLICA_ENABLED` unset, `App\Support\Database\ReadReplica` resolves to the
primary and its wrapper calls the closure with no connection swap at all — byte for
byte the behaviour of an install that never heard of replicas. The same is true on
SQLite, which has no replication concept.

### Why a separate connection instead of `read`/`write` on `mysql`

Laravel will happily take a `read`/`write` array on the primary connection. Doing that
load-balances **every** select the application makes onto a machine that is by
definition behind — including the send path. The failure mode is the one thing this
codebase must never do: a send job reads a stale `campaign_delivery_logs` row and
sends a subscriber a second copy.

So the split lives on its own connection, `mysql_read`, and nothing uses it until a
reporting call site deliberately asks for it. Everything else keeps talking to the
primary.

`mysql_read` still carries Laravel's own `read`/`write` arrays, and that is what
enforces **a write is never sent to a replica**: selects go to the read host,
inserts/updates/deletes/statements go to the write host, which is the primary. A
report job that stamps its own progress row while running is therefore still writing
to the primary.

### Setting it up

On the replica, create a user with the two privileges the feature needs — `SELECT` to
read, and `REPLICATION CLIENT` so the lag probe can ask `SHOW REPLICA STATUS`:

```sql
CREATE USER 'emp_reporting'@'%' IDENTIFIED BY '…';
GRANT SELECT ON emp.* TO 'emp_reporting'@'%';
GRANT REPLICATION CLIENT ON *.* TO 'emp_reporting'@'%';
```

Then in `.env` on the app servers:

```
DB_READ_REPLICA_ENABLED=true
DB_READ_HOST=10.0.0.21,10.0.0.22       # comma-separated spreads across replicas
DB_READ_USERNAME=emp_reporting
DB_READ_PASSWORD=…
```

`DB_READ_HOST` defaults to `DB_HOST`, so an install that enables the flag without
naming a replica simply reads the primary. That is intentional: a half-finished deploy
degrades to "correct but not faster", never to "wrong".

### Replication lag is the whole risk

A replica is eventually consistent. A customer who triggers a send and immediately
opens the report would, on a lagging replica, be shown numbers from *before their own
action* — which reads as data loss, not as caching.

Before every routed read, `App\Support\Database\ReplicaLag` measures the delay and the
read falls back to the primary when it is too large. Two probes, in order:

1. **`status`** — `SHOW REPLICA STATUS` (MySQL 8.0.22+), falling back to
   `SHOW SLAVE STATUS`. Computed by the replica itself, so clock skew between the two
   machines cannot distort it. Tried first for that reason. Needs `REPLICATION CLIENT`.
   If `Seconds_Behind_Source` comes back `NULL` — which means replication has
   **stopped** — that is reported as *unknown*, never as zero.
2. **`heartbeat`** — the primary stamps `replica_heartbeat`, and the row is read back
   *through* the replica. This measures true end-to-end visibility delay on any
   driver, but it depends on the two hosts agreeing about the time. Enable it by
   running this on the **primary**, once a minute:

   ```
   * * * * * cd /var/www/emp && php artisan replica:heartbeat >> /dev/null 2>&1
   ```

   (`routes/console.php` is owned by another work package, so the heartbeat is a
   plain crontab line rather than a Laravel scheduler entry. Skipping it is fine —
   the `status` probe alone is enough on MySQL.)

The routing decision:

| Probe result | Outcome |
| --- | --- |
| lag ≤ `DB_READ_REPLICA_MAX_LAG` (default 5s) | read the **replica** |
| lag above the ceiling | read the **primary** |
| lag cannot be measured | read the **primary** (`DB_READ_REPLICA_ON_UNKNOWN=primary`) |

A probe that fails is not evidence that the replica is fresh, which is why the third
row falls back rather than proceeding. Set `DB_READ_REPLICA_ON_UNKNOWN=replica` only
if you monitor lag out of band and accept the consequence.

Probe results are cached for `DB_READ_REPLICA_PROBE_TTL` seconds (default 5, Laravel
Cache per D-04) so a twenty-query report does not open twenty extra round trips. Keep
the TTL well below the lag ceiling.

Three further guards live in `ReadReplica` itself:

- it **refuses any reporting connection that does not declare a `read`/`write`
  split.** That split is the only reason "a write is never sent to a replica" is
  true, so if you point `DB_READ_REPLICA_CONNECTION` at a plain connection aimed
  straight at a replica host — the likeliest misconfiguration here — the feature
  turns itself off and everything reads the primary. A misconfigured install loses
  the performance win; it does not lose correctness;
- it refuses to engage while a transaction is open on the primary;
- it always restores the default connection, including when the closure throws.

### What is routed, and what is not

Routed today — every queued reporting job, which is where the heavy reads are:

- `App\Jobs\Reports\RunCampaignReportExportJob` — campaign report export
- `App\Jobs\Reports\RunReportBuilderExportJob` — custom report export
- `App\Jobs\Reports\RunAdminLogExportJob` — admin log export
- `App\Jobs\Exports\AdminOverviewExportJob` — cross-tenant module overview
- `App\Jobs\Analytics\SendScheduledReportJob` — scheduled analytics PDF

**Not routed:** the interactive Filament report and analytics pages. A page render is
a handful of aggregates in front of a waiting human, where a few seconds of staleness
is more confusing than a few hundred milliseconds of primary load — and it is the path
most likely to be opened straight after a write. Route it only if you measure a
problem.

Extending the seam is a one-line change **at the call site** —
`ReadReplica::run(fn () => …)` — and needs no change inside
`App\Services\Reports\*` or `App\Services\Analytics\*` at all. That is the entire
point of the design: the reporting services name no connection, so they follow
whatever the default is at the moment their query runs.

### Verification status (D-14)

The routing *mechanism* is verified end to end in
`tests/Feature/Performance/ReadReplicaTest.php` against two real SQLite files: a select
inside the wrapper is proven to hit the replica file and a write inside the same
wrapper is proven to land in the primary file. A real MySQL primary/replica pair does
not exist on the development machine, so **replica behaviour against MySQL is not
claimed to be verified** — see `docs/e2e-checklist.md`.

Full install walkthroughs: [install-vps.md](install-vps.md),
[install-cpanel.md](install-cpanel.md), [install-plesk.md](install-plesk.md),
[requirements.md](requirements.md).
