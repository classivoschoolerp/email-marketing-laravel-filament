<?php

declare(strict_types=1);

namespace App\Services\Upgrade;

use App\Support\Files;

/**
 * Resolves WHERE an upgrade is allowed to write (spec §6.2, WP-41).
 *
 * This tiny class carries the single most important safety property of the
 * whole work package, so it is deliberately separate from the runner and
 * deliberately boring:
 *
 *  - `path()` is the ONLY source of the destination directory. It reads
 *    `emp-upgrade.target_path` and falls back to base_path().
 *  - `assertSafe()` refuses to hand back base_path() while APP_ENV=testing.
 *    A test that forgets to inject a temp directory therefore fails loudly
 *    instead of quietly rewriting the developer's checkout.
 *  - `isProtected()` answers "may this relative path be written?" for the
 *    F-6.2-006 protected list, lexically (no filesystem access, so it is
 *    `open_basedir`-safe and works for paths that do not exist yet).
 */
final class UpgradeTarget
{
    public function path(): string
    {
        $configured = config('emp-upgrade.target_path');

        $path = is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : base_path();

        return rtrim(Files::normalize($path), '/');
    }

    public function workPath(): string
    {
        $configured = config('emp-upgrade.work_path');

        $path = is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : storage_path('app/upgrades');

        return rtrim(Files::normalize($path), '/');
    }

    /**
     * @throws UpgradeException when the resolved target is the live
     *                          installation during a test run
     */
    public function assertSafe(): string
    {
        $path = $this->path();

        if (app()->environment('testing') && $path === rtrim(Files::normalize(base_path()), '/')) {
            throw new UpgradeException(
                'Refusing to upgrade the real installation from a test run — '
                .'inject emp-upgrade.target_path with a temporary directory.'
            );
        }

        if (! Files::accessible($path)) {
            throw new UpgradeException(__('ops.files.open_basedir_denied', ['path' => $path]));
        }

        return $path;
    }

    /**
     * F-6.2-006: is this archive-relative path one an upgrade must never
     * write? Matches the exact path and everything beneath it, so listing
     * `storage/app/plugins` covers every installed plugin's whole tree.
     */
    public function isProtected(string $relative): bool
    {
        $normalized = trim(Files::safeRelative($relative), '/');

        if ($normalized === '') {
            return true;
        }

        foreach ($this->protectedPaths() as $protected) {
            if ($normalized === $protected || str_starts_with($normalized.'/', $protected.'/')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> normalised, relative, no leading/trailing slash */
    public function protectedPaths(): array
    {
        $paths = [];

        foreach ((array) config('emp-upgrade.protected_paths', []) as $path) {
            $normalized = trim(Files::safeRelative((string) $path), '/');

            if ($normalized !== '') {
                $paths[] = $normalized;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Absolute destination for an archive-relative path, or null when the
     * result would escape the target directory (belt and braces on top of the
     * archive scanner's own zip-slip guard).
     */
    public function destinationFor(string $relative): ?string
    {
        $safe = Files::safeRelative($relative);

        if ($safe === '') {
            return null;
        }

        $target = $this->path();
        $destination = Files::normalize($target.'/'.$safe);

        return Files::contains($target, $destination) ? $destination : null;
    }
}
