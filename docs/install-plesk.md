# Install on Plesk (Obsidian 18+)

Plesk sits between cPanel and a bare VPS: you get per-domain PHP settings, a
scheduled-tasks UI and — on a Plesk VPS with root — real systemd services.
Read [requirements.md](requirements.md) first.

---

## 1. Domain + PHP

*Websites & Domains → Add Domain*, then **PHP Settings**:

* PHP version: **8.3**, handler **FPM application served by nginx**
* `memory_limit = 256M`
* `max_execution_time = 300`
* `upload_max_filesize = 64M`, `post_max_size = 64M`
* `max_input_vars = 5000`
* `open_basedir`: set to `{WEBSPACEROOT}{/}{:}{TMP}{/}` (the Plesk default) or
  clear it if you have root. See the `open_basedir` section of
  [install-cpanel.md](install-cpanel.md#3-open_basedir) — the same rules apply.

Extensions (*Tools & Settings → PHP Settings* on a Plesk VPS, or your
provider's panel): `bcmath curl dom fileinfo gd intl mbstring openssl
pdo_mysql posix tokenizer xml zip`, plus `pcntl`, `redis` and `imap` when
available.

## 2. Document root

*Websites & Domains → Hosting Settings → Document root* →
`httpdocs/public` (or `/emp/public` if you upload to a sibling directory).

Plesk keeps the domain at `/var/www/vhosts/DOMAIN/`. Upload the release to
`/var/www/vhosts/DOMAIN/emp` and point the document root at
`/var/www/vhosts/DOMAIN/emp/public`.

## 3. nginx directives

*Websites & Domains → Apache & nginx Settings → Additional nginx directives*:

```nginx
client_max_body_size 64m;

location ~ /\.(?!well-known).* {
    deny all;
}
```

If "Proxy mode" is on, Apache serves PHP and nginx only proxies — that is
fine. Turning proxy mode off (nginx + PHP-FPM only) is faster.

## 4. Database

*Databases → Add Database*: name `emp`, a dedicated user, and set the
collation to `utf8mb4_unicode_ci`. Note the host — on Plesk it is usually
`localhost`, but a remote database server is also supported.

## 5. Composer

Plesk ships a Composer integration (*Websites & Domains → Composer*), or over
SSH:

```bash
cd /var/www/vhosts/DOMAIN/emp
/opt/plesk/php/8.3/bin/php /usr/lib/plesk-9.0/composer.phar install --no-dev --optimize-autoloader
chown -R DOMAIN_USER:psacln storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

## 6. Run the installer

Open `https://your-domain/install` and complete the five steps. It writes the
`.env`, migrates, seeds, creates the Super Admin and finally writes
`storage/app/installed`, after which the wizard 404s.

## 7. Scheduled task (mandatory)

*Websites & Domains → Scheduled Tasks → Add Task*:

* Task type: **Run a command**
* Command: `/opt/plesk/php/8.3/bin/php /var/www/vhosts/DOMAIN/emp/artisan schedule:run`
* Run: **Cron style** → `* * * * *`
* Notify: *Errors only*

## 8. Queue workers

### With root (recommended): systemd

`/etc/systemd/system/emp-worker@.service`:

```ini
[Unit]
Description=EMP queue worker %i
After=network.target mysql.service redis.service

[Service]
User=DOMAIN_USER
Group=psacln
WorkingDirectory=/var/www/vhosts/DOMAIN/emp
ExecStart=/opt/plesk/php/8.3/bin/php artisan queue:work redis --queue=transactional,campaigns-high,campaigns-send,automation,imports,bounces,webhooks,exports,maintenance --tries=3 --max-time=3600
Restart=always
RestartSec=5
TimeoutStopSec=3600

[Install]
WantedBy=multi-user.target
```

`/etc/systemd/system/emp-heartbeat.service` (§6.6 worker registry + drain):

```ini
[Unit]
Description=EMP worker heartbeat
After=network.target

[Service]
User=DOMAIN_USER
WorkingDirectory=/var/www/vhosts/DOMAIN/emp
ExecStart=/opt/plesk/php/8.3/bin/php artisan queue:heartbeat --interval=30
Restart=always

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now emp-worker@1 emp-worker@2 emp-worker@3 emp-worker@4 emp-heartbeat
sudo systemctl status 'emp-worker@*'
```

`TimeoutStopSec=3600` matters: without it systemd SIGKILLs a worker mid-send.
The jobs are idempotent (docs/ARCHITECTURE.md rule 6) so nothing is double-sent, but a
clean stop is still better.

### Without root: scheduled tasks

Add a second scheduled task, every minute:

```
/opt/plesk/php/8.3/bin/php /var/www/vhosts/DOMAIN/emp/artisan queue:work --stop-when-empty --max-time=55 --tries=3
```

and a third, every 2 minutes:

```
/opt/plesk/php/8.3/bin/php /var/www/vhosts/DOMAIN/emp/artisan queue:heartbeat --once
```

## 9. Redis

Plesk offers Redis through *Tools & Settings → Updates → Add Components* or
the distro package. Once installed:

```dotenv
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
REDIS_HOST=127.0.0.1
EMP_NODE_NAME=plesk-web-01
```

Restart the workers afterwards (`systemctl restart 'emp-worker@*'` or
Admin → Worker nodes → *Restart workers*).

## 10. Health check

Point the Plesk *Website monitoring* extension (or an external monitor) at
`https://your-domain/health`. It returns 200/503 with a JSON body; see
[install-vps.md §9](install-vps.md#9-load-balancer--uptime-probe).

## 11. Deployment

```bash
cd /var/www/vhosts/DOMAIN/emp
php artisan down
composer install --no-dev --optimize-autoloader
php artisan filament:assets       # REQUIRED after every upgrade
php artisan migrate --force
php artisan db:partition
php artisan config:cache && php artisan view:cache
php artisan queue:restart
php artisan up
```

Do **not** run `php artisan route:cache` — module route files perform
boot-time wiring (D-41/D-51 and `routes/modules/ops.php`).
