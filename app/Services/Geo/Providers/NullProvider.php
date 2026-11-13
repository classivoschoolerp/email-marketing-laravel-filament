<?php

declare(strict_types=1);

namespace App\Services\Geo\Providers;

use App\Services\Geo\GeoLocation;
use App\Services\Geo\IpLocationProvider;

/**
 * Default provider (D-10): no GeoIP backend configured. Always "configured"
 * so nothing ever errors, always returns null so callers record honest nulls
 * instead of invented locations.
 */
final class NullProvider implements IpLocationProvider
{
    public const KEY = 'null';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('settings.location.providers.null');
    }

    public function configured(): bool
    {
        return true;
    }

    public function locate(string $ip): ?GeoLocation
    {
        return null;
    }
}
