<?php

declare(strict_types=1);

namespace App\Services\Upgrade;

use App\Plugins\CoreVersion;
use App\Services\License\LicenseService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches the configured release feed (spec §6.2 F-6.2-001, WP-41).
 *
 * Deliberately shaped like {@see LicenseService}
 * (D-09/D-39): a configurable endpoint, a short timeout, and a hard rule that
 * an unreachable server NEVER changes application behaviour — it reports
 * "unknown", not "up to date" and not "upgrade available". An install whose
 * vendor has gone offline must keep working exactly as it did.
 *
 * The response is cached for `emp-upgrade.release.cache_ttl` seconds so the
 * dashboard costs nothing to render; `check(force: true)` busts it.
 */
class ReleaseChecker
{
    public const CACHE_KEY = 'upgrade:latest-release';

    public const NOTIFIED_CACHE_PREFIX = 'upgrade:notified:';

    /** No endpoint configured — the feature is opt-in. */
    public const STATUS_DISABLED = 'disabled';

    /** Endpoint configured but unreachable/unparsable. */
    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_CURRENT = 'current';

    public const STATUS_AVAILABLE = 'available';

    public function endpoint(): ?string
    {
        $endpoint = config('emp-upgrade.release.endpoint');

        if (! is_string($endpoint) || trim($endpoint) === '') {
            return null;
        }

        return trim($endpoint);
    }

    public function channel(): string
    {
        return (string) config('emp-upgrade.release.channel', 'stable');
    }

    public function currentVersion(): string
    {
        return CoreVersion::current();
    }

    /**
     * @return array{status: string, current: string, release: ReleaseInfo|null, message: string|null, checked_at: string|null}
     */
    public function check(bool $force = false): array
    {
        $endpoint = $this->endpoint();
        $current = $this->currentVersion();

        if ($endpoint === null) {
            return $this->result(self::STATUS_DISABLED, $current, null, __('upgrade.release.no_endpoint'));
        }

        if ($force) {
            $this->forget();
        }

        $payload = $this->cached($endpoint);

        if ($payload === null) {
            return $this->result(self::STATUS_UNKNOWN, $current, null, __('upgrade.release.unreachable'));
        }

        $release = ReleaseInfo::fromArray($this->selectChannel($payload));

        if ($release === null) {
            return $this->result(self::STATUS_UNKNOWN, $current, null, __('upgrade.release.unparsable'));
        }

        $status = $release->isNewerThan($current) ? self::STATUS_AVAILABLE : self::STATUS_CURRENT;

        return $this->result($status, $current, $release, null);
    }

    /** The newer release, or null when there is nothing to install. */
    public function latest(bool $force = false): ?ReleaseInfo
    {
        $result = $this->check($force);

        return $result['status'] === self::STATUS_AVAILABLE ? $result['release'] : null;
    }

    public function forget(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // Cache unavailable — the TTL is the fallback.
        }
    }

    /**
     * Have we already emailed about this version? Deduplicated per version so
     * a daily check cannot mail the same announcement 30 times (same shape as
     * the WP-24 threshold-alert dedup and D-72 quota notices).
     */
    public function alreadyNotified(string $version): bool
    {
        try {
            return Cache::get(self::NOTIFIED_CACHE_PREFIX.$version) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    public function markNotified(string $version): void
    {
        try {
            Cache::put(self::NOTIFIED_CACHE_PREFIX.$version, true, now()->addDays(90));
        } catch (Throwable) {
            // Best effort; a duplicate notice is preferable to a crash.
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function cached(string $endpoint): ?array
    {
        try {
            $cached = Cache::get(self::CACHE_KEY);

            if (is_array($cached)) {
                return $cached;
            }
        } catch (Throwable) {
            // Fall through to a live fetch.
        }

        $payload = $this->fetch($endpoint);

        if ($payload === null) {
            return null;
        }

        try {
            Cache::put(self::CACHE_KEY, $payload, (int) config('emp-upgrade.release.cache_ttl', 1800));
        } catch (Throwable) {
            // Not fatal.
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetch(string $endpoint): ?array
    {
        try {
            $response = Http::acceptJson()
                ->timeout((int) config('emp-upgrade.release.timeout', 15))
                ->withHeaders([
                    // Lets a vendor serve channel/compat-aware documents
                    // without the installation sending anything identifying.
                    'X-EMP-Core-Version' => $this->currentVersion(),
                    'X-EMP-Channel' => $this->channel(),
                ])
                ->get($endpoint, ['channel' => $this->channel(), 'version' => $this->currentVersion()]);
        } catch (Throwable $exception) {
            Log::warning('upgrade.release.fetch_failed', ['error' => $exception->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : null;
    }

    /**
     * A feed may publish one release, or a `channels` map. Anything else is
     * treated as the single-release form.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function selectChannel(array $payload): array
    {
        $channels = $payload['channels'] ?? null;

        if (! is_array($channels) || $channels === []) {
            return $payload;
        }

        $selected = $channels[$this->channel()] ?? $channels['stable'] ?? null;

        return is_array($selected) ? $selected : $payload;
    }

    /**
     * @return array{status: string, current: string, release: ReleaseInfo|null, message: string|null, checked_at: string|null}
     */
    private function result(string $status, string $current, ?ReleaseInfo $release, ?string $message): array
    {
        return [
            'status' => $status,
            'current' => $current,
            'release' => $release,
            'message' => $message,
            'checked_at' => $status === self::STATUS_DISABLED ? null : now()->toIso8601String(),
        ];
    }
}
