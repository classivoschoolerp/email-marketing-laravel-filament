<?php

declare(strict_types=1);

namespace App\Services\Upgrade;

use App\Plugins\CoreVersion;
use Illuminate\Support\Carbon;

/**
 * One release as advertised by the configured release feed (spec §6.2
 * F-6.2-001, WP-41).
 *
 * Immutable and dumb on purpose — every decision made from it (is it newer?
 * does this core satisfy its constraint?) is delegated to
 * {@see CoreVersion}, which is the single version authority in
 * the product (D-95). WP-41 does not get its own version grammar.
 */
final class ReleaseInfo
{
    /**
     * @param  string  $changelog  markdown/plain text, rendered escaped
     */
    private function __construct(
        public readonly string $version,
        public readonly ?string $downloadUrl,
        public readonly ?string $checksum,
        public readonly string $checksumAlgo,
        public readonly string $changelog,
        public readonly ?string $minPhp,
        public readonly ?string $requiresCore,
        public readonly bool $mandatory,
        public readonly ?string $notes,
        public readonly ?Carbon $releasedAt,
        public readonly ?int $sizeBytes,
    ) {}

    /**
     * Build from the decoded feed payload. Returns null when the document is
     * unusable — a feed that cannot name a valid semver version is treated as
     * "no release information", never as "you are up to date".
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): ?self
    {
        $version = trim((string) ($payload['version'] ?? ''));

        if ($version === '' || ! CoreVersion::isValid($version)) {
            return null;
        }

        $releasedAt = null;
        $rawDate = $payload['released_at'] ?? null;

        if (is_string($rawDate) && trim($rawDate) !== '') {
            try {
                $releasedAt = Carbon::parse($rawDate);
            } catch (\Throwable) {
                $releasedAt = null;
            }
        }

        $download = self::httpsUrl($payload['download_url'] ?? null);
        $checksum = strtolower(trim((string) ($payload['checksum'] ?? '')));

        return new self(
            version: $version,
            downloadUrl: $download,
            checksum: $checksum === '' ? null : $checksum,
            checksumAlgo: strtolower(trim((string) ($payload['checksum_algo'] ?? 'sha256'))) ?: 'sha256',
            changelog: (string) ($payload['changelog'] ?? ''),
            minPhp: self::nullableString($payload['min_php'] ?? null),
            requiresCore: self::nullableString($payload['requires_core'] ?? null),
            mandatory: (bool) ($payload['mandatory'] ?? false),
            notes: self::nullableString($payload['notes'] ?? null),
            releasedAt: $releasedAt,
            sizeBytes: isset($payload['size']) ? (int) $payload['size'] : null,
        );
    }

    /** Is this release strictly newer than the running core? */
    public function isNewerThan(string $coreVersion): bool
    {
        return version_compare(
            CoreVersion::normalize($this->version),
            CoreVersion::normalize($coreVersion),
            '>',
        );
    }

    /**
     * Can this release actually be installed by the one-click flow, or is it
     * an announcement only? A feed entry with no download URL or no checksum
     * is announced on the dashboard but never armed — installing an
     * unverifiable archive is exactly the failure mode F-6.2-002 exists to
     * prevent.
     */
    public function isInstallable(): bool
    {
        return $this->downloadUrl !== null
            && $this->checksum !== null
            && preg_match('/^[a-f0-9]{32,128}$/', $this->checksum) === 1
            && in_array($this->checksumAlgo, ['sha256', 'sha384', 'sha512'], true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'download_url' => $this->downloadUrl,
            'checksum' => $this->checksum,
            'checksum_algo' => $this->checksumAlgo,
            'changelog' => $this->changelog,
            'min_php' => $this->minPhp,
            'requires_core' => $this->requiresCore,
            'mandatory' => $this->mandatory,
            'notes' => $this->notes,
            'released_at' => $this->releasedAt?->toIso8601String(),
            'size' => $this->sizeBytes,
        ];
    }

    /**
     * Only https, and only a real absolute URL. A release feed handing back
     * `file:///…` or a plain-http mirror is a supply-chain problem, not a
     * convenience.
     */
    private static function httpsUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $url = trim($value);

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return str_starts_with(strtolower($url), 'https://') ? $url : null;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
