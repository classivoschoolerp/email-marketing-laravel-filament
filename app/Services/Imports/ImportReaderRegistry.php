<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Enums\ImportSource;
use App\Services\Imports\Readers\CsvFileReader;
use App\Services\Imports\Readers\DatabaseReader;
use App\Services\Imports\Readers\EspReader;
use App\Services\Imports\Readers\ImportReader;
use App\Services\Imports\Readers\ListReader;
use InvalidArgumentException;

/**
 * Import-source registry (spec §6.1 F-6.1-014 "Import/export sources").
 *
 * WP-36 turned RunImportJob's private `match` into this registry so a plugin
 * can add an ESP connector (Mailchimp, Brevo, …) or an FTP/SFTP source
 * without a core change — docs/ARCHITECTURE.md rule 2 applied to the import side.
 *
 * The built-in ImportSource cases keep their exact previous mapping as the
 * fallback, so existing behaviour is untouched; a registered reader simply
 * takes precedence, which is also how a plugin REPLACES a built-in source.
 *
 * Bound as a singleton from routes/modules/plugins.php (D-41/D-51).
 *
 * Known limitation, stated plainly: `ImportSource` is a closed PHP enum, so a
 * brand-new source key can be registered and resolved here but cannot yet be
 * persisted in `import_jobs.source`. Plugins today either override a built-in
 * source or drive the reader directly. Opening that up means relaxing the
 * enum cast on ImportJob — see docs/plugins.md §Known limits.
 */
class ImportReaderRegistry
{
    /** @var array<string, class-string<ImportReader>|ImportReader> */
    private array $readers = [];

    /** @param class-string<ImportReader>|ImportReader $reader */
    public function register(ImportSource|string $source, string|ImportReader $reader): void
    {
        if (is_string($reader) && ! is_subclass_of($reader, ImportReader::class)) {
            throw new InvalidArgumentException(
                "Import reader [{$reader}] must implement ".ImportReader::class.'.'
            );
        }

        $this->readers[$this->key($source)] = $reader;
    }

    public function has(ImportSource|string $source): bool
    {
        return isset($this->readers[$this->key($source)]);
    }

    public function for(ImportSource|string $source): ImportReader
    {
        $key = $this->key($source);

        if (isset($this->readers[$key])) {
            $reader = $this->readers[$key];

            return $reader instanceof ImportReader ? $reader : app($reader);
        }

        $case = $source instanceof ImportSource ? $source : ImportSource::tryFrom($key);

        if ($case === null) {
            throw new InvalidArgumentException("Unknown import source [{$key}].");
        }

        return app(match ($case) {
            ImportSource::Csv, ImportSource::Paste, ImportSource::Url => CsvFileReader::class,
            ImportSource::List => ListReader::class,
            ImportSource::Database => DatabaseReader::class,
            // WP-42 F-2.2.7-006 — the vendor inside the family is picked by
            // EspImporterRegistry from options.esp.driver.
            ImportSource::Esp => EspReader::class,
        });
    }

    /** @return list<string> plugin-registered keys only */
    public function keys(): array
    {
        return array_keys($this->readers);
    }

    private function key(ImportSource|string $source): string
    {
        return $source instanceof ImportSource ? $source->value : $source;
    }
}
