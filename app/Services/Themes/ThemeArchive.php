<?php

declare(strict_types=1);

namespace App\Services\Themes;

use App\Plugins\PluginArchive;
use App\Services\Themes\Exceptions\InvalidThemePackageException;
use ZipArchive;

/**
 * Safe reader/extractor for a theme `.zip` (spec §6.3 F-6.3-005).
 *
 * Deliberately the same discipline as {@see PluginArchive} — traversal,
 * absolute paths, Windows drive letters, NUL bytes, stream wrappers,
 * over-long paths, symlinks, entry-count caps, total-uncompressed caps and
 * per-entry compression-ratio caps are all rejected before a single byte is
 * written — with one difference that matters more than all of them:
 *
 *   A THEME MAY NOT CONTAIN CODE.
 *
 * `php`, `js`, `sh`, `phar` and friends are on the BLOCK list
 * (config/emp-themes.php), so an archive carrying one is refused outright
 * rather than half-installed with the file skipped. Nothing extracted here is
 * ever included, required, evaluated or served: the only thing the
 * application does with a theme package afterwards is `file_get_contents()`
 * the manifest-declared stylesheet and echo it inside a <style> tag.
 *
 * The class is intentionally standalone rather than a subclass of
 * PluginArchive: PluginArchive's limits, extension policy and manifest name
 * all come from `emp-plugins` config, and inheriting it would mean a theme
 * silently picking up plugin trust settings.
 */
class ThemeArchive
{
    private ZipArchive $zip;

    /** Common prefix stripped from every entry ('' when files sit at root). */
    private string $prefix = '';

    /** @var list<string> in-archive paths that passed every check */
    private array $entries = [];

    private function __construct() {}

    /**
     * Open + fully validate an archive. Throws before any byte is written.
     *
     * @throws InvalidThemePackageException
     */
    public static function open(string $zipPath): self
    {
        $archive = new self;
        $archive->zip = new ZipArchive;

        if (! is_file($zipPath)) {
            throw new InvalidThemePackageException(__('themes.errors.archive_missing'));
        }

        $maxUpload = (int) config('emp-themes.limits.max_upload_mb', 10) * 1024 * 1024;
        $size = filesize($zipPath);

        if ($size !== false && $size > $maxUpload) {
            throw new InvalidThemePackageException(__('themes.errors.archive_upload_too_large', [
                'max' => (int) config('emp-themes.limits.max_upload_mb', 10),
            ]));
        }

        if ($archive->zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new InvalidThemePackageException(__('themes.errors.archive_unreadable'));
        }

        try {
            $archive->scan();
        } catch (InvalidThemePackageException $e) {
            $archive->zip->close();

            throw $e;
        }

        return $archive;
    }

    public function close(): void
    {
        $this->zip->close();
    }

    /**
     * The decoded, validated manifest.
     *
     * @throws InvalidThemePackageException
     */
    public function manifest(): ThemeManifest
    {
        $file = (string) config('emp-themes.manifest_file', 'theme.json');
        $raw = $this->zip->getFromName($this->prefix.$file);

        if ($raw === false) {
            throw new InvalidThemePackageException(
                __('themes.errors.manifest_missing', ['file' => $file])
            );
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw new InvalidThemePackageException(
                __('themes.errors.manifest_unparsable', ['file' => $file])
            );
        }

        $manifest = ThemeManifest::fromArray($decoded);
        $errors = app(ThemeManifestValidator::class)->validate($manifest);

        if ($errors !== []) {
            throw InvalidThemePackageException::withErrors($errors);
        }

        // The declared stylesheet has to actually be in the archive: a
        // package whose manifest points at a file it does not ship installs
        // "successfully" and then renders nothing, which is the worst
        // possible failure mode for a theme.
        if ($this->zip->locateName($this->prefix.$manifest->stylesheet) === false) {
            throw new InvalidThemePackageException(__('themes.errors.stylesheet_missing', [
                'path' => $manifest->stylesheet,
            ]));
        }

        return $manifest;
    }

    /**
     * Write every allowed entry beneath $target (created if absent).
     * $target must be an absolute path chosen by core code — never something
     * derived from the archive.
     */
    public function extractTo(string $target): void
    {
        $target = rtrim($target, '/\\');

        if (! is_dir($target) && ! @mkdir($target, 0775, true) && ! is_dir($target)) {
            throw new InvalidThemePackageException(
                __('themes.errors.extract_failed', ['path' => $target])
            );
        }

        foreach ($this->entries as $entry) {
            $relative = $this->prefix === '' ? $entry : substr($entry, strlen($this->prefix));
            $relative = $this->safeRelativePath($relative);

            if ($relative === '') {
                continue;
            }

            $destination = $target.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $directory = dirname($destination);

            if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
                throw new InvalidThemePackageException(
                    __('themes.errors.extract_failed', ['path' => $directory])
                );
            }

            // Belt and braces: even after the scan-time guard, refuse to
            // write anywhere that is not genuinely inside the target.
            $realTarget = realpath($target);
            $realParent = realpath($directory);

            if ($realTarget === false || $realParent === false
                || ! str_starts_with($realParent.DIRECTORY_SEPARATOR, $realTarget.DIRECTORY_SEPARATOR)) {
                throw new InvalidThemePackageException(
                    __('themes.errors.archive_unsafe_entry', ['entry' => $relative])
                );
            }

            $contents = $this->zip->getFromName($entry);

            if ($contents === false) {
                continue;
            }

            file_put_contents($destination, $contents);
        }
    }

    /** @return list<string> validated in-archive paths (debugging/tests) */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * Single validation pass over the central directory.
     *
     * @throws InvalidThemePackageException
     */
    private function scan(): void
    {
        $limits = (array) config('emp-themes.limits', []);
        $maxEntries = (int) ($limits['max_entries'] ?? 500);
        $maxBytes = (int) ($limits['max_uncompressed_mb'] ?? 40) * 1024 * 1024;
        $maxRatio = (int) ($limits['max_compression_ratio'] ?? 200);
        $maxPath = (int) ($limits['max_entry_path_length'] ?? 180);

        $allowed = array_map('strval', (array) config('emp-themes.allowed_file_extensions', []));
        $blocked = array_map('strval', (array) config('emp-themes.blocked_file_extensions', []));
        $manifestFile = (string) config('emp-themes.manifest_file', 'theme.json');

        if ($this->zip->numFiles > $maxEntries) {
            throw new InvalidThemePackageException(
                __('themes.errors.archive_too_many_entries', ['max' => $maxEntries])
            );
        }

        $total = 0;
        $files = [];
        $topLevels = [];

        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $stat = $this->zip->statIndex($i);

            if ($stat === false) {
                continue;
            }

            $name = (string) $stat['name'];

            if ($name === '') {
                continue;
            }

            // --- zip-slip -------------------------------------------------
            $normalized = str_replace('\\', '/', $name);

            if (str_contains($normalized, '..')
                || str_starts_with($normalized, '/')
                || preg_match('#^[A-Za-z]:#', $normalized) === 1
                || str_contains($normalized, "\0")
                || str_contains($normalized, '://')) {
                throw new InvalidThemePackageException(
                    __('themes.errors.archive_unsafe_entry', ['entry' => $name])
                );
            }

            if (mb_strlen($normalized) > $maxPath) {
                throw new InvalidThemePackageException(
                    __('themes.errors.archive_path_too_long', ['entry' => $name])
                );
            }

            // --- symlinks --------------------------------------------------
            if ($this->isSymlink($i)) {
                throw new InvalidThemePackageException(
                    __('themes.errors.archive_symlink', ['entry' => $name])
                );
            }

            if (str_ends_with($normalized, '/')) {
                $topLevels[explode('/', trim($normalized, '/'))[0]] = true;

                continue;
            }

            // --- zip bomb --------------------------------------------------
            $size = (int) ($stat['size'] ?? 0);
            $compressed = (int) ($stat['comp_size'] ?? 0);
            $total += $size;

            if ($total > $maxBytes) {
                throw new InvalidThemePackageException(__('themes.errors.archive_too_large', [
                    'max' => (int) ($limits['max_uncompressed_mb'] ?? 40),
                ]));
            }

            if ($maxRatio > 0 && $compressed > 0 && $size > 0
                && intdiv($size, max($compressed, 1)) > $maxRatio) {
                throw new InvalidThemePackageException(
                    __('themes.errors.archive_compression_ratio', ['entry' => $name])
                );
            }

            // --- extension policy: NO CODE ---------------------------------
            $extension = mb_strtolower((string) pathinfo($normalized, PATHINFO_EXTENSION));

            if (in_array($extension, $blocked, true)) {
                throw new InvalidThemePackageException(__('themes.errors.archive_executable_file', [
                    'entry' => $name,
                    'extension' => $extension,
                ]));
            }

            $topLevels[explode('/', $normalized)[0]] = true;

            if (in_array($extension, $allowed, true)) {
                $files[] = $normalized;
            }
        }

        if ($files === []) {
            throw new InvalidThemePackageException(__('themes.errors.archive_empty'));
        }

        $this->prefix = $this->resolvePrefix(array_keys($topLevels), $files, $manifestFile);
        $this->entries = $files;

        if ($this->zip->locateName($this->prefix.$manifestFile) === false) {
            throw new InvalidThemePackageException(
                __('themes.errors.manifest_missing', ['file' => $manifestFile])
            );
        }
    }

    /**
     * Authors zip either the folder or its contents. Unwrap exactly one
     * top-level folder, and only when the manifest lives inside it.
     *
     * @param  list<string>  $topLevels
     * @param  list<string>  $files
     */
    private function resolvePrefix(array $topLevels, array $files, string $manifestFile): string
    {
        if (in_array($manifestFile, $files, true)) {
            return '';
        }

        $folders = array_values(array_filter(
            $topLevels,
            fn (string $top): bool => ! in_array($top, $files, true),
        ));

        return count($folders) === 1 ? $folders[0].'/' : '';
    }

    /** Unix mode is in the top 16 bits of the external attributes. */
    private function isSymlink(int $index): bool
    {
        $attributes = $this->zip->getExternalAttributesIndex($index, $opsys, $attr);

        if ($attributes !== true || $opsys !== ZipArchive::OPSYS_UNIX) {
            return false;
        }

        return (((int) $attr >> 16) & 0xA000) === 0xA000;
    }

    private function safeRelativePath(string $path): string
    {
        $segments = array_filter(
            explode('/', str_replace('\\', '/', $path)),
            fn (string $s): bool => $s !== '' && $s !== '.' && $s !== '..',
        );

        return implode('/', $segments);
    }
}
