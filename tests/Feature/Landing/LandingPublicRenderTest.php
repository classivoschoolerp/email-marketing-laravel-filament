<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\LandingPage;
use App\Models\TrackingDomain;
use App\Services\Landing\LandingPageUrl;
use App\Support\Tenancy\CurrentCustomer;
use Tests\Support\AutomationFixtures as Fx;

/*
 * §1.8 public render.
 *
 * F-1.8-001 published pages render on the app host and on a custom domain
 * F-1.8-005 SEO meta, OG tags, favicon, canonical, custom code injection
 * F-2.6-010 tracking scripts: validated IDs emit, junk emits nothing (D-100)
 * F-2.6-011 thank-you page
 * F-2.6-012 anything not live is a 404
 * plus the cross-tenant host fence, the rate limit and a CJK/emoji round trip.
 */

beforeEach(function (): void {
    config()->set('app.url', 'https://app.example.test');

    $this->customer = Fx::actingAsCustomer();
    $this->page = LandingPage::factory()->published()->create([
        'customer_id' => $this->customer->id,
        'name' => 'Spring offer',
        'slug' => 'spring-offer',
        'content_html' => '<section class="lp-hero"><h1 class="lp-h1">Spring offer</h1></section>',
    ]);
});

it('renders a published page on the platform host (F-1.8-001)', function (): void {
    $this->get(route('landing.public.show', ['slug' => 'spring-offer']))
        ->assertOk()
        ->assertSee('Spring offer')
        ->assertSee('lp-hero', false)
        // The base stylesheet ships with the document — it must not depend on
        // the app's Vite bundle, which is wrong on a customer domain.
        ->assertSee('.lp-container', false)
        // …and it is responsive out of the box (F-2.6-013): the same phone
        // breakpoint the §2.5.4 builder uses, plus the viewport tag without
        // which none of it applies.
        ->assertSee('<meta name="viewport" content="width=device-width, initial-scale=1">', false)
        ->assertSee('@media only screen and (max-width:600px)', false)
        ->assertSee('.lp-cols{flex-direction:column}', false);
});

it('404s anything that is not live (F-2.6-012)', function (): void {
    foreach (['draft', 'unpublished'] as $status) {
        $this->page->forceFill(['status' => $status])->save();

        $this->get(route('landing.public.show', ['slug' => 'spring-offer']))->assertNotFound();
    }

    // Scheduled for the future = not live yet…
    $this->page->forceFill(['status' => 'scheduled', 'scheduled_at' => now()->addHour()])->save();
    $this->get(route('landing.public.show', ['slug' => 'spring-offer']))->assertNotFound();

    // …and live the moment it passes, with no cron involved.
    $this->page->forceFill(['scheduled_at' => now()->subSecond()])->save();
    $this->get(route('landing.public.show', ['slug' => 'spring-offer']))->assertOk();

    // An unknown slug is a plain 404.
    $this->get(route('landing.public.show', ['slug' => 'nope']))->assertNotFound();
});

it('serves a page on its assigned custom domain and refuses it on another tenant\'s (F-1.8-001)', function (): void {
    $mine = TrackingDomain::factory()->create([
        'customer_id' => $this->customer->id,
        'name' => 'go.mine.test',
        'verified' => true,
        'ssl_status' => 'active',
    ]);

    $other = Customer::factory()->create();

    app(CurrentCustomer::class)->runAs($other, function () use ($other): void {
        TrackingDomain::factory()->create([
            'customer_id' => $other->id,
            'name' => 'go.theirs.test',
            'verified' => true,
        ]);
    });

    $this->page->forceFill(['tracking_domain_id' => $mine->id])->save();

    // The canonical URL moves onto the custom domain, https because SSL is
    // reported active on that domain row (never invented here).
    expect(app(LandingPageUrl::class)->url($this->page->refresh()))
        ->toBe('https://go.mine.test/p/spring-offer');

    $this->get('https://go.mine.test/p/spring-offer')->assertOk()->assertSee('Spring offer');

    // Slugs are globally unique, so without the host fence this page would
    // render on someone else's domain. It must not.
    $this->get('https://go.theirs.test/p/spring-offer')->assertNotFound();

    // The platform host still serves it.
    $this->get('https://app.example.test/p/spring-offer')->assertOk();
});

it('emits SEO meta, OG tags, canonical, favicon and robots (F-1.8-005, F-2.6-007)', function (): void {
    $this->page->forceFill([
        'seo_title' => 'Spring offer — 30% off',
        'seo_description' => 'Only until Friday.',
        'og_image_url' => 'https://cdn.example.com/og.png',
        'favicon_url' => 'https://cdn.example.com/fav.ico',
        'canonical_url' => 'https://example.com/spring',
        'robots' => 'noindex,nofollow',
    ])->save();

    $response = $this->get(route('landing.public.show', ['slug' => 'spring-offer']))->assertOk();

    $response->assertSee('<title>Spring offer — 30% off</title>', false)
        ->assertSee('<meta name="robots" content="noindex,nofollow">', false)
        ->assertSee('<meta name="description" content="Only until Friday.">', false)
        ->assertSee('<link rel="canonical" href="https://example.com/spring">', false)
        ->assertSee('<link rel="icon" href="https://cdn.example.com/fav.ico">', false)
        ->assertSee('<meta property="og:title" content="Spring offer — 30% off">', false)
        ->assertSee('<meta property="og:image" content="https://cdn.example.com/og.png">', false)
        ->assertSee('<meta name="twitter:card" content="summary_large_image">', false);
});

it('injects custom CSS/JS and only pattern-valid tracking IDs (F-2.6-010, D-100)', function (): void {
    $this->page->forceFill([
        'custom_css' => '.lp-h1{color:#ff0000} </style><script>alert(1)</script>',
        'custom_js' => 'window.__lp=1; </script><img src=x onerror=alert(1)>',
        'ga_measurement_id' => 'G-ABC1234567',
        'gtm_container_id' => 'GTM-ABC1234',
        'fb_pixel_id' => '1234567890123456',
    ])->save();

    $response = $this->get(route('landing.public.show', ['slug' => 'spring-offer']))->assertOk();
    $html = $response->getContent();

    // The customer's own code is emitted, with the escape hatch removed.
    expect($html)->toContain('.lp-h1{color:#ff0000}')
        ->toContain('window.__lp=1;')
        ->not->toContain('</style><script>alert(1)')
        ->not->toContain('</script><img src=x');

    // The vendor loaders carry the validated ids.
    expect($html)->toContain('googletagmanager.com/gtag/js?id=G-ABC1234567')
        ->toContain("gtag('config','G-ABC1234567')")
        ->toContain("'GTM-ABC1234'")
        ->toContain("fbq('init','1234567890123456')");

    // Junk in an id column emits NOTHING rather than a mangled tag.
    $this->page->forceFill([
        'custom_css' => null,
        'custom_js' => null,
        'ga_measurement_id' => '"><script>alert(1)</script>',
        'gtm_container_id' => 'not a container',
        'fb_pixel_id' => 'abc',
    ])->save();

    $html = $this->get(route('landing.public.show', ['slug' => 'spring-offer']))->assertOk()->getContent();

    expect($html)->not->toContain('gtag/js')
        ->not->toContain('gtm.js')
        ->not->toContain('fbevents.js')
        ->not->toContain('alert(1)');
});

it('round-trips CJK and emoji through the page content and title (utf8mb4)', function (): void {
    $content = '<h1 class="lp-h1">春のキャンペーン 🌸 봄 세일</h1>';

    $this->page->forceFill([
        'content_html' => $content,
        'seo_title' => '東京 🗼 ランディング',
        'seo_description' => '限定オファー ✨',
    ])->save();

    expect(LandingPage::withoutTenancy()->findOrFail($this->page->id)->content_html)->toBe($content);

    $this->get(route('landing.public.show', ['slug' => 'spring-offer']))
        ->assertOk()
        ->assertSee('春のキャンペーン 🌸 봄 세일', false)
        ->assertSee('東京 🗼 ランディング', false)
        ->assertSee('限定オファー ✨', false);
});

it('renders the thank-you page and keeps it out of the index (F-2.6-011)', function (): void {
    $this->page->forceFill([
        'after_submit' => LandingPage::AFTER_THANK_YOU,
        'thank_you_html' => '<div class="lp-thanks"><h1>Check your inbox 📬</h1></div>',
    ])->save();

    $this->get(route('landing.public.thanks', ['slug' => 'spring-offer']))
        ->assertOk()
        ->assertSee('Check your inbox 📬', false)
        ->assertSee('<meta name="robots" content="noindex,nofollow">', false);

    // Without authored markup the success message is shown — never a blank page.
    $this->page->forceFill(['thank_you_html' => null, 'success_message' => 'You are in.'])->save();

    $this->get(route('landing.public.thanks', ['slug' => 'spring-offer']))
        ->assertOk()
        ->assertSee('You are in.');
});

it('rate limits the public render route', function (): void {
    // The route carries throttle:60,1 — the 61st request in a minute is 429.
    foreach (range(1, 60) as $i) {
        $this->get(route('landing.public.show', ['slug' => 'spring-offer']))->assertOk();
    }

    $this->get(route('landing.public.show', ['slug' => 'spring-offer']))->assertStatus(429);
});
