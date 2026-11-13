<?php

declare(strict_types=1);

use App\Filament\Customer\Resources\SubscriberResource;
use App\Filament\Customer\Resources\SubscriberResource\Pages\ViewSubscriber;
use App\Models\CampaignTrackOpen;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Settings\LocationSettings;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * F-2.2.5-003 — geo location + timezone on the subscriber profile. Both
 * states are covered: a configured provider (ip-api, faked at the HTTP layer)
 * and the default null-provider install, which must say "unknown" rather than
 * invent anything (D-10).
 */
beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->for($this->customer)->create();

    $this->subscriber = Subscriber::factory()->for($this->list, 'list')->create([
        'email' => 'geo@x.example',
        'subscribe_ip' => '91.0.0.7',
        'ip' => '91.0.0.7',
    ]);

    $this->geoOf = fn (Subscriber $s): array => Livewire::test(ViewSubscriber::class, ['record' => $s->getRouteKey()])
        ->instance()
        ->geoLocation();
});

function wp27aEnableIpApi(): void
{
    Http::fake(['ip-api.com/*' => Http::response([
        'status' => 'success',
        'country' => 'Germany',
        'countryCode' => 'DE',
        'regionName' => 'Berlin',
        'city' => 'Berlin',
        'timezone' => 'Europe/Berlin',
    ])]);

    $settings = app(LocationSettings::class);
    $settings->provider = LocationSettings::PROVIDER_IP_API;
    $settings->save();
}

it('resolves country, region, city and timezone from the captured IP', function (): void {
    wp27aEnableIpApi();

    $geo = ($this->geoOf)($this->subscriber);

    expect($geo['configured'])->toBeTrue()
        ->and($geo['ip'])->toBe('91.0.0.7')
        ->and($geo['country'])->toBe('Germany')
        ->and($geo['region'])->toBe('Berlin')
        ->and($geo['city'])->toBe('Berlin')
        ->and($geo['timezone'])->toBe('Europe/Berlin');

    $this->get(SubscriberResource::getUrl('view', ['record' => $this->subscriber], panel: 'customer'))
        ->assertOk()
        ->assertSee(__('subscribers.profile.geo_heading'))
        ->assertSee('Europe/Berlin')
        ->assertDontSee(__('subscribers.profile.geo_no_provider'));
});

it('reports an honest unknown when no provider is configured', function (): void {
    Http::fake(); // any outbound call would be a bug

    $geo = ($this->geoOf)($this->subscriber);

    expect($geo['configured'])->toBeFalse()
        ->and($geo['country'])->toBeNull()
        ->and($geo['region'])->toBeNull()
        ->and($geo['city'])->toBeNull()
        ->and($geo['timezone'])->toBeNull()
        // The captured IP is still shown — it is a fact, unlike the location.
        ->and($geo['ip'])->toBe('91.0.0.7');

    Http::assertNothingSent();

    $this->get(SubscriberResource::getUrl('view', ['record' => $this->subscriber], panel: 'customer'))
        ->assertOk()
        ->assertSee(__('subscribers.profile.geo_unknown'))
        ->assertSee(__('subscribers.profile.geo_no_provider'));
});

it('falls back to the geo already recorded on a tracking hit', function (): void {
    Http::fake();

    CampaignTrackOpen::create([
        'campaign_uid' => 'campaign-uid-1',
        'subscriber_uid' => $this->subscriber->uid,
        'country' => 'France',
        'region' => 'Île-de-France',
        'city' => 'Paris',
        'created_at' => now()->subDay(),
    ]);

    $geo = ($this->geoOf)($this->subscriber);

    expect($geo['configured'])->toBeFalse()
        ->and($geo['country'])->toBe('France')
        ->and($geo['region'])->toBe('Île-de-France')
        ->and($geo['city'])->toBe('Paris')
        // No tracking column stores a timezone — it stays unknown.
        ->and($geo['timezone'])->toBeNull();
});

it('shows unknown for a subscriber with no IP at all', function (): void {
    wp27aEnableIpApi();

    $noIp = Subscriber::factory()->for($this->list, 'list')->create([
        'email' => 'noip@x.example',
        'ip' => null,
        'subscribe_ip' => null,
        'confirm_ip' => null,
    ]);

    $geo = ($this->geoOf)($noIp);

    expect($geo['configured'])->toBeTrue()
        ->and($geo['ip'])->toBeNull()
        ->and($geo['country'])->toBeNull()
        ->and($geo['timezone'])->toBeNull();

    Http::assertNothingSent();
});

it('does not expose another tenant\'s subscriber profile', function (): void {
    wp27aEnableIpApi();

    $foreign = Subscriber::factory()->create(['subscribe_ip' => '91.0.0.7']);

    // The tenant scope hides the row entirely — 404, never a leaked profile.
    $this->get(SubscriberResource::getUrl('view', ['record' => $foreign], panel: 'customer'))
        ->assertNotFound();
});
