<?php

declare(strict_types=1);

namespace App\Services\Imports\Esp;

use Closure;
use InvalidArgumentException;

/**
 * ESP import adapter registry (spec §2.2.7 F-2.2.7-006; docs/ARCHITECTURE.md rule 2 —
 * identical shape to App\Services\Verification\ProviderRegistry and
 * App\Services\Imports\ImportReaderRegistry).
 *
 * Self-initialising from config('emp-imports.esp.importers') and bound as a
 * singleton from PluginModuleServiceProvider, so a runtime registration made
 * by a plugin is still visible when the queued import job resolves the
 * registry later in the process.
 */
class EspImporterRegistry
{
    /** @var array<string, class-string<EspImporter>|Closure(): EspImporter> */
    private array $factories = [];

    public function __construct()
    {
        foreach ((array) config('emp-imports.esp.importers', []) as $key => $entry) {
            $class = is_array($entry) ? ($entry['class'] ?? null) : $entry;

            if (is_string($class)) {
                $this->register((string) $key, $class);
            }
        }
    }

    /** @param class-string<EspImporter>|Closure(): EspImporter $factory */
    public function register(string $key, string|Closure $factory): void
    {
        if (is_string($factory) && ! is_subclass_of($factory, EspImporter::class)) {
            throw new InvalidArgumentException(
                "ESP importer [{$factory}] must implement ".EspImporter::class.'.'
            );
        }

        $this->factories[$key] = $factory;
    }

    public function has(string $key): bool
    {
        return isset($this->factories[$key]);
    }

    public function for(string $key): EspImporter
    {
        $factory = $this->factories[$key] ?? null;

        if ($factory === null) {
            throw new InvalidArgumentException("Unknown ESP importer [{$key}].");
        }

        return $factory instanceof Closure ? $factory() : app($factory);
    }

    /** @return list<string> registered keys */
    public function keys(): array
    {
        return array_keys($this->factories);
    }

    /** @return array<string, string> key => human name, for select options */
    public function options(): array
    {
        $options = [];

        foreach ($this->keys() as $key) {
            $options[$key] = $this->for($key)->name();
        }

        return $options;
    }
}
