<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Services\Settings\SettingsReader;
use App\Services\Templates\TemplateZipImporter;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Central upload validation (spec §3.25.15 F-3.25.15-006, WP-22).
 *
 * One place decides whether a file may enter the system: extension against
 * the operator allowlist, MIME sanity against the claimed extension, hard
 * size cap, and — when a scanner is configured — an anti-virus verdict.
 *
 * Every check reads the §3.25.15 settings, so an operator tightens uploads
 * install-wide from one page. `check()` returns the list of failure messages
 * (empty = accepted); `validate()` is the throwing form used by call sites
 * that want an exception.
 *
 * Wired as proof into the template/campaign ZIP import path
 * ({@see TemplateZipImporter} callers); every other
 * upload path (import CSV, campaign attachments, avatars, GeoIP databases)
 * can adopt it by calling `check()` before persisting — see
 * docs/upload-guard.md.
 */
class UploadGuard
{
    /**
     * Extensions that must never be accepted whatever the allowlist says —
     * anything the web server or a PHP include could execute.
     *
     * @var array<int, string>
     */
    public const ALWAYS_BLOCKED = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht',
        'exe', 'com', 'bat', 'cmd', 'sh', 'bash', 'cgi', 'pl', 'py', 'jsp',
        'asp', 'aspx', 'dll', 'so', 'msi', 'scr', 'vbs', 'ps1', 'jar', 'htaccess',
    ];

    /**
     * Expected MIME prefixes per extension. Only extensions listed here are
     * MIME-checked; anything else passes the MIME step (browsers and OSes
     * disagree too often to be strict everywhere).
     *
     * @var array<string, array<int, string>>
     */
    private const MIME_MAP = [
        'csv' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel', 'application/octet-stream'],
        'txt' => ['text/plain'],
        'zip' => ['application/zip', 'application/x-zip-compressed', 'application/octet-stream', 'multipart/x-zip'],
        'html' => ['text/html', 'text/plain'],
        'htm' => ['text/html', 'text/plain'],
        'json' => ['application/json', 'text/plain'],
        'xml' => ['application/xml', 'text/xml', 'text/plain'],
        'pdf' => ['application/pdf'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'svg' => ['image/svg+xml', 'text/xml', 'text/plain'],
    ];

    /**
     * @return array<int, string> failure messages; empty means accepted
     */
    public function check(UploadedFile $file): array
    {
        $errors = [];

        $extension = mb_strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension()));
        $name = (string) $file->getClientOriginalName();

        if (in_array($extension, self::ALWAYS_BLOCKED, true)) {
            return [__('settings.security.upload.blocked_extension', ['extension' => $extension])];
        }

        $allowed = $this->allowedExtensions();

        if ($allowed !== [] && ! in_array($extension, $allowed, true)) {
            $errors[] = __('settings.security.upload.extension_not_allowed', [
                'extension' => $extension === '' ? '—' : $extension,
                'allowed' => implode(', ', $allowed),
            ]);
        }

        $maxBytes = $this->maxBytes();

        if ($maxBytes > 0 && $file->getSize() !== false && $file->getSize() > $maxBytes) {
            $errors[] = __('settings.security.upload.too_large', [
                'name' => $name,
                'max' => $this->maxMb(),
            ]);
        }

        if (! $this->mimeMatches($extension, (string) $file->getMimeType())) {
            $errors[] = __('settings.security.upload.mime_mismatch', [
                'extension' => $extension,
                'mime' => (string) $file->getMimeType(),
            ]);
        }

        // Only scan a file that already passed the cheap checks.
        if ($errors === []) {
            $path = $file->getRealPath();

            if (is_string($path) && $path !== '') {
                $result = $this->scan($path);

                if ($result->infected) {
                    $errors[] = __('settings.security.upload.infected', [
                        'name' => $name,
                        'signature' => (string) $result->signature,
                    ]);
                }
            }
        }

        return $errors;
    }

    public function passes(UploadedFile $file): bool
    {
        return $this->check($file) === [];
    }

    /**
     * Same policy for a file already persisted to disk — the shape Filament
     * hands downstream services (the FileUpload component stores first, then
     * passes a path). `$name` supplies the client-visible file name when the
     * stored path carries a generated one.
     *
     * @return array<int, string> failure messages; empty means accepted
     */
    public function checkPath(string $absolutePath, ?string $name = null): array
    {
        $name ??= basename($absolutePath);
        $fromName = mb_strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        // Content wins over the file name here: this entry point receives
        // already-stored paths (tempnam(), Filament's FileUpload, queue
        // payloads) whose generated names routinely say `.tmp`.
        $fromContent = $this->extensionFromContent($absolutePath);
        $extension = $fromContent !== '' ? $fromContent : $fromName;

        foreach (array_unique([$fromName, $fromContent]) as $candidate) {
            if (in_array($candidate, self::ALWAYS_BLOCKED, true)) {
                return [__('settings.security.upload.blocked_extension', ['extension' => $candidate])];
            }
        }

        $errors = [];
        $allowed = $this->allowedExtensions();

        // An unclassifiable file cannot be judged by the extension policy;
        // the size cap and the scanner below still apply to it.
        if ($extension !== '' && $allowed !== [] && ! in_array($extension, $allowed, true)) {
            $errors[] = __('settings.security.upload.extension_not_allowed', [
                'extension' => $extension,
                'allowed' => implode(', ', $allowed),
            ]);
        }

        $maxBytes = $this->maxBytes();
        $size = is_file($absolutePath) ? (int) filesize($absolutePath) : 0;

        if ($maxBytes > 0 && $size > $maxBytes) {
            $errors[] = __('settings.security.upload.too_large', [
                'name' => $name,
                'max' => $this->maxMb(),
            ]);
        }

        if ($errors === []) {
            $result = $this->scan($absolutePath);

            if ($result->infected) {
                $errors[] = __('settings.security.upload.infected', [
                    'name' => $name,
                    'signature' => (string) $result->signature,
                ]);
            }
        }

        return $errors;
    }

    /**
     * Throwing form for call sites that prefer an exception.
     *
     * @throws UploadRejectedException
     */
    public function validate(UploadedFile $file): void
    {
        $errors = $this->check($file);

        if ($errors !== []) {
            throw new UploadRejectedException($errors);
        }
    }

    /** Scan a file already on disk (import jobs, restored archives). */
    public function scan(string $absolutePath): ScanResult
    {
        return $this->scanner()->scan($absolutePath);
    }

    /** The configured scanner, or the no-op one when scanning is disabled. */
    public function scanner(): VirusScanner
    {
        try {
            $security = SettingsReader::security();

            if (! $security->uploadScanEnabled()) {
                return new NullVirusScanner;
            }

            return match ($security->uploadScannerDriver()) {
                'clamav' => new ClamAvSocketScanner(
                    $security->uploadScannerHost(),
                    $security->uploadScannerPort(),
                ),
                default => new NullVirusScanner,
            };
        } catch (Throwable) {
            return new NullVirusScanner;
        }
    }

    /** @return array<int, string> */
    public function allowedExtensions(): array
    {
        try {
            return SettingsReader::security()->uploadAllowedExtensions();
        } catch (Throwable) {
            return [];
        }
    }

    public function maxMb(): int
    {
        try {
            return SettingsReader::security()->uploadMaxSizeMb();
        } catch (Throwable) {
            return 0;
        }
    }

    public function maxBytes(): int
    {
        return $this->maxMb() * 1024 * 1024;
    }

    /**
     * Best-effort extension for a file whose name carries none, derived from
     * the detected MIME type via the same map used for the mismatch check.
     * Returns '' when the content cannot be classified.
     */
    private function extensionFromContent(string $absolutePath): string
    {
        if (! is_file($absolutePath) || ! function_exists('mime_content_type')) {
            return '';
        }

        $mime = @mime_content_type($absolutePath);

        if (! is_string($mime) || $mime === '') {
            return '';
        }

        $mime = mb_strtolower($mime);

        foreach (self::MIME_MAP as $extension => $mimes) {
            // application/octet-stream is a catch-all — never let it decide.
            if ($mime !== 'application/octet-stream' && in_array($mime, $mimes, true)) {
                return $extension;
            }
        }

        return '';
    }

    private function mimeMatches(string $extension, string $mime): bool
    {
        if ($mime === '' || ! isset(self::MIME_MAP[$extension])) {
            return true;
        }

        return in_array(mb_strtolower($mime), self::MIME_MAP[$extension], true);
    }
}
