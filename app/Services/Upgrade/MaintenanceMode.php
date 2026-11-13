<?php

declare(strict_types=1);

namespace App\Services\Upgrade;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Maintenance mode around the destructive part of an upgrade (spec §6.2
 * F-6.2-005, WP-41).
 *
 * A thin, swappable seam over `artisan down` / `artisan up` so the runner can
 * be tested without a test suite putting the developer's application into
 * maintenance mode — tests bind a recording double for this class.
 *
 * `down()` is deliberately best-effort. On a host where storage/framework is
 * not writable, `artisan down` fails; refusing to upgrade for that reason
 * would strand shared-hosting installs on an old version forever. The
 * pre-flight raises it as a WARNING (never a blocker) and the run records
 * that it went ahead unshielded.
 *
 * NOTE ON SCOPE: this is the framework's own maintenance mode, not the WP-04
 * `SiteOffline` middleware (D-24). SiteOffline is an operator-controlled
 * setting for planned downtime and deliberately lets `admin*` through — which
 * is exactly wrong here, since the admin panel is the thing being overwritten.
 * `artisan down` stops every request including admin ones, so a half-written
 * application cannot be executed by anybody.
 */
class MaintenanceMode
{
    public function isDown(): bool
    {
        try {
            return App::isDownForMaintenance();
        } catch (Throwable) {
            return false;
        }
    }

    /** @return bool whether the shield is actually up */
    public function down(): bool
    {
        if (! (bool) config('emp-upgrade.maintenance.enabled', true)) {
            return false;
        }

        try {
            Artisan::call('down', [
                '--retry' => (int) config('emp-upgrade.maintenance.retry_after', 60),
            ]);
        } catch (Throwable) {
            return false;
        }

        return $this->isDown();
    }

    public function up(): bool
    {
        try {
            Artisan::call('up');
        } catch (Throwable) {
            return false;
        }

        return ! $this->isDown();
    }
}
