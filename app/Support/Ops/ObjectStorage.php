<?php

declare(strict_types=1);

namespace App\Support\Ops;

use App\Services\Settings\SettingsReader;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * §0.1 F-0.1-009 — S3-compatible object storage for template images,
 * attachments, exports and uploads (WP-24).
 *
 * Laravel already ships the `s3` disk (config/filesystems.php), which works
 * against AWS S3, MinIO, Cloudflare R2, Backblaze B2, DigitalOcean Spaces and
 * Wasabi via `AWS_ENDPOINT` + `AWS_USE_PATH_STYLE_ENDPOINT`. What was missing
 * is an operator switch and a way to PROVE the bucket works before pointing
 * production traffic at it.
 *
 * {@see diskName()} is the one place other code asks "where do uploads go?",
 * and {@see smokeTest()} does a real write → read → delete round trip against
 * whatever disk is selected (the local driver in tests, S3 in production).
 */
final class ObjectStorage
{
    public const FALLBACK_DISK = 'public';

    /** The disk uploads should be written to. */
    public static function diskName(string $fallback = self::FALLBACK_DISK): string
    {
        try {
            if (! SettingsReader::ops()->objectStorageEnabled()) {
                return $fallback;
            }

            $disk = SettingsReader::ops()->objectStorageDisk();
        } catch (Throwable) {
            return $fallback;
        }

        return array_key_exists($disk, (array) config('filesystems.disks', [])) ? $disk : $fallback;
    }

    public static function disk(string $fallback = self::FALLBACK_DISK): Filesystem
    {
        return Storage::disk(self::diskName($fallback));
    }

    public static function enabled(): bool
    {
        return self::diskName() !== self::FALLBACK_DISK;
    }

    /**
     * Write → read back → delete a small probe object.
     *
     * @return array{ok: bool, disk: string, driver: string, error: ?string}
     */
    public static function smokeTest(?string $disk = null): array
    {
        $disk ??= self::diskName();
        $driver = (string) config('filesystems.disks.'.$disk.'.driver', 'unknown');
        $path = 'ops/storage-check-'.Str::random(12).'.txt';
        $payload = 'emp-storage-check-'.Str::random(8);

        try {
            $filesystem = Storage::disk($disk);
            $filesystem->put($path, $payload);

            $readBack = (string) $filesystem->get($path);
            $filesystem->delete($path);

            if ($readBack !== $payload) {
                return ['ok' => false, 'disk' => $disk, 'driver' => $driver, 'error' => 'read-back mismatch'];
            }

            return ['ok' => true, 'disk' => $disk, 'driver' => $driver, 'error' => null];
        } catch (Throwable $exception) {
            return ['ok' => false, 'disk' => $disk, 'driver' => $driver, 'error' => $exception->getMessage()];
        }
    }
}
