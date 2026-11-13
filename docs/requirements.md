# Server requirements (spec §6.9 F-6.9-002)

The installer (`/install`) checks every item on this page and refuses to
continue while a required one fails. `App\Services\Install\Installer::requirements()`
is the single source of truth; this document explains *why* each item is there.

## Minimum

| Item | Minimum | Notes |
|---|---|---|
| OS | Linux (Debian 12 / Ubuntu 22.04+ / RHEL 9 / Alma 9) | Windows works for development only — no `pcntl`, no Supervisor |
| PHP | **8.2** (8.3 recommended) | `declare(strict_types=1)` everywhere; 8.3 is what CI runs |
| Database | MySQL 8.0 / 8.4, MySQL 5.7, or MariaDB 10.6+ | Partitioned high-volume tables need MySQL 8 (`php artisan db:partition`, D-03) |
| RAM | 2 GB | 4 GB+ once you run more than ~4 worker processes |
| CPU | 2 cores | `php artisan tinker --execute="dump(App\Support\System\Cpu::suggestedWorkers())"` prints the suggested layout |
| Disk | 10 GB + room for logs/backups | Delivery logs and tracking tables dominate; retention is configurable (§3.25.2) |
| Web server | nginx 1.20+ or Apache 2.4 with mod_rewrite | Document root **must** be `public/` |
| Process supervisor | Supervisor or systemd | Queue workers are mandatory (docs/ARCHITECTURE.md rule 5) |
| Cron | One system cron entry, every minute | Everything scheduled hangs off it |
| Redis | 6+ | Production queues/cache/locks/counters (D-04). The `database` driver works but does not scale |

## PHP extensions

**Required** — the installer blocks without them:

`bcmath` `ctype` `curl` `dom` `fileinfo` `json` `mbstring` `openssl` `pcre`
`pdo` (+ `pdo_mysql`) `tokenizer` `xml` `zip`

**Recommended** — the installer warns only:

| Extension | Used for |
|---|---|
| `intl` | Locale-aware formatting and IDN domains |
| `gd` (or `imagick`) | Template image handling and thumbnails |
| `redis` (phpredis) | Redis queue/cache client (D-04) |
| `pcntl`, `posix` | `queue:work --timeout` signals and **Laravel Horizon** (§0.1 F-0.1-005) |
| `imap` | IMAP bounce/FBL polling (the POP3 path needs no extension — D-67) |
| `zip` | Uploads archive inside backups (§6.6 F-6.6-006) |

## php.ini

```ini
memory_limit = 256M        ; 512M when importing very large lists
max_execution_time = 300   ; CLI is unlimited; this is for the admin UI
upload_max_filesize = 64M  ; import files, .mmdb GeoIP databases (~60 MB)
post_max_size = 64M
max_input_vars = 5000      ; the §3.5 permission matrix posts a lot of fields
```

`open_basedir` is supported — see [install-cpanel.md](install-cpanel.md) for
the exact prefixes needed. `disable_functions` must not include
`proc_open`/`proc_get_status` if you want local MTA drivers (sendmail, exim,
qmail, mysqldump backups); everything else degrades gracefully.

## Writable directories

`storage/`, `storage/app`, `storage/framework`, `storage/logs`,
`bootstrap/cache`. The installer reports each one separately and distinguishes
"not writable" from "blocked by open_basedir".

## Outbound network

| Destination | Why | Optional? |
|---|---|---|
| SMTP / ESP API hosts | Sending (§2.9.2) | No |
| DNS resolvers (UDP/TCP 53) | SPF/DKIM/DMARC verification (§2.9.7) | No |
| POP3/IMAP hosts | Bounce + FBL polling (§2.9.4/5) | Yes |
| `download.maxmind.com` | GeoIP auto-update (§3.23 F-3.23-002) | Yes |
| `ip-api.com` / `ipinfo.io` | Hosted GeoIP providers | Yes |
| Payment gateway APIs | §3.26 billing | Yes |

## Verifying after install

```bash
php artisan ops:index-health     # §6.6 F-6.6-004 index health
php artisan ops:storage-check    # §0.1 F-0.1-009 object storage round trip
curl -s http://localhost/health  # §6.9 F-6.9-007 load-balancer probe
```
