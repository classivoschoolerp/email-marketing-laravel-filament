<?php

declare(strict_types=1);

namespace App\Services\Content\InboxPreview;

use App\Settings\InboxPreviewSettings;
use Throwable;

/**
 * Driver registry for inbox rendering previews (spec §2.3.5 F-2.3.5-006,
 * WP-46) — the D-12 shape, and docs/ARCHITECTURE.md's non-negotiable #2: a new rendering
 * farm is a new adapter registered here, never a core change.
 *
 * The built-in adapters register themselves in the constructor rather than
 * from a route module file, so this registry has no D-41/D-51 route-cache
 * caveat; {@see extend()} is the seam a plugin uses (its own boot happens
 * after the container is available, D-102).
 *
 * {@see default()} resolves the configured provider and falls back to
 * {@see NullProvider} — an install with no vendor account gets a provider that
 * says "not configured", never a fabricated screenshot.
 */
final class InboxPreviewRegistry
{
    /** @var array<string, InboxPreviewProvider> */
    private array $providers = [];

    public function __construct(private readonly InboxPreviewSettings $settings)
    {
        $this->register(new NullProvider);
        $this->register(new LitmusProvider($settings));
        $this->register(new EmailOnAcidProvider($settings));
    }

    public function register(InboxPreviewProvider $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    /** Plugin seam (F-3.26.3-005 precedent): replace or add an adapter. */
    public function extend(InboxPreviewProvider $provider): void
    {
        $this->register($provider);
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    public function get(string $key): InboxPreviewProvider
    {
        return $this->providers[$key] ?? $this->providers[NullProvider::KEY];
    }

    /** @return array<string, InboxPreviewProvider> */
    public function all(): array
    {
        return $this->providers;
    }

    /** @return array<string, InboxPreviewProvider> only those with credentials */
    public function configured(): array
    {
        return array_filter(
            $this->providers,
            static fn (InboxPreviewProvider $provider): bool => $provider->configured(),
        );
    }

    /**
     * The provider a preview run should use: the operator's choice when it is
     * actually configured, otherwise the first configured adapter, otherwise
     * NullProvider.
     */
    public function default(): InboxPreviewProvider
    {
        try {
            $chosen = trim((string) $this->settings->provider);
        } catch (Throwable) {
            $chosen = '';
        }

        if ($chosen !== '' && $this->has($chosen) && $this->get($chosen)->configured()) {
            return $this->get($chosen);
        }

        foreach ($this->configured() as $provider) {
            return $provider;
        }

        return $this->providers[NullProvider::KEY];
    }

    /** @return array<string, string> key => label, for a select */
    public function options(): array
    {
        $options = [];

        foreach ($this->providers as $key => $provider) {
            $options[$key] = $provider->label();
        }

        return $options;
    }
}
