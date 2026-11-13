<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Geo\MaxMindDatabaseUpdater;
use App\Services\Settings\SettingsReader;
use Illuminate\Console\Command;
use Throwable;

/**
 * `geo:update-database` (spec §3.23 F-3.23-002, WP-24): refresh the MaxMind
 * database from MaxMind's permalink endpoint. Scheduled weekly from
 * routes/modules/ops.php and skipped unless the auto-update toggle is on —
 * `--force` runs it regardless (the settings page action uses that path).
 */
class GeoUpdateDatabaseCommand extends Command
{
    protected $signature = 'geo:update-database {--force : ignore the auto-update toggle}';

    protected $description = 'Download the configured MaxMind GeoIP database (§3.23).';

    public function handle(MaxMindDatabaseUpdater $updater): int
    {
        if (! $this->option('force') && ! SettingsReader::location()->maxmindAutoUpdate()) {
            $this->info(__('ops.geo.auto_update_disabled'));

            return self::SUCCESS;
        }

        try {
            $path = $updater->update();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(__('ops.geo.database_updated', ['path' => $path]));

        return self::SUCCESS;
    }
}
