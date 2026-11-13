<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Geo\GeoDataImporter;
use App\Support\Files;
use Illuminate\Console\Command;

/**
 * `emp:geo-import` (spec §3.23 F-3.23-004/005, WP-24): populate the
 * countries/zones tables from the built-in ISO datasets, or from a CSV/JSON
 * file. Idempotent — safe to re-run on every deploy and from the installer.
 */
class GeoImportCommand extends Command
{
    protected $signature = 'emp:geo-import
        {--countries : import countries only}
        {--zones : import zones only}
        {--file= : CSV/JSON file to import instead of the built-in dataset}
        {--type=countries : row type of --file (countries|zones)}';

    protected $description = 'Import the countries and zones reference datasets (§3.23).';

    public function handle(GeoDataImporter $importer): int
    {
        $file = $this->option('file');

        if (is_string($file) && $file !== '') {
            return $this->importFile($importer, $file);
        }

        $countriesOnly = (bool) $this->option('countries');
        $zonesOnly = (bool) $this->option('zones');
        $both = $countriesOnly === $zonesOnly;

        if ($both || $countriesOnly) {
            $this->info(__('ops.geo.imported_countries', ['count' => $importer->importBuiltInCountries()]));
        }

        if ($both || $zonesOnly) {
            $this->info(__('ops.geo.imported_zones', ['count' => $importer->importBuiltInZones()]));
        }

        return self::SUCCESS;
    }

    private function importFile(GeoDataImporter $importer, string $file): int
    {
        if (! Files::accessible($file)) {
            $this->error(__('ops.files.open_basedir_denied', ['path' => $file]));

            return self::FAILURE;
        }

        if (! is_file($file)) {
            $this->error(__('ops.geo.file_missing', ['path' => $file]));

            return self::FAILURE;
        }

        $rows = $importer->parse((string) file_get_contents($file), $importer->formatFor($file));
        $type = (string) $this->option('type');

        if ($type === 'zones') {
            $this->info(__('ops.geo.imported_zones', ['count' => $importer->importZones($rows)]));

            return self::SUCCESS;
        }

        $this->info(__('ops.geo.imported_countries', ['count' => $importer->importCountries($rows)]));

        return self::SUCCESS;
    }
}
