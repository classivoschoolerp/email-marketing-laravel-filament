<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Upgrade\ReleaseChecker;
use App\Services\Upgrade\ReleaseNotifier;
use Illuminate\Console\Command;

/**
 * `upgrade:check` — poll the release feed (spec §6.2 F-6.2-001/008, WP-41).
 *
 * Scheduled daily from routes/modules/upgrade.php. Deliberately NOT a §0.6
 * KNOWN_JOBS row: D-40 fixes that list and this is a monitoring task like
 * `ops:monitor`, so it has no per-job toggle — its own switch is
 * `emp-upgrade.release.endpoint` (unset = the whole feature is off).
 */
class UpgradeCheckCommand extends Command
{
    protected $signature = 'upgrade:check {--notify : Email operators when a newer release is available}
                                          {--force : Ignore the cached feed and the per-version notification dedup}';

    protected $description = 'Check the configured release feed for a newer version.';

    public function handle(ReleaseChecker $checker, ReleaseNotifier $notifier): int
    {
        $result = $checker->check(force: (bool) $this->option('force'));

        switch ($result['status']) {
            case ReleaseChecker::STATUS_DISABLED:
                $this->line(__('upgrade.console.no_endpoint'));

                return self::SUCCESS;

            case ReleaseChecker::STATUS_UNKNOWN:
                $this->warn(__('upgrade.console.unreachable'));

                // An unreachable vendor is not a failure of this installation.
                return self::SUCCESS;

            case ReleaseChecker::STATUS_CURRENT:
                $this->info(__('upgrade.console.current', ['version' => $result['current']]));

                return self::SUCCESS;
        }

        $release = $result['release'];

        $this->info(__('upgrade.console.available', [
            'version' => $release?->version ?? '?',
            'current' => $result['current'],
        ]));

        if ($release === null || ! $this->option('notify')) {
            return self::SUCCESS;
        }

        if (! (bool) $this->option('force') && $checker->alreadyNotified($release->version)) {
            $this->line(__('upgrade.console.notify_skipped', ['version' => $release->version]));

            return self::SUCCESS;
        }

        $sent = $notifier->notify($release, force: (bool) $this->option('force'));

        $this->line(__('upgrade.console.notified', ['count' => $sent]));

        return self::SUCCESS;
    }
}
