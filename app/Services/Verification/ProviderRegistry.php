<?php

declare(strict_types=1);

namespace App\Services\Verification;

use App\Services\Verification\Providers\AbstractHttpProvider;
use Closure;
use InvalidArgumentException;

/**
 * Verification provider registry (§2.9.12 F-2.9.12-001, D-12; docs/ARCHITECTURE.md
 * rule 2 — same plugin pattern as App\Sending\DriverRegistry).
 *
 * Self-initialising from config('emp-verification.providers') and bound as
 * a singleton from routes/modules/blacklist.php (D-41/51 precedent), so
 * runtime registrations persist. Plugins add vendors WITHOUT core changes:
 * merge a key => ['class' => …] entry into the config from their service
 * provider, or resolve the registry and call register() at boot — either a
 * class name (HTTP adapters get the API key injected) or a factory closure
 * (?string $apiKey) => VerificationProvider.
 */
class ProviderRegistry
{
    /** @var array<string, class-string<VerificationProvider>|Closure(?string): VerificationProvider> */
    private array $factories = [];

    public function __construct()
    {
        foreach (config('emp-verification.providers', []) as $key => $entry) {
            $class = is_array($entry) ? ($entry['class'] ?? null) : $entry;

            if (is_string($class)) {
                $this->register((string) $key, $class);
            }
        }
    }

    /** @param class-string<VerificationProvider>|Closure(?string): VerificationProvider $factory */
    public function register(string $key, string|Closure $factory): void
    {
        if (is_string($factory) && ! is_subclass_of($factory, VerificationProvider::class)) {
            throw new InvalidArgumentException(
                "Verification provider [{$factory}] must implement ".VerificationProvider::class.'.'
            );
        }

        $this->factories[$key] = $factory;
    }

    public function has(string $key): bool
    {
        return isset($this->factories[$key]);
    }

    /**
     * Build a provider instance carrying the given API credential (null for
     * the built-in engine or when the caller resolved no key).
     */
    public function make(string $key, ?string $apiKey = null): VerificationProvider
    {
        $factory = $this->factories[$key] ?? null;

        if ($factory === null) {
            throw new InvalidArgumentException("Unknown verification provider [{$key}].");
        }

        if ($factory instanceof Closure) {
            return $factory($apiKey);
        }

        if (is_subclass_of($factory, AbstractHttpProvider::class)) {
            return new $factory($apiKey);
        }

        return app($factory);
    }

    /** @return list<string> registered provider keys */
    public function keys(): array
    {
        return array_keys($this->factories);
    }

    /** @return array<string, string> key => human name, for select options */
    public function options(): array
    {
        $options = [];

        foreach ($this->keys() as $key) {
            $options[$key] = $this->make($key)->name();
        }

        return $options;
    }
}
