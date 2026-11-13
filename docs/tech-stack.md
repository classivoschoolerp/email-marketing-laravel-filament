# Optional stack components (spec §0.1)

Four §0.1 rows describe components that cannot be installed on the Windows
development machine this platform is built on (docs/DECISIONS.md D-03/D-04), or
that change the application lifecycle enough that they must be opt-in. All of
them are **prepared in the repository**: configuration, guarded integration
code and the exact commands to switch them on. Nothing here is required for
the platform to run.

| Row | Component | State in the repo | To activate |
|---|---|---|---|
| F-0.1-005 | Laravel Horizon | `config/horizon.php` + `App\Support\Ops\HorizonSupport` (guarded, wired from `routes/modules/ops.php`) | `composer require laravel/horizon` on Linux |
| F-0.1-006 | Laravel Octane | `config/octane.php` + state-safety audit | `composer require laravel/octane` |
| F-0.1-007 | Reverb / Pusher | `config/broadcasting.php` + `App\Events\CampaignProgressUpdated` | `composer require laravel/reverb`, set `BROADCAST_CONNECTION` |
| F-0.1-009 | S3-compatible storage | `s3` disk + Operations settings toggle + `ops:storage-check` | configure `AWS_*`, enable the setting |

---

## Horizon — queue monitoring & auto-scaling workers (F-0.1-005)

**Why it is not a dependency.** Horizon needs `ext-pcntl`, `ext-posix` and a
Redis queue. None exist on Windows, so `composer require` cannot even resolve
here (D-04). Making it a hard dependency would mean the repository could not
be installed on its own build machine.

**What ships instead.** `config/horizon.php` is already written with four
supervisors mapped onto this platform's queue names
(`transactional`/`campaigns-high`, `campaigns-send`/`automation`,
`imports`/`bounces`/`webhooks`, `exports`/`maintenance`), auto-scaling by size
for the sending pool and by time for the rest.
`App\Support\Ops\HorizonSupport::configure()` — called from
`routes/modules/ops.php` — registers the `viewHorizon` gate behind the same
`logs.view` permission as the built-in queue monitor. Every call is a no-op
while the package is absent.

**Activate:**

```bash
composer require laravel/horizon
php artisan horizon:install        # publishes assets only; config already exists
# .env
QUEUE_CONNECTION=redis
HORIZON_PATH=admin/horizon
```

Then run `php artisan horizon` under Supervisor *instead of* the
`queue:work` programs in [install-vps.md](install-vps.md#7-queue-workers-mandatory).
The dashboard appears at `/admin/horizon`.

**Without Horizon** you still get: per-queue depth and oldest-pending age
(Admin → Queue monitor, WP-17), the failed-jobs UI, and the WP-24 worker/node
registry with graceful restart/drain (Admin → Worker nodes). `queue:heartbeat`
covers the "which nodes are alive" question Horizon would answer.

`HorizonSupport::status()` reports honestly whether the current host could run
it at all.

---

## Octane — high-throughput HTTP (F-0.1-006)

Octane keeps the framework booted between requests (FrankenPHP or Swoole).
It only helps the **admin/customer panels and public pages** — the sending
engine is queue-driven and never touches the HTTP layer.

```bash
composer require laravel/octane
php artisan octane:install --server=frankenphp
php artisan octane:start --workers=auto --max-requests=500
```

`--workers=auto` lines up with `App\Support\System\Cpu::cores()`, which is
cgroup-aware; Admin → Operations prints the detected count and the suggested
process layout.

### State-safety audit (required reading before enabling)

A long-lived worker turns any request state cached on a singleton into a
cross-request leak. Audited for this codebase:

| Component | Verdict |
|---|---|
| `App\Support\Tenancy\CurrentCustomer` | Registered with `$this->app->scoped()` (AppServiceProvider) — reset per request. **Safe**, and also listed in `config/octane.php`'s `flush`. |
| `App\Support\Roles\RoleChecker` | Scoped + memoised per request; also in `flush`. **Safe** |
| spatie settings repository | Request-scoped by the package. **Safe** |
| `App\Support\Ops\SlowQueryRecorder` | Statics: `$registered` must survive (the `DB::listen` hook is process-wide) and `$recording` is only true inside one call. **Safe as written** |
| `App\Support\System\Cpu` | Memoises an immutable host fact. **Safe** |
| `App\Services\Geo\GeoProviderRegistry` | Singleton of provider objects that read settings lazily on each call. **Safe** |
| Filament panels | Filament v3 is Octane-compatible; panel state is per request |

**Rule for new code:** never cache a `Customer`, `User`, `Request` or resolved
setting on a singleton. If you must, add the class to `config/octane.php`'s
`flush` list in the same commit.

Not available on shared cPanel/Plesk hosting — it needs a persistent process.

---

## Reverb / Pusher — real-time campaign progress (F-0.1-007)

`App\Events\CampaignProgressUpdated` implements `ShouldBroadcast` and carries
`{campaign_uid, total, sent, failed, status, percent}` on the private channel
`campaigns.{uid}` as `campaign.progress`.

**Wiring the send pipeline.** The WP-13 send pipeline is frozen for WP-24, so
the event is defined and tested but not yet dispatched. One line does it, at
the point where the delivery counters are already fresh — either in
`App\Support\Stats\LiveCounter`'s flush, or at the end of each send batch:

```php
event(CampaignProgressUpdated::for($campaign));
```

`::for()` accepts any object exposing `uid`, `delivery_total`, `delivery_sent`,
`delivery_failed` and `status`, so it does not couple the pipeline to a new
import. With `BROADCAST_CONNECTION=null` (the default, and the test default)
Laravel drops the payload, so adding the line is always safe.

**Activate Reverb (self-hosted, no vendor account):**

```bash
composer require laravel/reverb
php artisan reverb:install
# .env
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=... REVERB_APP_KEY=... REVERB_APP_SECRET=...
REVERB_HOST=your-domain REVERB_PORT=443 REVERB_SCHEME=https
php artisan reverb:start          # under Supervisor/systemd
```

Or Pusher: `BROADCAST_CONNECTION=pusher` plus the `PUSHER_*` variables.

**Channel authorisation.** Add to your broadcasting auth callbacks (a
`routes/channels.php` entry or a provider):

```php
Broadcast::channel('campaigns.{uid}', function ($customer, string $uid): bool {
    return Campaign::query()->where('uid', $uid)->exists(); // tenant scope applies
});
```

The global `customer_id` scope (D-05) already restricts the query to the
authenticated tenant, so a customer can only subscribe to their own campaigns.

**Customer panel listener.** The campaign progress bar subscribes only when a
driver is configured, so a `null`/`log` install renders the existing
poll-based bar unchanged:

```js
if (window.Echo) {
    window.Echo.private(`campaigns.${uid}`)
        .listen('.campaign.progress', (e) => Livewire.dispatch('campaign-progress', e));
}
```

Not available on shared cPanel/Plesk hosting — it needs a long-running
listener and a spare port.

---

## S3-compatible object storage (F-0.1-009)

Laravel's `s3` disk (already in `config/filesystems.php`) works with AWS S3,
MinIO, Cloudflare R2, DigitalOcean Spaces, Backblaze B2 and Wasabi via
`AWS_ENDPOINT` + `AWS_USE_PATH_STYLE_ENDPOINT`.

```dotenv
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=auto
AWS_BUCKET=emp-uploads
AWS_ENDPOINT=https://<account>.r2.cloudflarestorage.com
AWS_USE_PATH_STYLE_ENDPOINT=true
```

Then Settings → Operations → *Use object storage for uploads*, choosing the
disk. `App\Support\Ops\ObjectStorage::diskName()` is the single place other
code asks where uploads go; it falls back to the `public` disk whenever the
toggle is off or the named disk is not configured, so a typo can never send
uploads into the void.

**Verify before switching production traffic:**

```bash
php artisan ops:storage-check --disk=s3
```

It writes, reads back and deletes a probe object, and prints the real driver
error on failure.

Note: the `aws/aws-sdk-php` Flysystem adapter
(`league/flysystem-aws-s3-v3`) must be installed for the `s3` driver —
`composer require league/flysystem-aws-s3-v3 "^3.0"`. `ops:storage-check`
reports the missing-adapter exception verbatim if it is not.

---

## Redis (F-0.1-004/014)

Covered in [deploy-mysql.md](deploy-mysql.md#queues--redis). Workers on
dedicated sending nodes connect to a **central** Redis (`REDIS_HOST`), not
localhost, and each node should set a distinct `EMP_NODE_NAME` so the §6.6
worker registry stays readable.
