#!/usr/bin/env sh
# ---------------------------------------------------------------------------
# One-command bootstrap for the docker compose stack
# (spec §6.9 F-6.9-005 / §0.1 F-0.1-011, WP-24)
#
#   cp .env.docker .env
#   docker compose up -d
#   ./docker/bootstrap.sh
#
# Idempotent: safe to re-run after a `docker compose down` or a code update.
# ---------------------------------------------------------------------------
set -eu

COMPOSE="${COMPOSE:-docker compose}"
APP="${APP_SERVICE:-app}"

run() {
    # shellcheck disable=SC2086
    $COMPOSE exec -T "$APP" "$@"
}

echo "==> Waiting for MySQL to accept connections"
tries=0
until run php -r 'new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));' >/dev/null 2>&1; do
    tries=$((tries + 1))
    if [ "$tries" -gt 60 ]; then
        echo "!! MySQL did not become ready in 120s" >&2
        exit 1
    fi
    sleep 2
done
echo "    ready"

echo "==> Installing PHP dependencies"
run composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader

if ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
    echo "==> Generating application key"
    run php artisan key:generate --force
fi

echo "==> Publishing Filament panel assets"
# Required after every install/upgrade: without it both panels render unstyled.
run php artisan filament:assets

echo "==> Running migrations"
run php artisan migrate --force

echo "==> Seeding baseline data (roles, languages, currencies, gateways)"
run php artisan db:seed --force

echo "==> Importing countries and zones (§3.23)"
run php artisan emp:geo-import

echo "==> MySQL partitioning (§0.1 / D-03)"
run php artisan db:partition

echo "==> Linking public storage"
run php artisan storage:link || true

echo "==> Caching config and views"
# NOTE: route:cache is deliberately NOT run — module route files perform
# boot-time wiring (D-41/D-51). See routes/modules/ops.php.
run php artisan config:cache
run php artisan view:cache

echo "==> Health check"
run php artisan ops:index-health || true
$COMPOSE exec -T "$APP" php -r 'echo "app container OK\n";'

cat <<'EOF'

Stack is up:
  app        http://localhost:8080
  mailpit    http://localhost:8025
  mysql      127.0.0.1:33060 (emp / secret)

Next steps:
  - create the first administrator:  docker compose exec app php artisan tinker
  - or open http://localhost:8080/install to run the wizard
  - demo dataset:                    docker compose exec app php artisan emp:demo-seed
  - load-balancer probe:             curl -s http://localhost:8080/health

EOF
