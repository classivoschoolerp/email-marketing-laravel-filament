<?php

declare(strict_types=1);

use App\Filament\Customer\Resources\LandingPageResource\Pages\LandingPageAnalytics;
use App\Models\Customer;
use App\Models\LandingPage;
use App\Models\LandingPageVisit;
use App\Services\Landing\LandingPageStats;
use App\Services\Landing\LandingPageTracking;
use App\Settings\PrivacySettings;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Tests\Support\AutomationFixtures as Fx;
use Tests\Support\LandingTestHelpers;

/*
 * §2.6 F-2.6-008 / §1.8 F-1.8-003 — visit + conversion tracking.
 *
 * page visits, UNIQUE visitors, conversions and source, plus the privacy
 * guarantees: the visitor cookie is stored as a one-way hash and the IP
 * honours the §3.25.16 anonymise-IPs setting.
 */

beforeEach(function (): void {
    config()->set('app.url', 'http://localhost');

    $this->customer = Fx::actingAsCustomer();
    $this->page = LandingPage::factory()->published()->create([
        'customer_id' => $this->customer->id,
        'slug' => 'tracked',
    ]);
});

it('records one visit per view and counts unique visitors once (F-2.6-008)', function (): void {
    $first = $this->get(route('landing.public.show', ['slug' => 'tracked']))->assertOk();

    LandingTestHelpers::replayCookies($first, $this);

    $this->get(route('landing.public.show', ['slug' => 'tracked']))->assertOk();
    $this->get(route('landing.public.show', ['slug' => 'tracked']))->assertOk();

    $summary = app(LandingPageStats::class)->summary($this->page);

    expect($summary['visits'])->toBe(3)
        ->and($summary['unique_visitors'])->toBe(1)
        ->and($summary['conversions'])->toBe(0)
        ->and($summary['conversion_rate'])->toBe(0.0);

    expect(LandingPageVisit::withoutTenancy()->count())->toBe(3);
});

it('never stores the raw visitor cookie', function (): void {
    $response = $this->get(route('landing.public.show', ['slug' => 'tracked']))->assertOk();

    $raw = null;

    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->getName() === LandingPageTracking::VISITOR_COOKIE) {
            $raw = LandingTestHelpers::plain((string) $cookie->getValue());
        }
    }

    // The browser holds a 32-hex handle…
    expect($raw)->toMatch('/^[0-9a-f]{32}$/');

    $stored = (string) LandingPageVisit::withoutTenancy()->value('visitor_key');

    expect($stored)->toHaveLength(64)
        ->and($stored)->not->toBe($raw);
});

it('attributes the source from utm_source and the referrer (F-2.6-008)', function (): void {
    $this->get(route('landing.public.show', ['slug' => 'tracked']).'?utm_source=Newsletter')->assertOk();

    $this->withHeader('referer', 'https://blog.example.com/post/1')
        ->get(route('landing.public.show', ['slug' => 'tracked']))
        ->assertOk();

    $sources = LandingPageVisit::withoutTenancy()->orderBy('id')->pluck('source')->all();

    // A URL referrer is host-normalised, never stored raw — the §2.7 rule.
    expect($sources)->toBe(['newsletter', 'blog.example.com']);

    $bySource = app(LandingPageStats::class)->bySource($this->page);

    expect(collect($bySource)->pluck('source')->sort()->values()->all())
        ->toBe(['blog.example.com', 'newsletter']);
});

it('honours the anonymise-IPs privacy setting at this capture point (§3.25.16)', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77']);

    // Off: the address is recorded as it arrived.
    $this->get(route('landing.public.show', ['slug' => 'tracked']))->assertOk();

    expect(LandingPageVisit::withoutTenancy()->latest('id')->first()->ip)->toBe('203.0.113.77');

    $settings = app(PrivacySettings::class);
    $settings->anonymize_ips = true;
    $settings->save();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77']);
    $this->get(route('landing.public.show', ['slug' => 'tracked']))->assertOk();

    expect(LandingPageVisit::withoutTenancy()->latest('id')->first()->ip)->toBe('203.0.113.0');
});

it('records a device bucket and the referrer with the visit', function (): void {
    $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148')
        ->withHeader('referer', 'https://news.example.org/x')
        ->get(route('landing.public.show', ['slug' => 'tracked']))
        ->assertOk();

    $visit = LandingPageVisit::withoutTenancy()->latest('id')->firstOrFail();

    expect($visit->device)->toBe('mobile')
        ->and($visit->referrer)->toBe('https://news.example.org/x')
        // IpAnonymizer::maybe() is the only path an IP takes into this table.
        ->and($visit->ip)->not->toBeNull();
});

it('renders the analytics page with the four headline numbers (F-2.6-008)', function (): void {
    $this->get(route('landing.public.show', ['slug' => 'tracked']))->assertOk();

    Livewire::test(LandingPageAnalytics::class, ['record' => $this->page->getKey()])
        ->assertOk()
        ->assertSee(__('landing.analytics.visits'))
        ->assertSee(__('landing.analytics.unique_visitors'))
        ->assertSee(__('landing.analytics.conversions'))
        ->assertSee(__('landing.analytics.conversion_rate'))
        ->assertSee(__('landing.analytics.privacy_note'));
});

it('keeps one tenant\'s visits out of another tenant\'s reports', function (): void {
    $other = Customer::factory()->create();

    $foreign = app(CurrentCustomer::class)->runAs($other, fn (): LandingPage => LandingPage::factory()
        ->published()
        ->create(['customer_id' => $other->id, 'slug' => 'theirs']));

    $this->get(route('landing.public.show', ['slug' => 'theirs']))->assertOk();

    // The visit belongs to the PAGE's tenant, never to the acting one.
    $visit = LandingPageVisit::withoutTenancy()->latest('id')->firstOrFail();

    expect((int) $visit->customer_id)->toBe((int) $other->id)
        // …and my panel cannot see it.
        ->and(LandingPageVisit::query()->count())->toBe(0)
        ->and(app(LandingPageStats::class)->summary($this->page)['visits'])->toBe(0);

    expect(fn () => Livewire::test(LandingPageAnalytics::class, ['record' => $foreign->getKey()]))
        ->toThrow(ModelNotFoundException::class);
});
