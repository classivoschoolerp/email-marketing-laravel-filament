<?php

declare(strict_types=1);

namespace App\Services\Exports\Writers;

use App\Enums\ExportFormat;
use Closure;

/**
 * Export-target registry (spec §6.1 F-6.1-014 "Import/export sources").
 *
 * The export side needs a factory rather than a class name — a RowWriter is
 * constructed with the destination path — so registration takes a closure:
 *
 *   $registry->register('ods', fn (string $path) => new OdsRowWriter($path));
 *
 * {@see RowWriterFactory} consults this registry first and falls back to the
 * built-in CSV/XLSX writers, so existing behaviour is unchanged and a plugin
 * may also OVERRIDE a built-in format.
 *
 * Bound as a singleton from routes/modules/plugins.php (D-41/D-51).
 *
 * Same closed-enum caveat as the import side: `ExportFormat` is a PHP enum, so
 * a wholly new format key resolves here but cannot yet be stored in
 * `export_jobs.format` (D-84). Documented in docs/plugins.md §Known limits.
 */
class RowWriterRegistry
{
    /** @var array<string, Closure(string): RowWriter> */
    private array $factories = [];

    /** @param Closure(string): RowWriter $factory */
    public function register(ExportFormat|string $format, Closure $factory): void
    {
        $this->factories[$this->key($format)] = $factory;
    }

    public function has(ExportFormat|string $format): bool
    {
        return isset($this->factories[$this->key($format)]);
    }

    public function make(ExportFormat|string $format, string $absolutePath): ?RowWriter
    {
        $factory = $this->factories[$this->key($format)] ?? null;

        return $factory === null ? null : $factory($absolutePath);
    }

    /** @return list<string> plugin-registered keys only */
    public function keys(): array
    {
        return array_keys($this->factories);
    }

    private function key(ExportFormat|string $format): string
    {
        return $format instanceof ExportFormat ? $format->value : $format;
    }
}
