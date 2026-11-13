# Install on a standalone Linux VPS

Target: Ubuntu 22.04/24.04 or Debian 12, root access, nginx + PHP-FPM 8.3 +
MySQL 8 + Redis + Supervisor. Read [requirements.md](requirements.md) first.

Everything below is copy-pasteable. Replace `emp.example.com` and
`/var/www/emp` with your own values.

---

## 1. Packages

```bash
sudo apt update
sudo apt install -y nginx mysql-server redis-server supervisor unzip git \
    php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl \
    php8.3-zip php8.3-bcmath php8.3-gd php8.3-intl php8.3-redis php8.3-imap
```

`php8.3-cli` ships `pcntl`/`posix`, which `queue:work` signal handling and
Laravel Horizon need.

Composer:

```bash
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
```

## 2. Database

```bash
sudo mysql <<'SQL'
CREATE DATABASE emp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'emp'@'localhost' IDENTIFIED BY 'CHANGE-ME';
GRANT ALL PRIVILEGES ON emp.* TO 'emp'@'localhost';
FLUSH PRIVILEGES;
SQL
```

`utf8mb4` is not optional — CJK and emoji subject lines are tested end to end
(docs/ARCHITECTURE.md conventions).

## 3. Application files

```bash
sudo mkdir -p /var/www/emp && sudo chown -R $USER:www-data /var/www/emp
cd /var/www/emp
# upload/clone the release here
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
php artisan storage:link
```

## 4. nginx

```nginx
server {
    listen 80;
    server_name emp.example.com;
    root /var/www/emp/public;          # NEVER the project root
    index index.php;

    client_max_body_size 64m;          # imports + .mmdb uploads

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/emp /etc/nginx/sites-enabled/emp
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d emp.example.com   # TLS
```

## 5. Run the installer

Open `https://emp.example.com/install` and follow the five steps
(requirements → database → administrator → license → cron/queue). It writes
`.env`, runs `migrate` + `db:seed` + `emp:geo-import`, creates the Super Admin
and finally writes `storage/app/installed`, after which `/install` returns 404.

> The wizard locks itself out in two ways: the `storage/app/installed` marker
> it writes at the end, **and** the presence of any administrator account. An
> existing deployment upgraded to a version that ships the wizard therefore
> gets a 404 on `/install` immediately — it never becomes a public
> Super-Admin factory.

Prefer the CLI? Run the same steps manually:

```bash
php artisan filament:assets       # REQUIRED — panels render unstyled without it
php artisan migrate --force
php artisan db:seed --force
php artisan emp:geo-import
php artisan db:partition          # MySQL only (D-03)
touch storage/app/installed
```

> **After every `composer update` / upgrade, re-run `php artisan filament:assets`.**
> It republishes the panel CSS/JS into `public/`; skipping it is the usual cause
> of "the admin page loads but has no styling".

## 6. Cron (mandatory)

```bash
sudo -u www-data crontab -e
```

```cron
* * * * * /usr/bin/php8.3 /var/www/emp/artisan schedule:run >> /dev/null 2>&1
```

That single entry drives every §0.6 job: campaign sends, autoresponders,
recurring campaigns, automation ticks, bounce/FBL polling, stats rollups,
quota resets, backups, log pruning, license checks — and the WP-24 `ops:monitor`
sweep. Admin → Operations shows the recent runs; an empty table means cron is
not running.

## 7. Queue workers (mandatory)

`/etc/supervisor/conf.d/emp.conf`:

```ini
[program:emp-worker-priority]
command=/usr/bin/php8.3 /var/www/emp/artisan queue:work redis --queue=transactional,campaigns-high --tries=3 --max-time=3600
directory=/var/www/emp
user=www-data
autostart=true
autorestart=true
numprocs=2
process_name=%(program_name)s_%(process_num)02d
stopwaitsecs=3600
stdout_logfile=/var/log/emp-worker-priority.log

[program:emp-worker-send]
command=/usr/bin/php8.3 /var/www/emp/artisan queue:work redis --queue=campaigns-send,automation --tries=3 --max-time=3600
directory=/var/www/emp
user=www-data
autostart=true
autorestart=true
numprocs=6
process_name=%(program_name)s_%(process_num)02d
stopwaitsecs=3600

[program:emp-worker-ingest]
command=/usr/bin/php8.3 /var/www/emp/artisan queue:work redis --queue=imports,bounces,webhooks,exports,maintenance --tries=3 --max-time=3600
directory=/var/www/emp
user=www-data
autostart=true
autorestart=true
numprocs=2
process_name=%(program_name)s_%(process_num)02d
stopwaitsecs=3600

; §6.6 F-6.6-008/009 — keeps this node visible in the admin worker registry
; between jobs and exits when an operator raises the drain flag.
[program:emp-heartbeat]
command=/usr/bin/php8.3 /var/www/emp/artisan queue:heartbeat --queues=campaigns-send,transactional --interval=30
directory=/var/www/emp
user=www-data
autostart=true
autorestart=true
numprocs=1
```

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl status
```

**How many processes?** Admin → Operations prints the detected core count and
a suggested layout, or:

```bash
php artisan tinker --execute="dump(App\Support\System\Cpu::cores(), App\Support\System\Cpu::suggestedWorkers());"
```

The detection is cgroup-aware, so a 2-CPU container will not claim its host's
64 cores.

## 8. Redis + .env production values

```dotenv
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1          # a CENTRAL host when workers run on other nodes
EMP_NODE_NAME=web-01          # stable name per node for the worker registry
```

Distributed sending nodes run only the Supervisor programs above, pointed at
the same Redis and MySQL. Give each one a distinct `EMP_NODE_NAME`.

## 9. Load balancer / uptime probe

`GET /health` returns JSON plus 200 (healthy) or 503 (a required dependency is
down). `?detailed=1` adds queue depth.

```json
{"status":"ok","timestamp":"...","checks":{"database":{"ok":true,"required":true},...}}
```

* `database` and `cache` are **required** — a failure returns 503.
* `queue` and `workers` are informational: a backed-up queue must not pull a
  healthy web node out of rotation.
* Laravel's `/up` remains the cheap liveness probe.
* **Maintenance mode (§3.25.1 site-offline) intentionally makes `/health`
  return 503** so enabling it drains the load balancer.

## 10. Deployment / upgrades

```bash
php artisan down
git pull && composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:partition
php artisan config:cache && php artisan view:cache
php artisan queue:restart
php artisan up
```

> **Do not run `php artisan route:cache`.** Several module route files perform
> boot-time wiring — the Filament admin panel extensions
> (`routes/modules/admin.php`, D-41), the sending driver registry
> (`routes/modules/sending.php`, D-51) and the WP-24 operations bindings,
> queue listeners and schedules (`routes/modules/ops.php`). Cached routes skip
> those files, so the wiring would silently disappear. Move those blocks into
> service providers before enabling route caching.

## 11. Backups

`backups:run` is scheduled nightly at 01:00 and writes to `storage/backups`
(override with `BACKUPS_DIRECTORY`). It dumps the database (`mysqldump` must be
on `PATH`) and, when the Operations setting is on, zips `storage/app/public`
and `storage/app/private`. Retention and the uploads toggle live in
Settings → Operations; the Backups admin page lists, downloads, deletes and
prints the exact restore commands.

Copy the directory off the box — a backup on the same disk is not a backup.

## 12. Graceful restart & drain

* **Restart** (Admin → Worker nodes → *Restart workers*, or
  `php artisan queue:restart`): each worker finishes its current job and exits;
  Supervisor restarts it with the new code. Safe mid-campaign — every send job
  is idempotent (docs/ARCHITECTURE.md rule 6).
* **Drain** (Admin → Worker nodes → *Drain workers*): raises a cache flag that
  `queue:heartbeat` polls. Heartbeat processes exit, so Supervisor programs
  configured with `autorestart=true` cycle, and the admin page shows the drain
  banner until it is cleared. Use it before host maintenance, then
  *Cancel drain*.

## 13. Optional: Horizon, Octane, Reverb, S3

See [tech-stack.md](tech-stack.md).
