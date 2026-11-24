<?php

declare(strict_types=1);

use App\Enums\LandingPageStatus;
use App\Filament\Customer\Resources\LandingPageResource;
use App\Filament\Customer\Resources\LandingPageResource\Pages\CreateLandingPage;
use App\Filament\Customer\Resources\LandingPageResource\Pages\EditLandingPage;
use App\Filament\Customer\Resources\LandingPageResource\Pages\ListLandingPages;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\CustomerGroupLimit;
use App\Models\CustomerGroupPermission;
use App\Models\LandingPage;
use App\Models\LandingPageConversion;
use App\Models\LandingPageVisit;
use App\Models\TrackingDomain;
use App\Policies\LandingPagePolicy;
use App\Services\Landing\LandingPageUrl;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Support\AutomationFixtures as Fx;

/*
 * §2.6 landing pages — panel side.
 *
 * F-2.6-001 index with visits / conversions / conversion rate / domain
 * F-2.6-006 custom domain + subdomain assignment and honest SSL status
 * F-2.6-007 SEO settings persisted
 * F-2.6-010 tracking-script IDs validated, never injected raw (D-100)
 * F-2.6-012 publish / unpublish / schedule
 * F-2.6-014 duplicate
 * plus the §3.5 quota gate and the cross-tenant fence.
 */

beforeEach(function (): void {
    $this->customer = Fx::actingAsCustomer();
});

function landingVisit(LandingPage $page, string $visitor, bool $converted = false): void
{
    LandingPageVisit::withoutTenancy()->create([
        'customer_id' => $page->customer_id,
        'landing_page_id' => $page->id,
        'visitor_key' => str_pad($visitor, 64, '0'),
        'source' => 'direct',
        'converted' => $converted,
        'created_at' => now(),
    ]);

    if ($converted) {
        LandingPageConversion::withoutTenancy()->create([
            'customer_id' => $page->customer_id,
            'landing_page_id' => $page->id,
            'email' => $visitor.'@example.test',
            'source' => 'direct',
            'visitor_key' => str_pad($visitor, 64, '0'),
            'created_at' => now(),
        ]);
    }
}

it('renders the index with visits, conversions and conversion rate (F-2.6-001)', function (): void {
    $page = LandingPage::factory()->create([
        'customer_id' => $this->customer->id,
        'name' => 'Spring offer',
    ]);

    landingVisit($page, 'a');
    landingVisit($page, 'b');
    landingVisit($page, 'c');
    landingVisit($page, 'd', converted: true);

    $row = LandingPage::query()->withCount(['visits', 'conversions'])->findOrFail($page->id);

    expect((int) $row->visits_count)->toBe(4)
        ->and((int) $row->conversions_count)->toBe(1)
        ->and(ListLandingPages::conversionRate($row))->toBe('25.0%');

    Livewire::test(ListLandingPages::class)
        ->assertOk()
        ->assertSee('Spring offer')
        ->assertSee('25.0%');
});

it('shows the platform host as the domain until a verified custom domain is assigned (F-2.6-006)', function (): void {
    config()->set('app.url', 'https://app.example.test');

    $page = LandingPage::factory()->create(['customer_id' => $this->customer->id]);

    expect(ListLandingPages::domainLabel($page))->toBe('app.example.test');

    $domain = TrackingDomain::factory()->create([
        'customer_id' => $this->customer->id,
        'name' => 'go.customer.test',
        'verified' => true,
        'ssl_status' => 'active',
    ]);

    $page->forceFill(['tracking_domain_id' => $domain->id])->save();

    expect(ListLandingPages::domainLabel($page->refresh()))->toBe('go.customer.test');

    // An UNVERIFIED domain must not take over the URL — the page would 404
    // for every visitor until DNS caught up.
    $domain->forceFill(['verified' => false])->save();

    expect(ListLandingPages::domainLabel($page->refresh()))->toBe('app.example.test');
});

it('reports SSL exactly as the tracking domain recorded it, never inventing it (F-2.6-006)', function (): void {
    $urls = app(LandingPageUrl::class);

    expect($urls->sslStatus(null))->toBe('none');

    $domain = TrackingDomain::factory()->create([
        'customer_id' => $this->customer->id,
        'name' => 'ssl.example.test',
        'verified' => true,
        'ssl_status' => 'none',
    ]);

    // Verified DNS does NOT imply a certificate: nothing here issues one.
    expect($urls->sslStatus($domain->id))->toBe('none');

    foreach (['pending', 'active'] as $status) {
        $domain->forceFill(['ssl_status' => $status])->save();

        expect($urls->sslStatus($domain->id))->toBe($status);
    }

    // …and the panel shows that value.
    $page = LandingPage::factory()->create([
        'customer_id' => $this->customer->id,
        'tracking_domain_id' => $domain->id,
    ]);

    Livewire::test(EditLandingPage::class, ['record' => $page->getKey()])
        ->assertOk()
        ->assertSee(__('landing.ssl.active'));
});

it('creates a page and lands on the gallery (F-2.6-003)', function (): void {
    Livewire::test(CreateLandingPage::class)
        ->fillForm([
            'name' => 'Webinar signup',
            'status' => LandingPageStatus::Draft->value,
            'robots' => 'index,follow',
            'after_submit' => LandingPage::AFTER_MESSAGE,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(LandingPageResource::getUrl(
            'gallery',
            ['record' => LandingPage::query()->where('name', 'Webinar signup')->firstOrFail()],
        ));

    $page = LandingPage::query()->where('name', 'Webinar signup')->firstOrFail();

    expect($page->slug)->toBe('webinar-signup')
        ->and($page->uid)->not->toBeEmpty()
        ->and($page->status)->toBe(LandingPageStatus::Draft);
});

it('keeps slugs unique across the whole platform', function (): void {
    $other = Customer::factory()->create();

    app(CurrentCustomer::class)->runAs($other, function () use ($other): void {
        LandingPage::factory()->create([
            'customer_id' => $other->id,
            'name' => 'Pricing',
            'slug' => 'pricing',
        ]);
    });

    $mine = LandingPage::factory()->create([
        'customer_id' => $this->customer->id,
        'name' => 'Pricing',
        'slug' => 'pricing',
    ]);

    // The public path is the whole address on a shared host, so the second
    // one is adjusted rather than colliding.
    expect($mine->slug)->toBe('pricing-2');
});

it('saves SEO settings and validates tracking IDs (F-2.6-007, F-2.6-010)', function (): void {
    $page = LandingPage::factory()->create(['customer_id' => $this->customer->id]);

    Livewire::test(EditLandingPage::class, ['record' => $page->getKey()])
        ->fillForm([
            'seo_title' => 'Join the beta 🚀',
            'seo_description' => 'A short description',
            'og_image_url' => 'https://cdn.example.com/og.png',
            'canonical_url' => 'https://example.com/beta',
            'favicon_url' => 'https://cdn.example.com/fav.ico',
            'robots' => 'noindex,follow',
            'ga_measurement_id' => 'G-ABC1234567',
            'gtm_container_id' => 'GTM-ABC1234',
            'fb_pixel_id' => '1234567890123456',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $page->refresh();

    expect($page->seo_title)->toBe('Join the beta 🚀')
        ->and($page->robots)->toBe('noindex,follow')
        ->and($page->ga_measurement_id)->toBe('G-ABC1234567')
        ->and($page->fb_pixel_id)->toBe('1234567890123456');

    // A pasted snippet in an ID field is refused at save time — it must never
    // be stored looking valid (D-100).
    Livewire::test(EditLandingPage::class, ['record' => $page->getKey()])
        ->fillForm(['ga_measurement_id' => '<script>alert(1)</script>'])
        ->call('save')
        ->assertHasFormErrors(['ga_measurement_id']);

    expect($page->refresh()->ga_measurement_id)->toBe('G-ABC1234567');
});

it('publishes, unpublishes and schedules a page (F-2.6-012)', function (): void {
    $page = LandingPage::factory()->create(['customer_id' => $this->customer->id]);

    Livewire::test(ListLandingPages::class)
        ->callTableAction('publish', $page)
        ->assertHasNoErrors();

    $page->refresh();

    expect($page->status)->toBe(LandingPageStatus::Published)
        ->and($page->published_at)->not->toBeNull()
        ->and($page->isLive())->toBeTrue();

    Livewire::test(ListLandingPages::class)
        ->callTableAction('unpublish', $page)
        ->assertHasNoErrors();

    expect($page->refresh()->status)->toBe(LandingPageStatus::Unpublished)
        ->and($page->isLive())->toBeFalse();

    // Scheduling: a future time on a draft becomes the Scheduled state, and
    // the page is not live until that moment passes.
    $draft = LandingPage::factory()->create(['customer_id' => $this->customer->id]);

    Livewire::test(EditLandingPage::class, ['record' => $draft->getKey()])
        ->fillForm(['status' => LandingPageStatus::Draft->value, 'scheduled_at' => now()->addHour()])
        ->call('save')
        ->assertHasNoFormErrors();

    $draft->refresh();

    expect($draft->status)->toBe(LandingPageStatus::Scheduled)
        ->and($draft->isLive())->toBeFalse();

    $draft->forceFill(['scheduled_at' => now()->subMinute()])->save();

    expect($draft->refresh()->isLive())->toBeTrue();
});

it('duplicates a page through the queued job (F-2.6-014)', function (): void {
    $page = LandingPage::factory()->published()->create([
        'customer_id' => $this->customer->id,
        'name' => 'Launch',
        'content_html' => '<h1>Launch</h1>',
    ]);

    $page->variants()->create([
        'customer_id' => $this->customer->id,
        'name' => 'B',
        'weight' => 40,
        'is_winner' => true,
    ]);

    Livewire::test(ListLandingPages::class)
        ->callTableAction('duplicate', $page)
        ->assertHasNoErrors();

    $copy = LandingPage::query()->where('id', '!=', $page->id)->firstOrFail();

    expect($copy->name)->toBe('Launch '.__('landing.copy_suffix'))
        ->and($copy->slug)->not->toBe($page->slug)
        ->and($copy->content_html)->toBe('<h1>Launch</h1>')
        // A copy is never live on a URL nobody chose.
        ->and($copy->status)->toBe(LandingPageStatus::Draft)
        ->and($copy->published_at)->toBeNull()
        ->and($copy->variants()->count())->toBe(1)
        // The winner belonged to the original's data.
        ->and((bool) $copy->variants()->first()->is_winner)->toBeFalse();
});

it('refuses to show or edit another tenant\'s landing page', function (): void {
    $other = Customer::factory()->create();

    $foreign = app(CurrentCustomer::class)->runAs($other, fn (): LandingPage => LandingPage::factory()
        ->create(['customer_id' => $other->id, 'name' => 'Not mine']));

    // The tenancy scope hides it from the index...
    Livewire::test(ListLandingPages::class)->assertOk()->assertDontSee('Not mine');

    expect(LandingPage::query()->count())->toBe(0)
        ->and(LandingPage::withoutTenancy()->count())->toBe(1);

    // ...and the record pages refuse it outright.
    foreach ([EditLandingPage::class] as $pageClass) {
        expect(fn () => Livewire::test($pageClass, ['record' => $foreign->getKey()]))
            ->toThrow(ModelNotFoundException::class);
    }

    expect(Gate::forUser($this->customer)->allows('update', $foreign))->toBeFalse();
});

it('gates the module on the §3.5 permission and the max_landing_pages limit', function (): void {
    $group = CustomerGroup::factory()->create();
    $this->customer->forceFill(['customer_group_id' => $group->id])->save();

    CustomerGroupPermission::query()->create([
        'customer_group_id' => $group->id,
        'key' => LandingPagePolicy::CUSTOMER_PERMISSION,
        'allowed' => false,
    ]);

    expect(Gate::forUser($this->customer->refresh())->allows('viewAny', LandingPage::class))->toBeFalse();

    CustomerGroupPermission::query()->where('customer_group_id', $group->id)->update(['allowed' => true]);
    CustomerGroupLimit::query()->create([
        'customer_group_id' => $group->id,
        'key' => LandingPagePolicy::LIMIT_KEY,
        'value' => 1,
    ]);

    expect(Gate::forUser($this->customer->refresh())->allows('create', LandingPage::class))->toBeTrue();

    LandingPage::factory()->create(['customer_id' => $this->customer->id]);

    expect(Gate::forUser($this->customer->refresh())->allows('create', LandingPage::class))->toBeFalse();
});
