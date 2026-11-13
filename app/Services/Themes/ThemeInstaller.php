<?php

declare(strict_types=1);

namespace App\Services\Themes;

use App\Models\Theme as ThemeModel;
use App\Services\Security\UploadGuard;
use App\Services\Themes\Exceptions\InvalidThemePackageException;
use App\Settings\SkinSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Installs, replaces and removes theme packages (spec §6.3 F-6.3-005,
 * §3.21 F-3.21-002).
 *
 * GATE ORDER — every gate runs before a single byte is written to the theme
 * directory, mirroring PluginInstaller:
 *   1. WP-22 {@see UploadGuard} (extension allowlist, size cap, AV scan);
 *   2. the theme-specific upload cap (config emp-themes.limits.max_upload_mb);
 *   3. {@see ThemeArchive} — zip-slip, zip bomb, symlinks, and the
 *      no-executable-file rule that separates a theme from a plugin;
 *   4. {@see ThemeManifestValidator} — slug, version, stylesheet, palette;
 *   5. only then: staging directory → swap → DB row.
 *
 * UPGRADE SAFETY (F-6.3-005): packages live under
 * config('emp-themes.path') = storage/app/themes. `git pull`,
 * `composer install` and artifact deploys never touch storage/app, so a
 * custom theme survives a core upgrade untouched — the same guarantee
 * WP-36 gives plugins (D-94).
 */
class ThemeInstaller
{
    public function __construct(
        private readonly UploadGuard $guard,
        private readonly ThemeManifestValidator $validator,
        private readonly ThemeRegistry $registry,
    ) {}

    /**
     * Validate an archive without installing anything.
     *
     * @throws InvalidThemePackageException
     */
    public function inspect(string $zipPath): ThemeManifest
    {
        $this->assertUploadAcceptable($zipPath);

        $archive = ThemeArchive::open($zipPath);

        try {
            return $archive->manifest();
        } finally {
            $archive->close();
        }
    }

    /**
     * Install (or re-install over) a theme package.
     *
     * @throws InvalidThemePackageException
     */
    public function install(string $zipPath): ThemeModel
    {
        $this->assertUploadAcceptable($zipPath);

        $archive = ThemeArchive::open($zipPath);

        try {
            $manifest = $archive->manifest();
            $errors = $this->validator->validate($manifest);

            if ($errors !== []) {
                throw InvalidThemePackageException::withErrors($errors);
            }

            $target = $this->directoryFor($manifest->slug);
            $staging = $target.'.installing';
            $backup = $target.'.backup';

            $this->purge($staging);
            $this->purge($backup);

            $archive->extractTo($staging);

            $hadPrevious = is_dir($target);

            if ($hadPrevious) {
                File::moveDirectory($target, $backup);
            }

            try {
                File::moveDirectory($staging, $target);

                $model = DB::transaction(fn (): ThemeModel => ThemeModel::query()->updateOrCreate(
                    ['slug' => $manifest->slug],
                    [
                        'name' => $manifest->name,
                        'version' => $manifest->version,
                        'author' => $manifest->author,
                        'description' => $manifest->description,
                        'homepage' => $manifest->homepage,
                        'stylesheet' => $manifest->stylesheet,
                        'areas' => $manifest->areas === [] ? SkinSettings::AREAS : $manifest->areas,
                        'palette' => $manifest->palette + ['scheme' => $manifest->scheme],
                        'checksum' => $this->checksum($zipPath),
                    ],
                ));
            } catch (Throwable $e) {
                // Roll the previous version back rather than leaving the
                // installation with no theme where one used to be.
                $this->purge($target);

                if ($hadPrevious) {
                    File::moveDirectory($backup, $target);
                }

                throw $e;
            }

            $this->purge($backup);
            $this->registry->flush();

            return $model;
        } finally {
            $archive->close();
        }
    }

    /**
     * Delete a package (F-3.21-002).
     *
     * Any area still pointing at it is reset to the default first — a
     * dangling slug would otherwise silently fall back on every render, and
     * the settings page would keep showing a theme that no longer exists.
     * Per-user preferences pointing at it are cleared for the same reason.
     */
    public function delete(ThemeModel $theme): void
    {
        $slug = (string) $theme->slug;

        DB::transaction(function () use ($theme, $slug): void {
            $this->releaseSelections($slug);
            $theme->delete();
        });

        $this->purge($this->directoryFor($slug));
        $this->registry->flush();
    }

    /**
     * Absolute directory for a slug. The slug is re-checked here even though
     * the manifest validator already passed it: this is the method that
     * builds a filesystem path, so it does not trust a value that has been
     * through a database round-trip.
     */
    public function directoryFor(string $slug): string
    {
        if (preg_match(ThemeManifestValidator::SLUG_PATTERN, $slug) !== 1) {
            throw new InvalidThemePackageException(
                __('themes.errors.manifest_slug_invalid', ['slug' => $slug])
            );
        }

        return rtrim((string) config('emp-themes.path'), '/\\').DIRECTORY_SEPARATOR.$slug;
    }

    public function purge(string $directory): void
    {
        if ($directory !== '' && is_dir($directory)) {
            File::deleteDirectory($directory);
        }
    }

    /** Point every area/account still using $slug back at the default. */
    private function releaseSelections(string $slug): void
    {
        try {
            $settings = app(SkinSettings::class);
            $dirty = false;

            foreach (SkinSettings::AREAS as $area) {
                $property = 'skin_'.$area;

                if ($settings->{$property} === $slug) {
                    $settings->{$property} = ThemePalettes::DEFAULT;
                    $dirty = true;
                }
            }

            if ($dirty) {
                $settings->save();
            }
        } catch (Throwable) {
            // Settings not migrated yet — nothing can be pointing at it.
        }

        DB::table('users')->where('theme', $slug)->update(['theme' => null]);
        DB::table('customers')->where('theme', $slug)->update(['theme' => null]);
        DB::table('domains')->where('theme', $slug)->update(['theme' => null]);
    }

    private function checksum(string $zipPath): string
    {
        $hash = @hash_file('sha256', $zipPath);

        return $hash === false ? '' : $hash;
    }

    /**
     * @throws InvalidThemePackageException
     */
    private function assertUploadAcceptable(string $zipPath): void
    {
        if (! is_file($zipPath)) {
            throw new InvalidThemePackageException(__('themes.errors.archive_missing'));
        }

        // WP-22 central upload policy first (allowlist, size, AV scanner).
        $errors = $this->guard->checkPath($zipPath, basename($zipPath));

        if ($errors !== []) {
            throw InvalidThemePackageException::withErrors($errors);
        }
    }
}
