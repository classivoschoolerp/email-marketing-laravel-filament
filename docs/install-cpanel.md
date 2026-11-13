# Install on cPanel / WHM shared or VPS hosting

cPanel is the hardest supported target: PHP runs under `open_basedir`, there
is no Supervisor, and cron granularity is per-minute at best. Everything below
works within those limits. Read [requirements.md](requirements.md) first.

> **Honest warning.** Shared cPanel hosting can run this platform, but not at
> volume: without a real process supervisor the queue workers are restarted by
> cron, so throughput is capped by how many processes your host lets you run.
> For serious sending use [install-vps.md](install-vps.md).

---

## 1. Create the account and pick PHP 8.3

*WHM → Account Functions → Create a New Account*, then
*cPanel → Select PHP Version → 8.3* and enable:

`bcmath` `curl` `dom` `fileinfo` `gd` `intl` `json` `mbstring` `openssl`
`pdo_mysql` `posix` `tokenizer` `xml` `zip` (+ `imap` if you poll bounces over
IMAP, + `redis` if your host offers it)

In *PHP Options* on the same page:

```
memory_limit = 256M
max_execution_time = 300
upload_max_filesize = 64M
post_max_size = 64M
max_input_vars = 5000
```

## 2. Upload and point the document root at `public/`

Two supported layouts:

**A. Subdomain (recommended).** Upload the project to
`/home/USER/apps/emp`, then create a subdomain whose document root is
`/home/USER/apps/emp/public`. Nothing but `public/` is ever web-reachable.

**B. Main domain.** Upload the project to `/home/USER/emp`, move the contents
of `emp/public` into `public_html`, and edit `public_html/index.php`:

```php
require __DIR__.'/../emp/vendor/autoload.php';
$app = require_once __DIR__.'/../emp/bootstrap/app.php';
```

Never leave `.env`, `storage/` or `vendor/` under `public_html`.

## 3. `open_basedir`

cPanel usually sets, per account:

```
open_basedir = /home/USER/:/usr/lib/php:/usr/local/lib/php:/tmp
```

Consequences, and how this codebase handles them:

| Symptom | Cause | Handling |
|---|---|---|
| "File not found" for a path that exists | `open_basedir` denies it | `App\Support\Files::inspect()` reports `allowed: false` separately, and the installer's requirements screen labels it *(blocked by open_basedir)* |
| `realpath()` returns false + a warning | Classic `open_basedir` behaviour | This codebase never uses `realpath()` to validate a path. `Files::normalize()` / `Files::contains()` are purely lexical |
| Backups fail | `BACKUPS_DIRECTORY` outside `/home/USER` | Keep it inside the account, e.g. `/home/USER/apps/emp/storage/backups` |
| Temp files fail | `sys_get_temp_dir()` points outside the allowlist | Use `App\Support\Files::temporaryDirectory()`, which always resolves under `storage/app/tmp` |
| `mysqldump` missing | `proc_open` disabled or binary absent | Take backups from *cPanel → Backup* instead; the admin Backups page still lists and prunes what exists |

**Rules for any new code** (audited for WP-24, see the `App\Support\Files`
docblock): never build an absolute path from user input; resolve uploads
through a Storage disk (`Files::storagePath()` strips traversal); check
`Files::accessible()` before touching an operator-configured absolute path so
the error names `open_basedir` instead of "not found".

## 4. Database

*cPanel → MySQL Databases*: create a database and user (they get the
`USER_` prefix), grant ALL PRIVILEGES. Note the full prefixed names — the
installer needs them.

Set the collation to `utf8mb4_unicode_ci` (*phpMyAdmin → Operations*) if your
host defaults to `latin1`.

## 5. Composer

If *Terminal* is available:

```bash
cd ~/apps/emp
php -d memory_limit=-1 /opt/cpanel/composer/bin/composer install --no-dev --optimize-autoloader
```

If it is not, run `composer install --no-dev --optimize-autoloader` locally on
a machine with the same PHP version and upload the resulting `vendor/`.

Then publish the Filament panel assets — **required after every install and
upgrade**, and the usual cause of an admin page that loads with no styling:

```bash
/usr/local/bin/ea-php83 ~/apps/emp/artisan filament:assets
```

If you uploaded `vendor/` from another machine, upload `public/css/filament/`
and `public/js/filament/` with it, or run the command above over SSH.

## 6. Run the installer

Browse to `https://your-domain/install`. The requirements screen shows the
`open_basedir` prefixes in force so you can confirm the account is set up
correctly. After the last step it writes `storage/app/installed` and `/install`
returns 404.

## 7. Cron

*cPanel → Cron Jobs*, every minute:

```
* * * * * /usr/local/bin/ea-php83 /home/USER/apps/emp/artisan schedule:run >/dev/null 2>&1
```

Use the exact binary your host provides (`ea-php83`, `/usr/local/bin/php`, …) —
`php` alone often resolves to an older CLI.

## 8. Queue workers without Supervisor

cPanel has no Supervisor, so run bounded workers from cron. They exit on their
own, and cron starts the next one:

```
* * * * * /usr/local/bin/ea-php83 /home/USER/apps/emp/artisan queue:work --stop-when-empty --max-time=55 --tries=3 >/dev/null 2>&1
*/2 * * * * /usr/local/bin/ea-php83 /home/USER/apps/emp/artisan queue:heartbeat --once >/dev/null 2>&1
```

* `--max-time=55` guarantees the process is gone before the next minute.
* `--stop-when-empty` avoids burning a slot when there is nothing to do.
* Add two or three identical lines to increase concurrency, staying inside your
  host's process limit.
* The `queue:heartbeat --once` line keeps the node visible in
  Admin → Worker nodes.

`.env` should stay on `QUEUE_CONNECTION=database` and `CACHE_STORE=database`
unless your host actually offers Redis.

## 9. Email sending

Shared hosts throttle `sendmail` hard and their IPs are usually poorly
reputed. Configure an external delivery server (SMTP or an ESP API) under
Admin → Delivery servers instead of the local MTA, and set SPF/DKIM for your
sending domain (§2.9.7).

## 10. Backups

`backups:run` is scheduled nightly. On shared hosting `mysqldump` may be
unavailable; if the cron run history shows failures, rely on cPanel's own
backup and keep the uploads archive (it only needs `ext-zip`):

Settings → Operations → *Include uploads in backups*, and keep
*Keep backups (days)* low — shared accounts have small disk quotas.

## 11. Known limitations on cPanel

| Feature | Status |
|---|---|
| Laravel Horizon | Not available (needs Redis + long-running processes) |
| Laravel Octane | Not available (needs a persistent server process) |
| Reverb websockets | Not available (needs a long-running listener + a spare port) |
| Local MTA drivers (sendmail/exim/qmail) | Usually blocked by `disable_functions=proc_open` |
| High-volume sending | Capped by the host's process/CPU limits |

Everything else — campaigns, automations, imports/exports, tracking, reports,
GeoIP, billing — works.
