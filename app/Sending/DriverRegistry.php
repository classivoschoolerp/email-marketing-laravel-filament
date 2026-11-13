<?php

declare(strict_types=1);

namespace App\Sending;

use App\Models\DeliveryServer;
use InvalidArgumentException;

/**
 * Sending driver registry (spec §2.9.2 F-2.9.2-020/021, docs/ARCHITECTURE.md rule 2).
 *
 * Self-initialising from config('emp-sending.drivers') — the 20 built-in D-21
 * keys — and bound as a singleton from routes/modules/sending.php (same
 * boot-time wiring precedent as D-41), so runtime registrations persist.
 *
 * Plugins (§6.1) add vendors WITHOUT core changes, either by merging a
 * key => class entry into config('emp-sending.drivers') from their service
 * provider, or by resolving the registry and calling register() at boot.
 */
class DriverRegistry
{
    /** @var array<string, class-string<SendingDriver>> */
    private array $classes = [];

    /** @var array<string, SendingDriver> resolved instances */
    private array $instances = [];

    public function __construct()
    {
        foreach (config('emp-sending.drivers', []) as $key => $class) {
            $this->register($key, $class);
        }
    }

    /**
     * Plugin-facing registration API (F-2.9.2-021). Accepts a class name
     * (lazily resolved through the container) or a ready instance.
     *
     * @param  class-string<SendingDriver>|SendingDriver  $driver
     */
    public function register(string $key, string|SendingDriver $driver): void
    {
        if ($driver instanceof SendingDriver) {
            $this->instances[$key] = $driver;
            $this->classes[$key] = $driver::class;

            return;
        }

        if (! is_subclass_of($driver, SendingDriver::class)) {
            throw new InvalidArgumentException(
                "Sending driver [{$driver}] must implement ".SendingDriver::class.'.'
            );
        }

        $this->classes[$key] = $driver;
        unset($this->instances[$key]);
    }

    public function has(string $key): bool
    {
        return isset($this->classes[$key]);
    }

    public function driver(string $key): SendingDriver
    {
        if (! $this->has($key)) {
            throw new InvalidArgumentException("Unknown sending driver [{$key}].");
        }

        return $this->instances[$key] ??= app($this->classes[$key]);
    }

    public function forServer(DeliveryServer $server): SendingDriver
    {
        return $this->driver($server->type);
    }

    /** @return list<string> registered driver keys */
    public function keys(): array
    {
        return array_keys($this->classes);
    }

    /** @return array<string, SendingDriver> keyed by driver key */
    public function all(): array
    {
        foreach ($this->classes as $key => $class) {
            $this->driver($key);
        }

        return $this->instances;
    }

    /** @return array<string, string> key => label, for select options */
    public function options(): array
    {
        return array_map(fn (SendingDriver $d): string => $d->label(), $this->all());
    }
}
