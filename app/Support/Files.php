<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * `open_basedir`-safe file helpers (spec §6.9 F-6.9-004, WP-24).
 *
 * Shared hosts (cPanel/Plesk) run PHP with `open_basedir` set, usually to
 * `/home/<user>/:/tmp:/usr/local/lib/php`. Under that restriction:
 *
 *  - `realpath()` returns **false** and emits a warning for any path outside
 *    the allowed prefixes — so it must never be used to *validate* a path;
 *  - `is_file()` / `file_exists()` are safe but return false for denied paths,
 *    which is indistinguishable from "missing";
 *  - `sys_get_temp_dir()` may point outside the allowed list; always use
 *    `storage_path()` for scratch files;
 *  - symlinks that leave the allowed tree are refused.
 *
 * Rules for this codebase (documented in docs/install-cpanel.md):
 *  1. Never build an absolute path from user input. Resolve everything from a
 *     Storage disk root via {@see storagePath()}, which strips traversal.
 *  2. Use {@see contains()} (lexical, no filesystem access) instead of
 *     comparing `realpath()` results.
 *  3. Check {@see accessible()} before touching a configured absolute path
 *     (backup directories, MTA binaries, pickup dirs) so the failure message
 *     names `open_basedir` instead of "file not found".
 */
final class Files
{
    /**
     * Lexically normalise a path: forward slashes, collapsed separators,
     * resolved `.`/`..` segments. Never touches the filesystem, so it is safe
     * under `open_basedir` (unlike realpath()).
     */
    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = '';

        // Preserve a leading slash or a Windows drive letter.
        if (preg_match('#^([A-Za-z]:)?/#', $path, $matches) === 1) {
            $prefix = $matches[0];
            $path = substr($path, strlen($prefix));
        } elseif (preg_match('#^[A-Za-z]:#', $path, $matches) === 1) {
            $prefix = $matches[0];
            $path = substr($path, strlen($prefix));
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments !== [] && end($segments) !== '..') {
                    array_pop($segments);
                } elseif ($prefix === '') {
                    $segments[] = '..';
                }

                continue;
            }

            $segments[] = $segment;
        }

        return $prefix.implode('/', $segments);
    }

    /**
     * Strip every traversal/absolute component from an untrusted relative
     * path so it can only ever resolve inside its base directory.
     */
    public static function safeRelative(string $path): string
    {
        $normalized = self::normalize($path);
        $normalized = preg_replace('#^([A-Za-z]:)?/#', '', $normalized) ?? '';

        return ltrim(str_replace('..', '', $normalized), '/');
    }

    /** Lexical containment test — no realpath(), no filesystem access. */
    public static function contains(string $base, string $path): bool
    {
        $base = rtrim(self::normalize($base), '/');
        $path = self::normalize($path);

        return $base !== '' && ($path === $base || str_starts_with($path, $base.'/'));
    }

    /**
     * Absolute path for a file on a Storage disk, with traversal stripped.
     * Preferred over hand-built `storage_path(...)` concatenation.
     */
    public static function storagePath(string $relative, string $disk = 'local'): string
    {
        return self::normalize(Storage::disk($disk)->path(self::safeRelative($relative)));
    }

    /**
     * Directories PHP is allowed to touch, or an empty list when
     * `open_basedir` is not set (the common VPS/Docker case).
     *
     * @return list<string>
     */
    public static function openBasedirPaths(): array
    {
        $value = (string) ini_get('open_basedir');

        if (trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $part): string => rtrim(self::normalize(trim($part)), '/'),
            explode(PATH_SEPARATOR, $value),
        ), static fn (string $part): bool => $part !== ''));
    }

    public static function openBasedirEnabled(): bool
    {
        return self::openBasedirPaths() !== [];
    }

    /**
     * True when `open_basedir` permits this path (always true when the ini
     * directive is unset). Purely lexical — safe to call on missing paths.
     */
    public static function accessible(string $path): bool
    {
        $allowed = self::openBasedirPaths();

        if ($allowed === []) {
            return true;
        }

        foreach ($allowed as $prefix) {
            if (self::contains($prefix, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Writable check that distinguishes "denied by open_basedir" from
     * "missing" — used by the installer requirements screen (F-6.9-001).
     *
     * @return array{exists: bool, writable: bool, allowed: bool}
     */
    public static function inspect(string $path): array
    {
        $allowed = self::accessible($path);

        return [
            'exists' => $allowed && @file_exists($path),
            'writable' => $allowed && @is_writable($path),
            'allowed' => $allowed,
        ];
    }

    /** Scratch directory that is always inside the app tree (never /tmp). */
    public static function temporaryDirectory(string $suffix = ''): string
    {
        $path = storage_path('app/tmp'.($suffix === '' ? '' : '/'.self::safeRelative($suffix)));

        if (! is_dir($path)) {
            @mkdir($path, 0775, true);
        }

        return self::normalize($path);
    }
}
