<?php

declare(strict_types=1);

namespace App\Services\Upgrade;

use App\Models\UpgradeRun;
use App\Plugins\CoreVersion;
use App\Support\Files;
use ZipArchive;

/**
 * Safe reader for a core release `.zip` (spec §6.2 F-6.2-002, WP-41).
 *
 * This is deliberately the SAME gate as {@see PluginArchive}
 * (WP-36) — traversal, absolute paths, Windows drive letters, null bytes,
 * stream wrappers, symlinks, entry-count / total-size / per-entry
 * compression-ratio caps, single-top-level-folder unwrap. It is a separate
 * class rather than a subclass for one honest reason: PluginArchive's policy
 * is bound to `config('emp-plugins.*')` and to a plugin manifest, and WP-36's
 * files are read-only reuse for this work package. The path-safety primitives
 * that CAN be shared are shared — {@see Files::safeRelative()} and
 * {@see Files::contains()} — and the version grammar is
 * {@see CoreVersion}, never a second implementation.
 *
 * The one policy difference, and why:
 *
 *   PluginArchive uses an ALLOW-list of file extensions and silently skips
 *   anything else. That is right for a plugin (a plugin shipping a stray
 *   binary should just not get it) and WRONG for a core release: silently
 *   dropping a file from a core upgrade leaves a half-written application.
 *   So a release archive is governed by a BLOCK-list only, and a blocked
 *   entry rejects the WHOLE package rather than being skipped.
 *
 * Nothing here decides what may be written — see {@see UpgradeTarget} for the
 * protected-path rule that makes F-6.2-006 true.
 */
class ReleaseArchive
{
    private ZipArchive $zip;

    /** Common prefix stripped from every entry ('' when files sit at root). */
    private string $prefix = '';

    /** @var list<string> in-archive paths that passed every check */
    private array $entries = [];

    /** @var array<string, int> uncompressed size per (prefix-stripped) path */
    private array $sizes = [];

    private function __construct() {}

    /**
     * Open + fully validate. Throws before a single byte is written anywhere.
     *
     * @throws UpgradeException
     */
    public static function open(string $zipPath): self
    {
        $archive = new self;
        $archive->zip = new ZipArchive;

        if (! is_file($zipPath)) {
            throw UpgradeException::atStep(
                UpgradeRun::STEP_VERIFY,
                __('upgrade.errors.archive_missing')
            );
        }

        if ($archive->zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw UpgradeException::atStep(
                UpgradeRun::STEP_VERIFY,
                __('upgrade.errors.archive_unreadable')
            );
        }

        try {
            $archive->scan();
        } catch (UpgradeException $exception) {
            $archive->zip->close();

            throw $exception;
        }

        return $archive;
    }

    public function close(): void
    {
        $this->zip->close();
    }

    /**
     * Prefix-stripped, target-relative paths, sorted for a stable file plan.
     *
     * @return list<string>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function sizeOf(string $relative): int
    {
        return $this->sizes[$relative] ?? 0;
    }

    public function totalBytes(): int
    {
        return array_sum($this->sizes);
    }

    /** Raw bytes of one validated entry, or null when it cannot be read. */
    public function read(string $relative): ?string
    {
        $contents = $this->zip->getFromName($this->prefix.$relative);

        return $contents === false ? null : $contents;
    }

    /**
     * The optional `release.json` at the archive root.
     *
     * @return array<string, mixed>|null
     */
    public function manifest(): ?array
    {
        $file = (string) config('emp-upgrade.manifest_file', 'release.json');
        $raw = $this->zip->getFromName($this->prefix.$file);

        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Cross-check the package against what the release feed advertised.
     * A manifest that disagrees means the download is not the release that
     * was announced — reject rather than guess.
     *
     * @throws UpgradeException
     */
    public function assertMatches(string $expectedVersion): void
    {
        $manifest = $this->manifest();

        if ($manifest === null) {
            return;
        }

        $version = trim((string) ($manifest['version'] ?? ''));

        if ($version === '') {
            return;
        }

        if (! CoreVersion::isValid($version)
            || CoreVersion::normalize($version) !== CoreVersion::normalize($expectedVersion)) {
            throw UpgradeException::atStep(
                UpgradeRun::STEP_VERIFY,
                __('upgrade.errors.manifest_version_mismatch', [
                    'expected' => $expectedVersion,
                    'found' => $version,
                ])
            );
        }
    }

    /**
     * Single validation pass over the central directory.
     *
     * @throws UpgradeException
     */
    private function scan(): void
    {
        $limits = (array) config('emp-upgrade.limits', []);
        $maxEntries = (int) ($limits['max_entries'] ?? 30000);
        $maxBytes = (int) ($limits['max_uncompressed_mb'] ?? 750) * 1024 * 1024;
        $maxRatio = (int) ($limits['max_compression_ratio'] ?? 250);
        $maxPath = (int) ($limits['max_entry_path_length'] ?? 220);
        $blocked = array_map('strval', (array) config('emp-upgrade.blocked_file_extensions', []));

        if ($this->zip->numFiles > $maxEntries) {
            throw $this->reject(__('upgrade.errors.archive_too_many_entries', ['max' => $maxEntries]));
        }

        $total = 0;
        $files = [];
        $sizes = [];
        $topLevels = [];
        $rootFiles = [];

        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $stat = $this->zip->statIndex($i);

            if ($stat === false) {
                continue;
            }

            $name = (string) $stat['name'];

            if ($name === '') {
                continue;
            }

            $normalized = str_replace('\\', '/', $name);

            // --- zip-slip (identical rule set to PluginArchive::scan) ------
            if (str_contains($normalized, '..')
                || str_starts_with($normalized, '/')
                || preg_match('#^[A-Za-z]:#', $normalized) === 1
                || str_contains($normalized, "\0")
                || str_contains($normalized, '://')) {
                throw $this->reject(__('upgrade.errors.archive_unsafe_entry', ['entry' => $name]));
            }

            if (mb_strlen($normalized) > $maxPath) {
                throw $this->reject(__('upgrade.errors.archive_path_too_long', ['entry' => $name]));
            }

            if ($this->isSymlink($i)) {
                throw $this->reject(__('upgrade.errors.archive_symlink', ['entry' => $name]));
            }

            if (str_ends_with($normalized, '/')) {
                $topLevels[explode('/', trim($normalized, '/'))[0]] = 'dir';

                continue;
            }

            // --- zip bomb ---------------------------------------------------
            $size = (int) ($stat['size'] ?? 0);
            $compressed = (int) ($stat['comp_size'] ?? 0);
            $total += $size;

            if ($total > $maxBytes) {
                throw $this->reject(__('upgrade.errors.archive_too_large', [
                    'max' => (int) ($limits['max_uncompressed_mb'] ?? 750),
                ]));
            }

            if ($maxRatio > 0 && $compressed > 0 && $size > 0
                && intdiv($size, max($compressed, 1)) > $maxRatio) {
                throw $this->reject(__('upgrade.errors.archive_compression_ratio', ['entry' => $name]));
            }

            // --- extension policy: block-list only (see class docblock) -----
            $extension = mb_strtolower((string) pathinfo($normalized, PATHINFO_EXTENSION));

            if ($extension !== '' && in_array($extension, $blocked, true)) {
                throw $this->reject(__('upgrade.errors.archive_blocked_file', [
                    'entry' => $name,
                    'extension' => $extension,
                ]));
            }

            $segments = explode('/', $normalized);

            if (count($segments) === 1) {
                $rootFiles[] = $normalized;
            }

            $topLevels[$segments[0]] = count($segments) === 1 ? 'file' : 'dir';

            $files[] = $normalized;
            $sizes[$normalized] = $size;
        }

        if ($files === []) {
            throw $this->reject(__('upgrade.errors.archive_empty'));
        }

        $this->prefix = $this->resolvePrefix($topLevels, $rootFiles);

        $stripped = [];
        $strippedSizes = [];

        foreach ($files as $file) {
            $relative = $this->prefix === '' ? $file : substr($file, strlen($this->prefix));
            $relative = Files::safeRelative($relative);

            if ($relative === '') {
                continue;
            }

            $stripped[] = $relative;
            $strippedSizes[$relative] = $sizes[$file] ?? 0;
        }

        sort($stripped);

        $this->entries = array_values(array_unique($stripped));
        $this->sizes = $strippedSizes;
    }

    /**
     * Publishers zip either the release folder or its contents. Unwrap
     * exactly one top-level folder, and only when nothing sits at the root
     * beside it — the same rule PluginArchive applies, expressed without a
     * manifest to anchor on (a release manifest is optional).
     *
     * @param  array<string, string>  $topLevels  name => 'file'|'dir'
     * @param  list<string>  $rootFiles
     */
    private function resolvePrefix(array $topLevels, array $rootFiles): string
    {
        if ($rootFiles !== []) {
            return '';
        }

        $folders = array_keys(array_filter($topLevels, static fn (string $kind): bool => $kind === 'dir'));

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

    private function reject(string $message): UpgradeException
    {
        return UpgradeException::atStep(UpgradeRun::STEP_VERIFY, $message);
    }
}
