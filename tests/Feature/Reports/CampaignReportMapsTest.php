<?php

declare(strict_types=1);

use App\Filament\Customer\Resources\CampaignResource;
use App\Models\CampaignTrackOpen;
use App\Models\Customer;
use App\Services\Reports\CampaignReportService;
use App\Services\Reports\ClickMapRenderer;
use App\Services\Reports\GeoMapRenderer;
use App\Support\Geo\WorldTileGrid;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Tests\Support\CampaignReportFixture;

/*
 * WP-27B — the two remaining §2.3.8 visuals:
 *   F-2.3.8-005 click map (heat overlay of link positions in the body)
 *   F-2.3.8-013 geo world map (tile cartogram over the country table)
 *
 * The CampaignReportFixture pins exact event counts:
 *   url A: 2 clicks (1 unique) · url B: 1 click (1 unique) · 3 clicks total.
 */

beforeEach(function (): void {
    $this->fixture = CampaignReportFixture::build();
    $this->customer = $this->fixture->customer;

    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);
});

/* ====================================================================== *
 *  F-2.3.8-005 — click map
 * ====================================================================== */

it('badges every anchor with its exact click count, rank and share', function (): void {
    $this->fixture->campaign->forceFill([
        'content_html' => '<html><body>'
            .'<p><a href="https://example.com/a">Hero button</a></p>'
            .'<p><a href="https://example.com/b">Secondary</a></p>'
            .'<p><a href="https://example.com/never-clicked">Footer link</a></p>'
            .'<p><a href="#skip">In-page anchor</a></p>'
            .'</body></html>',
    ])->save();

    $map = app(ClickMapRenderer::class)->render($this->fixture->campaign);

    // 3 real anchors mapped; the in-page "#" anchor is not a destination.
    expect($map['anchors'])->toBe(3)
        ->and($map['total_clicks'])->toBe(3);

    // EXACT badges: url A = rank 1, 2 clicks, 66.7%; url B = rank 2, 1 click,
    // 33.3%; the untracked footer link = 0.
    expect($map['html'])
        ->toContain('#1: 2 (66.7%)')
        ->toContain('#2: 1 (33.3%)')
        ->toContain('data-emp-click-badge="0"')
        // Every badged anchor carries its machine-readable count too.
        ->toContain('data-emp-clicks="2"')
        ->toContain('data-emp-clicks="1"')
        ->toContain('data-emp-clicks="0"')
        // Heat: the winner gets the strongest shade, the unclicked link the
        // no-data shade.
        ->toContain('#991b1b')
        ->toContain('#9ca3af')
        // Navigation is neutralised; the original URL is kept for reference.
        ->toContain('data-emp-url="https://example.com/a"')
        ->not->toContain('href="https://example.com/a"');

    // Ranks: only clicked links are ranked.
    $ranks = collect($map['links'])->pluck('rank', 'url')->all();

    expect($ranks['https://example.com/a'])->toBe(1)
        ->and($ranks['https://example.com/b'])->toBe(2);
});

it('matches anchors regardless of trailing slash and host case', function (): void {
    $this->fixture->campaign->forceFill([
        'content_html' => '<a href="https://EXAMPLE.com/a/">Hero</a>',
    ])->save();

    $map = app(ClickMapRenderer::class)->render($this->fixture->campaign);

    expect($map['html'])->toContain('#1: 2 (66.7%)')
        ->and($map['anchors'])->toBe(1);
});

it('returns an empty click map for a campaign with no content', function (): void {
    $this->fixture->campaign->forceFill(['content_html' => null])->save();

    $map = app(ClickMapRenderer::class)->render($this->fixture->campaign);

    expect($map['html'])->toBe('')
        ->and($map['anchors'])->toBe(0)
        // The link data still exists — only the visual has nothing to draw on.
        ->and($map['links'])->toHaveCount(2);
});

it('renders the click map on the clicks tab of the report page', function (): void {
    $this->fixture->campaign->forceFill([
        'content_html' => '<a href="https://example.com/a">Hero button</a>',
    ])->save();

    $this->get(CampaignResource::getUrl('report', ['record' => $this->fixture->campaign]).'?activeTab=clicks')
        ->assertOk()
        ->assertSee(__('reports.click_map.heading'))
        ->assertSee('#1: 2 (66.7%)', false);
});

/* ====================================================================== *
 *  F-2.3.8-013 — geo world map
 * ====================================================================== */

it('derives a stable, collision-free world tile grid from country centroids', function (): void {
    $grid = WorldTileGrid::grid();
    $centroids = WorldTileGrid::centroids();

    // Every country in the dataset gets exactly one tile…
    expect($grid)->toHaveCount(count($centroids));

    // …and no two countries share a cell.
    $cells = array_map(fn (array $cell): string => $cell[0].':'.$cell[1], $grid);
    expect(array_unique($cells))->toHaveCount(count($cells));

    // Deterministic across calls.
    expect(WorldTileGrid::grid())->toBe($grid);

    // Anchors: the layout really is geographic. Columns increase eastward,
    // rows increase southward.
    [$usCol, $usRow] = $grid['US'];
    [$deCol, $deRow] = $grid['DE'];
    [$jpCol, $jpRow] = $grid['JP'];
    [$zaCol, $zaRow] = $grid['ZA'];
    [$auCol, $auRow] = $grid['AU'];

    expect($usCol)->toBeLessThan($deCol)      // America is west of Europe
        ->and($deCol)->toBeLessThan($jpCol)   // Europe is west of Japan
        ->and($deRow)->toBeLessThan($zaRow)   // Germany is north of South Africa
        ->and($auRow)->toBeGreaterThan($jpRow); // Australia is south of Japan
});

it('resolves stored country values to iso codes and never guesses', function (): void {
    expect(WorldTileGrid::resolveCode('Germany'))->toBe('DE')
        ->and(WorldTileGrid::resolveCode('germany'))->toBe('DE')
        ->and(WorldTileGrid::resolveCode('DE'))->toBe('DE')
        ->and(WorldTileGrid::resolveCode('de'))->toBe('DE')
        ->and(WorldTileGrid::resolveCode('United Kingdom'))->toBe('GB')
        // Unrecognised values stay unknown rather than being attributed.
        ->and(WorldTileGrid::resolveCode('Atlantis'))->toBeNull()
        ->and(WorldTileGrid::resolveCode(''))->toBeNull()
        ->and(WorldTileGrid::resolveCode(null))->toBeNull();
});

it('shades the world map by each countrys share of opens', function (): void {
    // Overwrite the fixture's 4 open rows with a known geo distribution.
    CampaignTrackOpen::query()->where('campaign_uid', $this->fixture->campaign->uid)->delete();

    $open = function (?string $country, int $times): void {
        for ($i = 0; $i < $times; $i++) {
            CampaignTrackOpen::query()->create([
                'campaign_uid' => $this->fixture->campaign->uid,
                'subscriber_uid' => 'sub-'.$country.'-'.$i,
                'country' => $country,
            ]);
        }
    };

    $open('Germany', 6);
    $open('France', 3);
    $open('DE', 2);        // stored as a code — same country
    $open('Atlantis', 4);  // unrecognised
    $open(null, 5);        // no GeoIP data

    $map = app(GeoMapRenderer::class)->render($this->fixture->campaign);

    expect($map['total'])->toBe(20)
        ->and($map['mapped'])->toBe(11)   // 6 + 2 Germany + 3 France
        ->and($map['unknown'])->toBe(9)   // 4 Atlantis + 5 null
        ->and($map['max'])->toBe(8);      // Germany, name + code merged

    expect($map['rows'][0])->toBe([
        'code' => 'DE', 'name' => 'Germany', 'count' => 8, 'percent' => 40.0,
    ]);
    expect($map['rows'][1])->toBe([
        'code' => 'FR', 'name' => 'France', 'count' => 3, 'percent' => 15.0,
    ]);

    // The SVG is inline, has one tile per country and reports exact figures
    // in each tile's tooltip.
    expect($map['svg'])->toStartWith('<svg xmlns="http://www.w3.org/2000/svg"')
        ->toContain('data-country="DE" data-count="8"')
        ->toContain('data-country="FR" data-count="3"')
        ->toContain('data-country="US" data-count="0"')
        ->toContain('<title>Germany — 8 opens (40%)</title>')
        ->toContain('<title>United States — no opens</title>')
        // Germany is the maximum → the darkest step; the US has no data.
        ->toContain('fill="#1d4ed8"')
        ->toContain('fill="#e5e7eb"')
        ->and(substr_count($map['svg'], '<rect'))->toBe(count(WorldTileGrid::grid()));
});

it('renders the world map above the country table on the geo tab', function (): void {
    CampaignTrackOpen::query()->where('campaign_uid', $this->fixture->campaign->uid)->delete();

    CampaignTrackOpen::query()->create([
        'campaign_uid' => $this->fixture->campaign->uid,
        'subscriber_uid' => 'sub-geo-1',
        'country' => 'Germany',
        'region' => 'Bavaria',
        'city' => 'Munich',
    ]);

    $this->get(CampaignResource::getUrl('report', ['record' => $this->fixture->campaign]).'?activeTab=geo')
        ->assertOk()
        ->assertSee(__('reports.geo.map_heading'))
        ->assertSee('data-country="DE"', false)
        // The table the map sits above is untouched.
        ->assertSee('Bavaria')
        ->assertSee('Munich')
        // …and the honest caption is on the page, not a claim of a real map.
        ->assertSee(__('reports.geo.map_hint'));
});

it('counts every open in the country breakdown including the null bucket', function (): void {
    CampaignTrackOpen::query()->where('campaign_uid', $this->fixture->campaign->uid)->delete();

    foreach (['Germany', 'Germany', null] as $index => $country) {
        CampaignTrackOpen::query()->create([
            'campaign_uid' => $this->fixture->campaign->uid,
            'subscriber_uid' => 'sub-cb-'.$index,
            'country' => $country,
        ]);
    }

    $rows = app(CampaignReportService::class)->countryBreakdown($this->fixture->campaign);

    expect($rows)->toBe([
        ['country' => 'Germany', 'count' => 2, 'percent' => 66.7],
        ['country' => null, 'count' => 1, 'percent' => 33.3],
    ]);
});

it('denies the report page and its maps across tenants', function (): void {
    $other = Customer::factory()->create();

    $this->actingAs($other, 'customer');
    app(CurrentCustomer::class)->set($other);

    $this->get(CampaignResource::getUrl('report', ['record' => $this->fixture->campaign]).'?activeTab=geo')
        ->assertNotFound();
});
