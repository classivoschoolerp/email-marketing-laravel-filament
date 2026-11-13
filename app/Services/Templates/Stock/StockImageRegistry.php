<?php

declare(strict_types=1);

namespace App\Services\Templates\Stock;

use Throwable;

/**
 * Stock image library registry (spec §2.5.4 F-2.5.4-010, WP-42).
 *
 * The BuilderRegistry pattern, applied to image libraries: adapters are
 * listed as class names in config('emp-templates.stock.providers') and
 * resolved from the container, so adding a third library never touches the
 * controller, the picker or this class.
 *
 * "Configured" means "has an API key". A library without one is not offered
 * anywhere — the picker simply does not appear, which is the required
 * behaviour: no key, no feature, no error.
 */
class StockImageRegistry
{
    /** @var array<string, StockImageProvider>|null */
    private ?array $providers = null;

    /** @return array<string, StockImageProvider> */
    public function all(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $out = [];

        foreach ((array) config('emp-templates.stock.providers', []) as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            try {
                $provider = app($class);
            } catch (Throwable) {
                continue;
            }

            if ($provider instanceof StockImageProvider) {
                $out[$provider->key()] = $provider;
            }
        }

        return $this->providers = $out;
    }

    /** @return array<string, StockImageProvider> only libraries with a key */
    public function configured(): array
    {
        return array_filter($this->all(), static fn (StockImageProvider $p): bool => $p->isConfigured());
    }

    /** @return list<string> */
    public function configuredKeys(): array
    {
        return array_values(array_keys($this->configured()));
    }

    /** A configured library by key, or null. */
    public function get(string $key): ?StockImageProvider
    {
        return $this->configured()[$key] ?? null;
    }

    /**
     * The picker payload: [] when nothing is configured, which is exactly how
     * the builder learns the feature is unavailable.
     *
     * @return list<array{key: string, label: string}>
     */
    public function options(): array
    {
        $out = [];

        foreach ($this->configured() as $provider) {
            $out[] = ['key' => $provider->key(), 'label' => $provider->label()];
        }

        return $out;
    }
}
