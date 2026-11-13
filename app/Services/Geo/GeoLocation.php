<?php

declare(strict_types=1);

namespace App\Services\Geo;

/**
 * Result of an IP → location lookup (spec §3.23, D-10). Every field is
 * nullable: providers differ in granularity and an honest "unknown" is always
 * preferable to an invented value.
 */
final readonly class GeoLocation
{
    public function __construct(
        public string $ip,
        public ?string $countryCode = null,
        public ?string $countryName = null,
        public ?string $regionCode = null,
        public ?string $regionName = null,
        public ?string $city = null,
        public ?string $timezone = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?string $provider = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->countryCode === null
            && $this->countryName === null
            && $this->regionName === null
            && $this->city === null
            && $this->timezone === null;
    }

    /**
     * Columns shared by campaign_track_opens / campaign_track_clicks
     * (country/region/city already exist since WP-09).
     *
     * @return array{country: ?string, region: ?string, city: ?string}
     */
    public function trackingColumns(): array
    {
        return [
            'country' => $this->countryName ?? $this->countryCode,
            'region' => $this->regionName ?? $this->regionCode,
            'city' => $this->city,
        ];
    }

    /** Single-line label for login_logs.location and admin tables. */
    public function label(): string
    {
        $parts = array_values(array_filter([
            $this->city,
            $this->regionName ?? $this->regionCode,
            $this->countryName ?? $this->countryCode,
        ], static fn (?string $part): bool => $part !== null && $part !== ''));

        return implode(', ', $parts);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ip' => $this->ip,
            'country_code' => $this->countryCode,
            'country_name' => $this->countryName,
            'region_code' => $this->regionCode,
            'region_name' => $this->regionName,
            'city' => $this->city,
            'timezone' => $this->timezone,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'provider' => $this->provider,
        ];
    }
}
