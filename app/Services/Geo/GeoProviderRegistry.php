<?php

declare(strict_types=1);

namespace App\Services\Geo;

use InvalidArgumentException;

/**
 * GeoIP provider registry (spec §3.23 F-3.23-001, D-10) — mirrors the WP-14
 * DriverRegistry / WP-16 ProviderRegistry pattern (docs/ARCHITECTURE.md rule 2).
 *
 * Self-initialising from `config('emp-ops.geo.providers')`. Plugins (§6.1)
 * add a vendor without touching core code, either by merging a
 * `key => class` entry into that config from their service provider, or by
 * resolving the registry at boot and calling {@see register()}.
 *
 * Bound as a singleton from routes/modules/ops.php (D-41/D-51 precedent —
 * providers file is shared; see the note there about route:cache).
 */
class GeoProviderRegistry
{
    /** @var array<string, class-string<IpLocationProvider>> */
    private array $classes = [];

    /** @var array<string, IpLocationProvider> */
    private array $instances = [];

    public function __construct()
    {
        /** @var array<string, class-string<IpLocationProvider>> $configured */
        $configured = config('emp-ops.geo.providers', []);

        foreach ($configured as $key => $class) {
            $this->register((string) $key, $class);
        }
    }

    /** @param class-string<IpLocationProvider>|IpLocationProvider $provider */
    public function register(string $key, string|IpLocationProvider $provider): void
    {
        if ($provider instanceof IpLocationProvider) {
            $this->instances[$key] = $provider;
            $this->classes[$key] = $provider::class;

            return;
        }

        if (! is_subclass_of($provider, IpLocationProvider::class)) {
            throw new InvalidArgumentException(
                "IP location provider [{$provider}] must implement ".IpLocationProvider::class.'.'
            );
        }

        $this->classes[$key] = $provider;
        unset($this->instances[$key]);
    }

    public function has(string $key): bool
    {
        return isset($this->classes[$key]);
    }

    public function provider(string $key): IpLocationProvider
    {
        if (! $this->has($key)) {
            throw new InvalidArgumentException("Unknown IP location provider [{$key}].");
        }

        return $this->instances[$key] ??= app($this->classes[$key]);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->classes);
    }

    /** @return array<string, IpLocationProvider> */
    public function all(): array
    {
        foreach ($this->classes as $key => $class) {
            $this->provider($key);
        }

        return $this->instances;
    }

    /** @return array<string, string> key => label, for the settings select */
    public function options(): array
    {
        return array_map(
            static fn (IpLocationProvider $provider): string => $provider->label(),
            $this->all(),
        );
    }
}
