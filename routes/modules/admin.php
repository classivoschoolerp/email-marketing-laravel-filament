<?php

declare(strict_types=1);

use App\Support\Admin\AdminPanelExtensions;

/*
|--------------------------------------------------------------------------
| Admin module routes (WP-03, spec §3.2–3.5)
|--------------------------------------------------------------------------
| Auto-registered with the web middleware group by ModulesServiceProvider.
| Also wires the WP-03 panel extensions (force-password-reset
| enforcement) — see AdminPanelExtensions for why this
| happens here instead of a service provider.
*/

AdminPanelExtensions::register();
