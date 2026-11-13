<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Live upgrade manager module (WP-41, spec §6.2)
|--------------------------------------------------------------------------
| Auto-registered with the web middleware group by ModulesServiceProvider.
|
| There are deliberately NO HTTP routes here. Every §6.2 surface is an admin
| Filament page (App\Filament\Admin\Pages\UpgradeManagerPage) or an artisan
| command; exposing "apply an upgrade" as a plain POST endpoint would put the
| most destructive operation in the product one CSRF mistake away from being
| reachable, so it stays inside the panel's auth + permission chain.
|
| Boot-time schedule wiring lives here for the same reason as D-41/D-51 and
| routes/modules/ops.php: routes/console.php is shared across parallel work
| packages. The `route:cache` caveat documented at the bottom of
| routes/modules/ops.php applies to this file too.
*/

if (app()->runningInConsole()) {
    /*
     * F-6.2-008: poll the release feed daily and email operators when a new
     * version appears. A no-op unless BOTH `emp-upgrade.release.endpoint` and
     * `emp-upgrade.notify.enabled` are configured, so a default install never
     * phones home and never mails anyone.
     *
     * Not a §0.6 KNOWN_JOBS row (D-40 fixes that list) — like `ops:monitor`
     * this is monitoring, and it has no per-job toggle. Its switch is the
     * endpoint being set at all.
     *
     * There is deliberately no scheduled `upgrade:apply`. An unattended
     * self-mutating install is not a feature; it is an outage waiting for a
     * bad release.
     */
    Schedule::command('upgrade:check --notify')->dailyAt('05:00');
}
