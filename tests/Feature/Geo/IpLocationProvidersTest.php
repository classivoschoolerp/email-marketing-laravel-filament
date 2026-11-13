<?php

declare(strict_types=1);

use App\Services\Geo\GeoProviderRegistry;
use App\Services\Geo\IpLocationProvider;
use App\Services\Geo\IpLocationResolver;
use App\Services\Geo\MaxMindDatabaseUpdater;
use App\Services\Geo\Providers\IpApiProvider;
use App\Services\Geo\Providers\IpInfoProvider;
use App\Services\Geo\Providers\MaxMindDbProvider;
use App\Services\Geo\Providers\NullProvider;
use App\Settings\LocationSettings;
use App\Support\Geo\MaxMindDbReader;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MaxMindDbFixture;

// §3.23 F-3.23-001/002 — provider interface + registry (D-10), the three
// adapters and the null default.

function wp24Settings(array $values = []): LocationSettings
{
    $settings = app(LocationSettings::class);

    foreach ($values as $key => $value) {
        $settings->{$key} = $value;
    }

    $settings->save();

    return $settings;
}

/* ================================================================ *
 *  Registry (F-3.23-001) — plugin-registrable, same shape as WP-14
 * ================================================================ */

it('registers the four built-in providers and accepts plugin registrations', function (): void {
    $registry = app(GeoProviderRegistry::class);

    expect($registry->keys())->toContain('null', 'maxmind', 'ip-api', 'ipinfo')
        ->and($registry->provider('null'))->toBeInstanceOf(NullProvider::class)
        ->and($registry->options())->toHaveKey('ipinfo');

    // §6.1 plugin path: a vendor adds itself with no core change.
    $plugin = new class implements IpLocationProvider
    {
        public function key(): string
        {
            return 'acme-geo';
        }

        public function label(): string
        {
            return 'ACME Geo';
        }

        public function configured(): bool
        {
            return true;
        }

        public function locate(string $ip): ?\App\Services\Geo\GeoLocation
        {
            return new \App\Services\Geo\GeoLocation(ip: $ip, countryCode: 'MA', provider: 'acme-geo');
        }
    };

    $registry->register('acme-geo', $plugin);

    expect($registry->has('acme-geo'))->toBeTrue()
        ->and($registry->provider('acme-geo')->locate('9.9.9.9')?->countryCode)->toBe('MA');

    expect(fn () => $registry->register('bad', \stdClass::class))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $registry->provider('nope'))->toThrow(InvalidArgumentException::class);
});

it('resolves nothing and touches no provider while the null provider is selected', function (): void {
    wp24Settings(['provider' => LocationSettings::PROVIDER_NULL]);
    Http::fake(); // any outbound call would fail the assertion below

    $resolver = app(IpLocationResolver::class);

    expect($resolver->enabled())->toBeFalse()
        ->and($resolver->locate('8.8.8.8'))->toBeNull()
        ->and($resolver->lookup('8.8.8.8'))->toBeNull();

    Http::assertNothingSent();
});

/* ================================================================ *
 *  MaxMind local database (F-3.23-002)
 * ================================================================ */

it('reads a MaxMind database written from the published binary format', function (): void {
    $path = storage_path('framework/testing/wp24-geo/GeoLite2-City-Test.mmdb');
    MaxMindDbFixture::cityDatabase($path);

    $reader = new MaxMindDbReader($path);

    expect($reader->databaseType())->toBe('GeoLite2-City-Test')
        ->and($reader->buildEpoch())->toBe(1710000000)
        ->and($reader->metadata()['ip_version'])->toBe(4);

    $record = $reader->get('1.2.3.4');

    expect($record['country']['iso_code'])->toBe('US')
        ->and($record['country']['names']['fr'])->toBe('États-Unis')       // utf8 round trip
        ->and($record['subdivisions'][0]['iso_code'])->toBe('CA')
        ->and($record['city']['names']['en'])->toBe('Mountain View')
        ->and($record['location']['time_zone'])->toBe('America/Los_Angeles')
        ->and(round((float) $record['location']['latitude'], 3))->toBe(37.386)
        ->and($record['location']['accuracy_radius'])->toBe(1000);

    // A second network in the same tree resolves independently…
    expect($reader->get('8.8.8.8')['city']['names']['en'])->toBe('Mountain View');

    // …and an address outside every network is an honest null.
    expect($reader->get('9.9.9.9'))->toBeNull();

    @unlink($path);
});

it('maps a MaxMind record onto the GeoLocation contract', function (): void {
    $relative = 'geoip/GeoLite2-City-Test.mmdb';
    $absolute = Storage::disk('local')->path($relative);
    MaxMindDbFixture::cityDatabase($absolute);

    wp24Settings([
        'provider' => LocationSettings::PROVIDER_MAXMIND,
        'maxmind_database_path' => $relative,
    ]);

    $provider = app(MaxMindDbProvider::class);

    expect($provider->configured())->toBeTrue()
        ->and($provider->databaseInfo()['type'])->toBe('GeoLite2-City-Test');

    $location = $provider->locate('1.2.3.4');

    expect($location->countryCode)->toBe('US')
        ->and($location->countryName)->toBe('United States')
        ->and($location->regionCode)->toBe('CA')
        ->and($location->regionName)->toBe('California')
        ->and($location->city)->toBe('Mountain View')
        ->and($location->timezone)->toBe('America/Los_Angeles')
        ->and($location->provider)->toBe('maxmind')
        ->and($location->label())->toBe('Mountain View, California, United States')
        ->and($location->trackingColumns())->toBe([
            'country' => 'United States',
            'region' => 'California',
            'city' => 'Mountain View',
        ]);

    Storage::disk('local')->delete($relative);
});

it('reports honestly when no MaxMind database has been uploaded', function (): void {
    wp24Settings([
        'provider' => LocationSettings::PROVIDER_MAXMIND,
        'maxmind_database_path' => null,
    ]);

    $provider = app(MaxMindDbProvider::class);

    expect($provider->configured())->toBeFalse()
        ->and($provider->databaseInfo())->toBeNull()
        ->and(app(IpLocationResolver::class)->enabled())->toBeFalse();

    expect(fn () => $provider->locate('1.2.3.4'))
        ->toThrow(RuntimeException::class, __('settings.location.errors.maxmind_missing'));
});

it('downloads and unpacks a MaxMind tar.gz update', function (): void {
    $mmdb = 'MMDB-BYTES-'.str_repeat('x', 40);
    $archive = gzencode(wp24Tar([
        'GeoLite2-City_20260808/COPYRIGHT.txt' => 'copyright',
        'GeoLite2-City_20260808/GeoLite2-City.mmdb' => $mmdb,
    ]));

    Http::fake([
        'download.maxmind.com/*' => Http::response($archive, 200),
    ]);

    wp24Settings([
        'provider' => LocationSettings::PROVIDER_MAXMIND,
        'maxmind_edition' => 'GeoLite2-City',
        'maxmind_license_key' => 'test-license-key',
        'maxmind_auto_update' => true,
    ]);

    $path = app(MaxMindDatabaseUpdater::class)->update();

    expect($path)->toBe('geoip/GeoLite2-City.mmdb')
        ->and(Storage::disk('local')->get($path))->toBe($mmdb)
        ->and(app(LocationSettings::class)->maxmind_database_path)->toBe($path)
        ->and(app(LocationSettings::class)->maxmind_updated_at)->not->toBeNull();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'edition_id=GeoLite2-City')
        && str_contains($request->url(), 'license_key=test-license-key'));
});

it('refuses to auto-update without a license key and skips when the toggle is off', function (): void {
    wp24Settings([
        'provider' => LocationSettings::PROVIDER_MAXMIND,
        'maxmind_license_key' => null,
        'maxmind_auto_update' => false,
    ]);

    expect(fn () => app(MaxMindDatabaseUpdater::class)->update())
        ->toThrow(RuntimeException::class, __('settings.location.errors.maxmind_license_missing'));

    // Scheduled run respects the toggle instead of erroring every night.
    $this->artisan('geo:update-database')
        ->expectsOutputToContain(__('ops.geo.auto_update_disabled'))
        ->assertSuccessful();
});

/* ================================================================ *
 *  Hosted providers (F-3.23-001) — contract-locked with Http::fake
 * ================================================================ */

it('maps an ip-api.com success response', function (): void {
    Http::fake([
        'ip-api.com/*' => Http::response([
            'status' => 'success',
            'country' => 'Morocco',
            'countryCode' => 'MA',
            'region' => '07',
            'regionName' => 'Casablanca-Settat',
            'city' => 'Casablanca',
            'timezone' => 'Africa/Casablanca',
            'lat' => 33.5731,
            'lon' => -7.5898,
        ]),
    ]);

    wp24Settings(['provider' => LocationSettings::PROVIDER_IP_API]);

    $location = app(IpApiProvider::class)->locate('41.140.0.1');

    expect($location->countryCode)->toBe('MA')
        ->and($location->countryName)->toBe('Morocco')
        ->and($location->regionName)->toBe('Casablanca-Settat')
        ->and($location->city)->toBe('Casablanca')
        ->and($location->timezone)->toBe('Africa/Casablanca')
        ->and($location->latitude)->toBe(33.5731)
        ->and($location->provider)->toBe('ip-api');
});

it('returns null on an ip-api failure status', function (): void {
    Http::fake(['ip-api.com/*' => Http::response(['status' => 'fail', 'message' => 'reserved range'])]);

    expect(app(IpApiProvider::class)->locate('10.0.0.1'))->toBeNull();
});

it('throws on an ip-api transport error so the operator sees the real reason', function (): void {
    Http::fake(['ip-api.com/*' => Http::response('nope', 500)]);

    expect(fn () => app(IpApiProvider::class)->locate('8.8.8.8'))
        ->toThrow(RuntimeException::class);
});

it('uses the ip-api pro endpoint when a key is configured', function (): void {
    Http::fake(['pro.ip-api.com/*' => Http::response(['status' => 'success', 'countryCode' => 'FR'])]);

    wp24Settings(['provider' => LocationSettings::PROVIDER_IP_API, 'ipapi_key' => 'pro-key']);

    expect(app(IpApiProvider::class)->locate('90.0.0.1')?->countryCode)->toBe('FR');

    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://pro.ip-api.com/')
        && str_contains($request->url(), 'key=pro-key'));
});

it('maps an ipinfo.io response and requires a token', function (): void {
    wp24Settings(['provider' => LocationSettings::PROVIDER_IPINFO, 'ipinfo_token' => null]);

    expect(app(IpInfoProvider::class)->configured())->toBeFalse();
    expect(fn () => app(IpInfoProvider::class)->locate('8.8.8.8'))
        ->toThrow(RuntimeException::class, __('settings.location.errors.ipinfo_token_missing'));

    Http::fake([
        'ipinfo.io/*' => Http::response([
            'ip' => '8.8.8.8',
            'city' => 'Mountain View',
            'region' => 'California',
            'country' => 'US',
            'loc' => '37.4056,-122.0775',
            'timezone' => 'America/Los_Angeles',
        ]),
    ]);

    wp24Settings(['ipinfo_token' => 'tok_123']);

    $provider = app(IpInfoProvider::class);

    expect($provider->configured())->toBeTrue();

    $location = $provider->locate('8.8.8.8');

    expect($location->countryCode)->toBe('US')
        ->and($location->countryName)->toBe('United States') // resolved from the countries dataset
        ->and($location->regionName)->toBe('California')
        ->and($location->city)->toBe('Mountain View')
        ->and($location->latitude)->toBe(37.4056)
        ->and($location->longitude)->toBe(-122.0775);

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer tok_123'));
});

/* ================================================================ *
 *  Resolver behaviour on the capture path
 * ================================================================ */

it('caches lookups, skips private ranges and never throws at capture time', function (): void {
    Http::fake(['ip-api.com/*' => Http::response(['status' => 'success', 'countryCode' => 'DE', 'country' => 'Germany'])]);

    wp24Settings(['provider' => LocationSettings::PROVIDER_IP_API, 'cache_ttl_minutes' => 60]);

    $resolver = app(IpLocationResolver::class);

    expect($resolver->enabled())->toBeTrue()
        ->and($resolver->routable('192.168.1.1'))->toBeFalse()
        ->and($resolver->locate('192.168.1.1'))->toBeNull();

    Http::assertNothingSent();

    expect($resolver->locate('91.0.0.1')?->countryCode)->toBe('DE')
        ->and($resolver->locate('91.0.0.1')?->countryCode)->toBe('DE');

    Http::assertSentCount(1); // second call served from cache

    // A provider outage must not lose the tracking hit.
    Http::fake(['ip-api.com/*' => fn () => throw new RuntimeException('network down')]);

    expect($resolver->locate('91.0.0.2'))->toBeNull();
});

/**
 * Minimal USTAR writer so the updater test uses a real archive.
 *
 * @param  array<string, string>  $files
 */
function wp24Tar(array $files): string
{
    $tar = '';

    foreach ($files as $name => $contents) {
        $header = str_pad(substr($name, 0, 100), 100, "\0");
        $header .= str_pad('0000644', 8, "\0");            // mode
        $header .= str_pad('0000000', 8, "\0");            // uid
        $header .= str_pad('0000000', 8, "\0");            // gid
        $header .= str_pad(sprintf('%011o', strlen($contents)), 12, "\0");
        $header .= str_pad(sprintf('%011o', time()), 12, "\0");
        $header .= str_repeat(' ', 8);                     // checksum placeholder
        $header .= '0';                                    // typeflag: regular file
        $header = str_pad($header, 512, "\0");

        $checksum = 0;

        for ($i = 0; $i < 512; $i++) {
            $checksum += ord($header[$i]);
        }

        $header = substr_replace($header, str_pad(sprintf('%06o', $checksum), 6, '0', STR_PAD_LEFT)."\0 ", 148, 8);

        $tar .= $header;
        $tar .= str_pad($contents, (int) (ceil(strlen($contents) / 512) * 512), "\0");
    }

    return $tar.str_repeat("\0", 1024);
}
