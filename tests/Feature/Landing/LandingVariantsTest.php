<?php

declare(strict_types=1);

use App\Filament\Customer\Resources\LandingPageResource\Pages\ManageLandingVariants;
use App\Models\LandingPage;
use App\Models\LandingPageConversion;
use App\Models\LandingPageVariant;
use App\Models\LandingPageVisit;
use App\Services\Landing\LandingPageStats;
use App\Services\Landing\LandingVariantAssigner;
use Livewire\Livewire;
use Tests\Support\AutomationFixtures as Fx;
use Tests\Support\LandingTestHelpers;

/*
 * §2.6 F-2.6-004 / §1.8 F-1.8-002 — A/B variants.
 *
 * traffic split by weight, STICKY assignment across requests, winner
 * selection taking 100% of traffic, and the statistical comparison that
 * refuses to name a winner on thin data.
 */

beforeEach(function (): void {
    config()->set('app.url', 'http://localhost');

    $this->customer = Fx::actingAsCustomer();
    $this->page = LandingPage::factory()->published()->create([
        'customer_id' => $this->customer->id,
        'slug' => 'ab-page',
        'ab_enabled' => true,
        'content_html' => '<h1>page content</h1>',
    ]);

    $this->a = LandingPageVariant::factory()->control()->create([
        'landing_page_id' => $this->page->id,
        'customer_id' => $this->customer->id,
        'name' => 'A',
        'weight' => 50,
        'content_html' => '<h1>ARM-A</h1>',
    ]);

    $this->b = LandingPageVariant::factory()->create([
        'landing_page_id' => $this->page->id,
        'customer_id' => $this->customer->id,
        'name' => 'B',
        'weight' => 50,
        'content_html' => '<h1>ARM-B</h1>',
    ]);
});

/** Replay a response's cookies on the next request, as a browser would. */
function replayCookies($response): void
{
    LandingTestHelpers::replayCookies($response, test());
}

it('serves an arm chosen by weight (F-2.6-004)', function (): void {
    // All the weight on B: every fresh visitor gets B.
    $this->a->forceFill(['weight' => 0])->save();
    $this->b->forceFill(['weight' => 100])->save();

    $this->get(route('landing.public.show', ['slug' => 'ab-page']))
        ->assertOk()
        ->assertSee('ARM-B', false)
        ->assertDontSee('ARM-A', false);

    expect((int) LandingPageVisit::withoutTenancy()->latest('id')->first()->landing_page_variant_id)
        ->toBe((int) $this->b->id);
});

it('keeps the same visitor on the same arm across requests (F-1.8-002)', function (): void {
    $first = $this->get(route('landing.public.show', ['slug' => 'ab-page']))->assertOk();

    $assigned = (int) LandingPageVisit::withoutTenancy()->latest('id')->first()->landing_page_variant_id;

    expect($assigned)->toBeIn([(int) $this->a->id, (int) $this->b->id]);

    // Carry the assignment cookie forward, exactly as a browser does.
    replayCookies($first);

    foreach (range(1, 5) as $i) {
        $this->get(route('landing.public.show', ['slug' => 'ab-page']))->assertOk();

        expect((int) LandingPageVisit::withoutTenancy()->latest('id')->first()->landing_page_variant_id)
            ->toBe($assigned);
    }

    expect(LandingPageVisit::withoutTenancy()->count())->toBe(6)
        // One visitor, six visits.
        ->and(LandingPageVisit::withoutTenancy()->distinct()->count('visitor_key'))->toBe(1);
});

it('sends all traffic to a declared winner (F-2.6-004)', function (): void {
    $first = $this->get(route('landing.public.show', ['slug' => 'ab-page']))->assertOk();

    $assigned = (int) LandingPageVisit::withoutTenancy()->latest('id')->first()->landing_page_variant_id;

    // Declare the OTHER arm the winner: the concluded test must override the
    // visitor's sticky assignment, or losers keep being served forever.
    $winner = $assigned === (int) $this->a->id ? $this->b : $this->a;

    Livewire::test(ManageLandingVariants::class, ['record' => $this->page->getKey()])
        ->call('declareWinner', $winner->id);

    replayCookies($first);

    $this->get(route('landing.public.show', ['slug' => 'ab-page']))
        ->assertOk()
        ->assertSee($winner->name === 'A' ? 'ARM-A' : 'ARM-B', false);

    expect((int) LandingPageVisit::withoutTenancy()->latest('id')->first()->landing_page_variant_id)
        ->toBe((int) $winner->id);
});

it('falls back to the page content when the test is off or the arm is disabled', function (): void {
    $this->page->forceFill(['ab_enabled' => false])->save();

    $this->get(route('landing.public.show', ['slug' => 'ab-page']))
        ->assertOk()
        ->assertSee('page content', false)
        ->assertDontSee('ARM-', false);

    // Back on, but with only one enabled arm: a cookie pointing at the
    // disabled one must not keep serving switched-off content.
    $this->page->forceFill(['ab_enabled' => true])->save();
    $this->b->forceFill(['enabled' => false])->save();

    $response = $this->get(route('landing.public.show', ['slug' => 'ab-page']))->assertOk();
    replayCookies($response);

    $this->get(route('landing.public.show', ['slug' => 'ab-page']))
        ->assertOk()
        ->assertSee('ARM-A', false);
});

it('compares conversion rates with a real significance test (F-2.6-004)', function (): void {
    // 400 visits per arm; A converts at 10%, B at 20%.
    seedArm($this->page, $this->a, 400, 40);
    seedArm($this->page, $this->b, 400, 80);

    $stats = app(LandingPageStats::class);
    $rows = $stats->variantComparison($this->page);

    expect($rows)->toHaveCount(2);

    $control = $rows[0];
    $challenger = $rows[1];

    expect($control['is_control'])->toBeTrue()
        ->and($control['conversion_rate'])->toBe(10.0)
        ->and($challenger['conversion_rate'])->toBe(20.0)
        ->and($challenger['uplift'])->toBe(100.0)
        ->and($challenger['confidence'])->toBeGreaterThan(0.99)
        ->and($challenger['significant'])->toBeTrue();

    expect($stats->suggestedWinner($this->page)?->id)->toBe($this->b->id);

    Livewire::test(ManageLandingVariants::class, ['record' => $this->page->getKey()])
        ->assertOk()
        ->assertSee(__('landing.variants.suggested_winner', ['name' => 'B']));
});

it('refuses to name a winner on a sample too small to mean anything', function (): void {
    seedArm($this->page, $this->a, 10, 1);
    seedArm($this->page, $this->b, 10, 4);

    $stats = app(LandingPageStats::class);
    $rows = $stats->variantComparison($this->page);

    expect($rows[1]['confidence'])->toBeNull()
        ->and($rows[1]['significant'])->toBeFalse()
        ->and($stats->suggestedWinner($this->page))->toBeNull();

    Livewire::test(ManageLandingVariants::class, ['record' => $this->page->getKey()])
        ->assertOk()
        ->assertSee(__('landing.variants.no_suggested_winner'));

    // Identical rates on a big sample are also NOT a winner.
    LandingPageVisit::withoutTenancy()->where('landing_page_id', $this->page->id)->delete();
    LandingPageConversion::withoutTenancy()->where('landing_page_id', $this->page->id)->delete();

    seedArm($this->page, $this->a, 500, 50);
    seedArm($this->page, $this->b, 500, 50);

    expect($stats->suggestedWinner($this->page))->toBeNull();
});

it('adds a variant through the panel and turns the test on', function (): void {
    $page = LandingPage::factory()->create([
        'customer_id' => $this->customer->id,
        'content_html' => '<h1>start</h1>',
    ]);

    expect($page->refresh()->ab_enabled)->toBeFalse();

    Livewire::test(ManageLandingVariants::class, ['record' => $page->getKey()])
        ->callAction('add_variant', ['name' => 'Headline B', 'weight' => 30, 'is_control' => false]);

    $variant = LandingPageVariant::query()->where('landing_page_id', $page->id)->firstOrFail();

    expect($variant->name)->toBe('Headline B')
        ->and($variant->weight)->toBe(30)
        // A new arm starts from the page, so the test begins with one change.
        ->and($variant->content_html)->toBe('<h1>start</h1>')
        ->and($page->refresh()->ab_enabled)->toBeTrue();
});

it('exposes the sticky cookie under a per-page name', function (): void {
    $assigner = app(LandingVariantAssigner::class);

    expect($assigner->cookieName($this->page))
        ->toBe(LandingVariantAssigner::COOKIE_PREFIX.$this->page->uid)
        // Two pages never share an assignment.
        ->not->toBe($assigner->cookieName(LandingPage::factory()->create([
            'customer_id' => $this->customer->id,
        ])));
});

/** Bulk-seed visits and conversions for one arm. */
function seedArm(LandingPage $page, LandingPageVariant $variant, int $visits, int $conversions): void
{
    $rows = [];

    for ($i = 0; $i < $visits; $i++) {
        $rows[] = [
            'customer_id' => $page->customer_id,
            'landing_page_id' => $page->id,
            'landing_page_variant_id' => $variant->id,
            'visitor_key' => hash('sha256', $variant->id.'-'.$i),
            'source' => 'direct',
            'converted' => $i < $conversions,
            'created_at' => now(),
        ];
    }

    foreach (array_chunk($rows, 200) as $chunk) {
        LandingPageVisit::withoutTenancy()->insert($chunk);
    }

    $conversionRows = [];

    for ($i = 0; $i < $conversions; $i++) {
        $conversionRows[] = [
            'customer_id' => $page->customer_id,
            'landing_page_id' => $page->id,
            'landing_page_variant_id' => $variant->id,
            'email' => 'lead'.$variant->id.'-'.$i.'@example.test',
            'source' => 'direct',
            'visitor_key' => hash('sha256', $variant->id.'-'.$i),
            'created_at' => now(),
        ];
    }

    foreach (array_chunk($conversionRows, 200) as $chunk) {
        LandingPageConversion::withoutTenancy()->insert($chunk);
    }
}
