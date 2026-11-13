# Upgrades — the live upgrade manager

Spec §6.2. Implemented by WP-41.

This file is the reference for anyone who is about to press **Upgrade now**, and
for anyone reading it at 3am because an upgrade did not finish. Read §1 and §7
before you use the feature. They are short, and they are the two sections that
matter.

---

## 1. Trust model — read this before you upgrade anything

**An upgrade overwrites the running application's source code and then runs
migrations against your live database.**

Not "downloads an update". Not "applies a patch safely". The one-click upgrade:

- replaces PHP files that the very next HTTP request will execute;
- runs `php artisan migrate --force`, which can add, alter and drop columns in
  the database holding every subscriber, campaign and invoice you have;
- clears every cache, including compiled views and routes;
- takes the site offline while it does so.

It does all of that from an archive downloaded off the internet.

### What actually protects you

| Control | Where | What it stops |
|---|---|---|
| `system.upgrade` permission | `App\Services\Upgrade\UpgradePermissions`, `AdminModules::EXTRA_PERMISSIONS` | non-operators reaching the feature at all |
| Typed confirmation phrase (`UPGRADE`) | `UpgradeRunner::assertConfirmed()`, `emp-upgrade.confirmation_phrase` | upgrading by misclick; the button alone can never do it |
| Dry run | `UpgradeRunner::dryRun()` | finding out what a release touches **after** it touched it |
| Nine blocking pre-flight checks | `UpgradePreflight` | upgrading onto a full disk, an old PHP, a dead database or a half-migrated schema |
| SHA-256 checksum verification | `UpgradeRunner::verifyChecksum()` | running an archive that is not the one the publisher signed off |
| https-only download URLs | `ReleaseInfo::httpsUrl()` | a plaintext mirror substituting the package |
| Release-manifest cross-check | `ReleaseArchive::assertMatches()` | a mis-published or swapped download claiming to be the advertised version |
| Zip-slip / symlink / zip-bomb guards | `ReleaseArchive::scan()` | `../`, absolute paths, `C:\`, null bytes, stream wrappers, links out of the tree, disk exhaustion |
| Blocked file extensions | `emp-upgrade.blocked_file_extensions` | a PHP release shipping native binaries |
| Protected paths | `UpgradeTarget::isProtected()` | the upgrade touching `.env`, plugins, themes, backups or uploads |
| Automatic pre-upgrade backup | `UpgradeBackup` → WP-24 `backups:run` | having nothing to restore |
| Per-file rollback snapshot | `UpgradeRunner::applyFiles()` | a half-written file tree with no way back |
| Maintenance mode | `MaintenanceMode` (`artisan down`) | requests executing a half-written application |
| Upgrade history log | `upgrade_runs` | not knowing what happened, or what was in flight when it stopped |

None of that makes a hostile release safe. **The release endpoint you configure
is the trust boundary.** If you point `EMP_UPGRADE_ENDPOINT` at a server you do
not control, you have handed that server the ability to run arbitrary code as
your web user. The checksum protects the transport, not the publisher.

Therefore:

1. **`system.upgrade` is the real permission boundary.** Grant it to as few
   admins as possible. It is deliberately one coarse key — a "view upgrades but
   not apply them" split would imply a safety that does not exist, the same
   reasoning WP-36 applied to `plugins.manage`.
2. **Dry-run first, every time.** It downloads, verifies and reports the exact
   file plan without writing a byte.
3. **Read the changelog.** It is rendered escaped, never as HTML.
4. **Keep the backups.** The pre-upgrade dump is the only thing that undoes a
   migration.
5. **Nothing is scheduled.** There is no auto-upgrade cron and there will not be
   one. `upgrade:check` (notification only) is the only scheduled task.

---

## 2. What is never touched (F-6.2-006)

`emp-upgrade.protected_paths` is the list of paths an upgrade will not write, in
any mode, for any release. A package that contains one of them has that entry
**skipped**, and the skip is recorded on the upgrade run's `file_plan.protected`
so you can see exactly what the release tried to touch.

```
.env
.env.backup
storage/app/plugins        ← WP-36 installed plugins (D-94)
storage/app/themes         ← WP-38 installed themes
storage/app/backups        ← your backups
storage/app/public         ← customer uploads and assets
storage/app/private
storage/app/upgrades       ← downloads and rollback snapshots
storage/backups
storage/framework
storage/logs
database/database.sqlite
public/storage
```

This is asserted, not asserted-to: `tests/Feature/Upgrade/UpgradeRunnerTest.php`
applies a release archive that deliberately contains a hostile `.env`, a
replacement `plugin.json`, a replacement `theme.json` and a "DESTROYED" backup
file, then asserts every one of those files still has its original contents
afterwards.

**Subscriber data, campaigns, settings, plugins and themes therefore survive an
upgrade by construction.** The only thing that can change them is a migration
the release ships, and that is what the pre-upgrade backup exists for.

### The version number after an upgrade

`config('emp.core_version')` is the canonical version (D-95) and it ships inside
`config/emp.php` — which a release archive replaces, so the version moves with
the code. But if your `.env` pins `EMP_CORE_VERSION`, that wins, and `.env` is a
protected path this upgrade will never write. When that happens the run says so
in its notes (`upgrade.notes.version_pinned`) rather than silently reporting the
old version. Update `.env` yourself.

---

## 3. Configuring the release feed

Nothing is enabled by default. A self-hosted install must opt in to being told
what to run.

```dotenv
EMP_UPGRADE_ENDPOINT=https://releases.example.com/emp/latest.json
EMP_UPGRADE_CHANNEL=stable
EMP_UPGRADE_NOTIFY=true            # email operators when a release appears
# EMP_UPGRADE_NOTIFY_TO=ops@example.com   # default: every admin with system.upgrade
```

The endpoint must return:

```json
{
  "version": "1.1.0",
  "released_at": "2026-08-13T09:00:00Z",
  "download_url": "https://releases.example.com/emp/emp-1.1.0.zip",
  "checksum": "9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08",
  "checksum_algo": "sha256",
  "changelog": "### 1.1.0\n- Faster sending\n- Fixed a bounce parser bug",
  "min_php": "8.2.0",
  "requires_core": ">=1.0.0",
  "mandatory": false,
  "size": 41943040
}
```

A `channels` object keyed by channel name is also accepted:

```json
{ "channels": { "stable": { …release… }, "beta": { …release… } } }
```

Rules the platform applies to that document:

- `version` must be strict `major.minor.patch`. Anything else = "unknown", never
  "up to date".
- `download_url` must be **https**. A plain-http URL is dropped, which makes the
  release announced-but-not-installable.
- `checksum` must be 32–128 hex characters and `checksum_algo` one of
  `sha256` / `sha384` / `sha512`. Without both, the release is announced and the
  Upgrade button never appears.
- `requires_core` is evaluated against the running version with the same
  constraint grammar plugins use (`App\Plugins\CoreVersion`, docs/plugins.md).
- An unreachable or unparsable feed **never** changes behaviour. It reports
  "unknown". A vendor going offline must not affect a running installation.

The document is cached for `emp-upgrade.release.cache_ttl` seconds (30 min
default). **Check for updates** on the admin page busts the cache.

### Release archive format

A `.zip` whose contents are laid out exactly like the application root. Either
layout works — files at the root, or wrapped in a single top-level folder
(`emp-1.1.0/…`), which is unwrapped automatically.

An optional `release.json` at the archive root:

```json
{ "version": "1.1.0" }
```

When present its version **must** match what the feed advertised; a mismatch
rejects the package. That mismatch is either a mis-published release or a
swapped download, and neither should be installed.

---

## 4. Pre-flight checks (F-6.2-003)

Every check below **blocks** the upgrade when it fails. There is no `--force`
flag and there will not be one — every force flag ever shipped has eventually
been used on a full disk.

| Check | Blocking | Fails when |
|---|---|---|
| `disk_space` | yes | free space < `min_free_disk_mb` + 3× the archive (download + staging + rollback snapshot) |
| `php_version` | yes | PHP < the release's `min_php` (or `emp-upgrade.preflight.min_php_version`) |
| `database` | yes | `getPdo()` / `select 1` fails |
| `pending_migrations` | yes | the schema is already behind the running code |
| `core_version` | yes | the release is not newer, `requires_core` is unsatisfied, or the release is not checksum-verifiable |
| `target_writable` | yes | the application directory is missing, unwritable, or refused by `open_basedir` |
| `backup_directory` | yes | the pre-upgrade backup could not be written |
| `zip_extension` | yes | `ext-zip` is missing |
| `maintenance` | **no** | maintenance mode cannot be enabled — the upgrade proceeds unshielded and says so |

`pending_migrations` deserves a word: a pending migration before an upgrade means
the install is already half-deployed. Layering a new release's migrations on top
of that is how a database ends up in a state no `migrate` can reason about. Run
`php artisan migrate --force` first, then upgrade.

A blocked run is still recorded, with status `blocked` and the reasons in
`error`. Nothing is downloaded and nothing is written.

---

## 5. What a run actually does

```
preflight → download → verify_checksum → backup → maintenance_on
         → apply_files → migrate → clear_caches → maintenance_off → cleanup
```

Each step is appended to `upgrade_runs.steps` as it completes, and
`upgrade_runs.current_step` names the step in flight. Nothing destructive
happens before `verify_checksum` passes.

- **backup** calls the existing WP-24 `backups:run` — the same command the
  scheduled daily backup uses, so there is one backup implementation and one
  restore procedure. It does *not* trust the exit code: `backups:run` is a §0.6
  cron job (D-40) and returns success without doing anything when an operator has
  disabled that job in §3.25.11 settings. `UpgradeBackup` diffs the backup
  directory across the call and **fails the upgrade** unless a new database
  artefact really appeared.
- **apply_files** copies the previous contents of every file it is about to
  overwrite into `storage/app/upgrades/rollback/{run-uid}/` first, and records
  every file it *creates* in `upgrade_runs.created_files`. Protected paths are
  re-checked here, not just at plan time.
- **migrate** runs `migrate --force`.
- **clear_caches** runs `config:clear`, `cache:clear`, `view:clear`,
  `route:clear` and **`filament:assets`**. That last one is not optional — D-87:
  skipping it renders both panels unstyled.
- **cleanup** deletes the downloaded archive and **keeps the rollback snapshot**.

### Dry run

`upgrade:apply --dry-run`, or the **Dry run** button. Runs pre-flight, downloads,
verifies the checksum, opens the archive and computes the complete file plan —
then stops. No backup, no maintenance mode, no writes, no migrations. The run is
recorded with `dry_run = true` so the plan is auditable afterwards.

### From the CLI

```bash
php artisan upgrade:check                 # is there anything new?
php artisan upgrade:check --notify        # …and email operators (deduped per version)
php artisan upgrade:apply --dry-run       # full plan, nothing written
php artisan upgrade:apply --confirm=UPGRADE
```

`--confirm` must carry the exact phrase from `emp-upgrade.confirmation_phrase`.

---

## 6. Rollback

Two kinds, and they are not the same thing.

**Files: automatic.** Any failure after the first write triggers a restore from
the snapshot — files this run created are deleted, files it replaced are copied
back. The run then ends as `rolled_back`. If the restore itself could not
complete, the run ends as `failed` and the snapshot path is on the row.

**Files: on demand.** A successful upgrade keeps its snapshot, so **Roll back
files** on the history row (or `UpgradeRunner::rollback()`) puts the previous
code back. Use it when a release is bad but the database is fine. Snapshots live
in `storage/app/upgrades/rollback/{run-uid}/` — delete them yourself once you are
confident in a release.

**Database: never automatic.** `migrate:rollback` on a release whose migrations
half-applied is how data gets destroyed, so this feature does not do it. The
documented recovery is to restore the dump taken minutes earlier. This is a
deliberate limitation, stated here rather than hidden.

---

## 7. Recovery — an upgrade half-applied

Start at **Admin → System → Upgrades**. The history row tells you exactly where
it stopped: `current_step` on a run still marked `running`, or the last `failed`
step. Match it below.

### 7.1 The row says `blocked`

Nothing happened. Fix what the check reported and try again.

### 7.2 The row says `rolled_back`, and the failed step is `download`, `verify_checksum` or `backup`

Nothing was written. The installation is exactly as it was. Read `error`, fix the
cause (disk, network, a disabled `backups:run` job), try again.

### 7.3 The row says `rolled_back`, and the failed step is `apply_files`

Files were restored from the snapshot; the database was never touched. Notes will
say *"Every file this run had written was restored"*. Confirm the site responds,
then investigate `error`.

### 7.4 The row says `rolled_back`, and the failed step is `migrate` or `clear_caches`

**This is the case that needs you.** The file tree was restored to the previous
release, but the database may be partially migrated — old code against a newer
schema. The run's notes say so and name the backup.

```bash
# 1. Stay offline.
php artisan down

# 2. Restore the pre-upgrade database dump named on the upgrade run.
#    (Admin → Reports → Backups prints the exact command for your driver.)
#    MySQL:
mysql -h HOST -u USER -p DATABASE < /path/to/backup-YYYYMMDD-HHMMSS.sql
#    SQLite:
cp /path/to/backup-YYYYMMDD-HHMMSS.sqlite database/database.sqlite

# 3. Confirm code and schema agree.
php artisan migrate:status

# 4. Back up online.
php artisan up
```

Then work out why the migration failed before trying again. A migration that
fails once will fail again.

### 7.5 The row still says `running`

The process died mid-flight — OOM, PHP timeout, someone closed the tab, the host
rebooted. `current_step` names what was in progress. Nothing will resume on its
own.

- **`apply_files`** — the tree may be half-written. Use **Roll back files** on
  that row; it restores from the snapshot and deletes what the run created.
  Verify with `php artisan about` and by loading `/admin`.
- **`migrate`** — treat it as §7.4: restore the database dump.
- Anything earlier — nothing was written; the row can be ignored.

### 7.6 The site is stuck in maintenance mode

```bash
php artisan up
```

The runner always tries to lift it, including on failure, but a process killed
between `down` and `up` cannot. This is also why maintenance mode is
`artisan down` rather than the WP-04 `SiteOffline` setting (D-24): `SiteOffline`
deliberately lets `admin*` through, which is exactly wrong when the admin panel
is the thing being overwritten.

### 7.7 The panels look unstyled after an upgrade

`filament:assets` did not run. Run it (D-87):

```bash
php artisan filament:assets
```

### 7.8 Nothing above works

You have the pre-upgrade database dump, the uploads archive and the file
snapshot, all named on the upgrade run. Restore all three onto the previous
release and you are back where you started. That is the whole point of taking
them before the first write.

---

## 8. Testing note

The upgrade tests never touch the real installation, and cannot:
`emp-upgrade.target_path` is injected with a temp directory by every test, and
`UpgradeTarget::assertSafe()` throws outright if the resolved target is
`base_path()` while `APP_ENV=testing`. There is a test asserting that too
(*"refuses outright to run against the real installation"*).

Post-upgrade cache commands are also overridden in tests — running
`filament:assets` from a test would publish assets into the developer's own
checkout. A separate test asserts the shipped default still contains it.

---

## 9. Known limits — stated, not hidden

- **Migrations are not reversed.** §6 and §7.4 are the answer. There is no
  automatic database rollback and adding one would be more dangerous than not
  having it.
- **`vendor/` is whatever the release ships.** The upgrade does not run
  `composer install`. A release archive that omits `vendor/` will produce a
  broken install; that is a publishing error, not something this feature can
  detect. Publish full artefacts.
- **The version pin in `.env` wins** (§2). The upgrade cannot fix it because it
  must never write `.env`.
- **A file the release deletes stays.** The upgrade adds and replaces; it does
  not remove files that vanished between releases. Orphaned files are inert but
  they accumulate.
- **Multi-server installs need care.** This upgrades the host it runs on. On a
  cluster, upgrade one node, then deploy the same artefact to the others — or
  keep using your existing deployment pipeline, which this feature does not
  replace.
- **`route:cache` is unsafe** for the same reason as D-41/D-51: module route
  files carry boot-time wiring. The deploy scripts deliberately do not run it.

---

## 10. Files

| Path | What |
|---|---|
| `config/emp-upgrade.php` | every knob, with the safety notes |
| `app/Services/Upgrade/UpgradeTarget.php` | where writes may land; the protected-path rule |
| `app/Services/Upgrade/ReleaseChecker.php` | the release feed (F-6.2-001) |
| `app/Services/Upgrade/ReleaseInfo.php` | one release, normalised |
| `app/Services/Upgrade/UpgradePreflight.php` | the nine checks (F-6.2-003) |
| `app/Services/Upgrade/ReleaseArchive.php` | the archive safety gate |
| `app/Services/Upgrade/UpgradeBackup.php` | the pre-upgrade backup hook (F-6.2-004) |
| `app/Services/Upgrade/MaintenanceMode.php` | the shield (F-6.2-005) |
| `app/Services/Upgrade/UpgradeRunner.php` | the run itself (F-6.2-002/006) |
| `app/Services/Upgrade/ReleaseNotifier.php` | the new-release email (F-6.2-008) |
| `app/Models/UpgradeRun.php` | the history log (F-6.2-007) |
| `app/Filament/Admin/Pages/UpgradeManagerPage.php` | the dashboard |
| `app/Console/Commands/UpgradeCheckCommand.php` | `upgrade:check` |
| `app/Console/Commands/UpgradeApplyCommand.php` | `upgrade:apply` |
| `routes/modules/upgrade.php` | the daily check schedule |
| `tests/Feature/Upgrade/` | the tests, including the protected-path proof |
