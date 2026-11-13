<?php

declare(strict_types=1);

use App\Enums\LoginResult;
use App\Models\CampaignTrackOpen;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\LoginLog;
use App\Models\Subscriber;
use App\Settings\LocationSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

// §3.23 wiring — tracking hits and sign-in records are geo-tagged at capture
// time when a provider is configured, and untouched when it is not. This is
// what unblocks the WP-17 geo report row.

function wp24TrackingSubscriber(): Subscriber
{
    $customer = Customer::factory()->create();
    $list = EmailList::factory()->create(['customer_id' => $customer->id]);

    return Subscriber::factory()->create(['customer_id' => $customer->id, 'list_id' => $list->id]);
}

/** Select ip-api and stub it with one response (or a throwing closure). */
function wp24EnableIpApi(mixed $stub = null): void
{
    Http::fake(['ip-api.com/*' => $stub ?? Http::response([
        'status' => 'success',
        'country' => 'Germany',
        'countryCode' => 'DE',
        'regionName' => 'Berlin',
        'city' => 'Berlin',
        'timezone' => 'Europe/Berlin',
    ])]);

    $settings = app(LocationSettings::class);
    $settings->provider = LocationSettings::PROVIDER_IP_API;
    $settings->enrich_tracking = true;
    $settings->enrich_login_logs = true;
    $settings->save();
}

function wp24LoginRequest(string $ip = '91.0.0.7'): Request
{
    return Request::create('/customer/login', 'POST', server: ['REMOTE_ADDR' => $ip]);
}

/* ================================================================ *
 *  Default install: geo stays null, and no provider is contacted
 * ================================================================ */

it('leaves tracking geo columns null while no provider is configured', function (): void {
    Http::fake();

    $subscriber = wp24TrackingSubscriber();

    $this->withServerVariables(['REMOTE_ADDR' => '91.0.0.5'])
        ->get("/campaigns/demo-uid/track-opening/{$subscriber->uid}")
        ->assertOk();

    $open = CampaignTrackOpen::query()->firstOrFail();

    expect($open->country)->toBeNull()
        ->and($open->region)->toBeNull()
        ->and($open->city)->toBeNull()
        // The WP-09 columns must keep working exactly as before.
        ->and($open->campaign_uid)->toBe('demo-uid')
        ->and($open->subscriber_uid)->toBe($subscriber->uid);

    Http::assertNothingSent();
});

it('leaves login log location null while no provider is configured', function (): void {
    Http::fake();

    $customer = Customer::factory()->create();

    expect(LoginLog::record('customer', $customer, $customer->email, LoginResult::Success, wp24LoginRequest())->location)
        ->toBeNull();

    Http::assertNothingSent();
});

/* ================================================================ *
 *  With a provider: capture-time enrichment
 * ================================================================ */

it('geo-tags an open at capture time', function (): void {
    wp24EnableIpApi();

    $subscriber = wp24TrackingSubscriber();

    $this->withServerVariables(['REMOTE_ADDR' => '91.0.0.5'])
        ->get("/campaigns/demo-uid/track-opening/{$subscriber->uid}")
        ->assertOk();

    $open = CampaignTrackOpen::query()->firstOrFail();

    expect($open->country)->toBe('Germany')
        ->and($open->region)->toBe('Berlin')
        ->and($open->city)->toBe('Berlin');
});

it('geo-tags a sign-in record at capture time', function (): void {
    wp24EnableIpApi(Http::response([
        'status' => 'success',
        'country' => 'Germany',
        'countryCode' => 'DE',
        'regionName' => 'Bavaria',
        'city' => 'Munich',
        'timezone' => 'Europe/Berlin',
    ]));

    $customer = Customer::factory()->create();

    $log = LoginLog::record('customer', $customer, $customer->email, LoginResult::Success, wp24LoginRequest());

    expect($log->location)->toBe('Munich, Bavaria, Germany');
});

it('respects the per-surface enrichment toggles', function (): void {
    wp24EnableIpApi();

    $settings = app(LocationSettings::class);
    $settings->enrich_tracking = false;
    $settings->enrich_login_logs = false;
    $settings->save();

    $subscriber = wp24TrackingSubscriber();

    $this->withServerVariables(['REMOTE_ADDR' => '91.0.0.5'])
        ->get("/campaigns/demo-uid/track-opening/{$subscriber->uid}")
        ->assertOk();

    expect(CampaignTrackOpen::query()->firstOrFail()->country)->toBeNull();

    $customer = Customer::factory()->create();

    expect(LoginLog::record('customer', $customer, $customer->email, LoginResult::Success, wp24LoginRequest())->location)
        ->toBeNull();
});

it('never loses a tracking hit when the geo provider fails', function (): void {
    wp24EnableIpApi(fn () => throw new RuntimeException('provider down'));

    $subscriber = wp24TrackingSubscriber();

    $this->withServerVariables(['REMOTE_ADDR' => '91.0.0.5'])
        ->get("/campaigns/demo-uid/track-opening/{$subscriber->uid}")
        ->assertOk();

    $open = CampaignTrackOpen::query()->firstOrFail();

    expect($open->country)->toBeNull()
        ->and($open->subscriber_uid)->toBe($subscriber->uid);
});
