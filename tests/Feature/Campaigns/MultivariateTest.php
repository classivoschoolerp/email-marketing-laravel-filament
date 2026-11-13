<?php

declare(strict_types=1);

use App\Enums\AbTestVariable;
use App\Enums\AbWinnerCriteria;
use App\Enums\CampaignStatus;
use App\Filament\Customer\Resources\CampaignResource;
use App\Filament\Customer\Resources\CampaignResource\Pages\CampaignReport;
use App\Models\Campaign;
use App\Models\CampaignDeliveryLog;
use App\Models\CampaignTrackOpen;
use App\Models\CampaignVariant;
use App\Models\CampaignVariantRecipient;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Services\Campaigns\AbTest\AbTestManager;
use App\Services\Campaigns\AbTest\AbTestSplitter;
use App\Services\Campaigns\AbTest\MultivariateAnalysis;
use App\Services\Campaigns\AbTest\MultivariateDesign;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * §2.3.6 F-2.3.6-004 — multivariate combinations.
 *
 * The worked example used throughout: subject × image, 2 levels each ⇒ 4
 * variants, 200 delivered per variant.
 *
 *                       image 1      image 2      subject marginal
 *     subject A       A: 60/200    B: 56/200        116 / 400 = 29.0 %
 *     subject B       C: 30/200    D: 34/200         64 / 400 = 16.0 %
 *     image marginal   90 / 400     90 / 400
 *
 * Subject A wins REGARDLESS of the image (60 > 30 and 56 > 34), and the two
 * image levels are exactly tied at 90/400 — which is the whole point of a
 * factorial design and impossible to read off the per-variant table alone.
 *
 * The subject comparison, by hand (two-proportion z-test, SignificanceTest):
 *   p̂ = (116 + 64) / 800 = 0.225
 *   SE = √(0.225 · 0.775 · (1/400 + 1/400)) = √(0.174375 · 0.005)
 *      = √0.000871875 = 0.0295275…
 *   z  = (0.29 − 0.16) / 0.0295275… = 4.4026710693…
 *   p  = 0.000010701362…  — far inside 95 %, and inside it even after Holm.
 */

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->for($this->customer)->create();

    $this->campaign = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
        'status' => CampaignStatus::Sending,
        'subject' => 'Base subject',
        'content_html' => '<p>Base <a href="{UNSUBSCRIBE_URL}">bye</a></p>',
        'ab_enabled' => true,
        'ab_test_percent' => 20,
        'ab_winner_criteria' => AbWinnerCriteria::UniqueOpens->value,
        'ab_confidence_level' => 95,
        'send_started_at' => now(),
    ]);
});

/* ====================================================================== *
 *  The design
 * ====================================================================== */

it('generates the full cross-product in odometer order', function (): void {
    $design = app(MultivariateDesign::class);

    $factors = [
        AbTestVariable::Subject->value => ['Subject A', 'Subject B'],
        AbTestVariable::Content->value => ['<p>Image 1</p>', '<p>Image 2</p>'],
    ];

    expect($design->size($factors))->toBe(4)
        ->and($design->designNotation($factors))->toBe('2 × 2')
        ->and($design->problems($factors))->toBe([]);

    // The LAST factor varies fastest.
    expect($design->combinations($factors))->toBe([
        ['subject' => 'Subject A', 'content' => '<p>Image 1</p>'],
        ['subject' => 'Subject A', 'content' => '<p>Image 2</p>'],
        ['subject' => 'Subject B', 'content' => '<p>Image 1</p>'],
        ['subject' => 'Subject B', 'content' => '<p>Image 2</p>'],
    ]);

    $variants = $design->generate($this->campaign, $factors);

    expect($variants)->toHaveCount(4)
        ->and(array_map(fn (CampaignVariant $v): string => $v->label, $variants))->toBe(['A', 'B', 'C', 'D'])
        // Every variant records its level map — that is what makes the
        // per-factor report possible.
        ->and($variants[0]->factors)->toBe(['subject' => 'Subject A', 'content' => '<p>Image 1</p>'])
        ->and($variants[3]->factors)->toBe(['subject' => 'Subject B', 'content' => '<p>Image 2</p>'])
        // …and the level is written to the column that actually overrides.
        ->and($variants[2]->subject)->toBe('Subject B')
        ->and($variants[2]->content_html)->toBe('<p>Image 1</p>');

    // The campaign now declares exactly the factors under test.
    expect($this->campaign->fresh()->ab_variables)->toBe(['subject', 'content'])
        ->and(app(AbTestManager::class)->problems($this->campaign->fresh()))->toBe([]);
});

// The refusal is the requirement: 3 × 3 × 2 = 18 must NOT become 8.
it('refuses an oversized design instead of truncating it', function (): void {
    $design = app(MultivariateDesign::class);

    $tooBig = [
        AbTestVariable::Subject->value => ['s1', 's2', 's3'],
        AbTestVariable::Content->value => ['c1', 'c2', 'c3'],
        AbTestVariable::FromName->value => ['f1', 'f2'],
    ];

    expect($design->size($tooBig))->toBe(18)
        ->and($design->designNotation($tooBig))->toBe('3 × 3 × 2');

    $problems = $design->problems($tooBig);

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toBe(__('campaigns.mvt.errors.too_many_combinations', [
            'design' => '3 × 3 × 2',
            'size' => 18,
            'max' => AbTestManager::MAX_VARIANTS,
        ]))
        ->and($problems[0])->toContain('18')
        ->and($problems[0])->toContain('3 × 3 × 2');

    expect(fn () => $design->generate($this->campaign, $tooBig))
        ->toThrow(InvalidArgumentException::class);

    // Nothing was written — not eight of the eighteen, not any of them.
    expect($this->campaign->variants()->count())->toBe(0);

    // 2 × 2 × 2 = 8 is exactly the cap and IS allowed.
    $exactly = [
        AbTestVariable::Subject->value => ['s1', 's2'],
        AbTestVariable::Content->value => ['c1', 'c2'],
        AbTestVariable::FromName->value => ['f1', 'f2'],
    ];

    expect($design->problems($exactly))->toBe([])
        ->and($design->generate($this->campaign, $exactly))->toHaveCount(AbTestManager::MAX_VARIANTS);
});

it('rejects a factor with fewer than two options', function (): void {
    $design = app(MultivariateDesign::class);

    expect($design->problems([AbTestVariable::Subject->value => ['only one']]))
        ->toContain(__('campaigns.mvt.errors.too_few_levels', [
            'factor' => AbTestVariable::Subject->label(),
            'min' => MultivariateDesign::MIN_LEVELS,
        ]));

    // Duplicates collapse, so "A, A" is still one option.
    expect($design->size([AbTestVariable::Subject->value => ['A', 'A']]))->toBe(1);

    // No factors at all is the existing "choose something to test" error.
    expect($design->problems([]))->toBe([__('campaigns.ab.errors.no_variable')])
        ->and($design->problems(['not_a_variable' => ['x', 'y']]))
        ->toBe([__('campaigns.ab.errors.no_variable')]);
});

it('keeps the deterministic seat allocation across the generated arms', function (): void {
    $variants = app(MultivariateDesign::class)->generate($this->campaign, [
        AbTestVariable::Subject->value => ['Subject A', 'Subject B'],
        AbTestVariable::Content->value => ['<p>Image 1</p>', '<p>Image 2</p>'],
    ]);

    for ($i = 1; $i <= 100; $i++) {
        Subscriber::factory()->for($this->list, 'list')->create([
            'email' => 'mvt'.str_pad((string) $i, 3, '0', STR_PAD_LEFT).'@fixture.test',
        ]);
    }

    $splitter = app(AbTestSplitter::class);
    $rows = Subscriber::query()->get(['id', 'uid']);

    // 20 % of 100 = 20 seats over 4 equal arms = 5 each, summing to 20.
    $seats = $splitter->seats($variants, 20);

    expect(array_values($seats))->toBe([5, 5, 5, 5])
        ->and(array_sum($seats))->toBe(20);

    // …and it is still reproducible, which is what a resume wave depends on.
    $first = $splitter->split($this->campaign->fresh(), $rows, $variants);
    $second = $splitter->split($this->campaign->fresh(), $rows->shuffle(), $variants);

    expect($first['assignments'])->toBe($second['assignments'])
        ->and(count($first['assignments']))->toBe(20);
});

/* ====================================================================== *
 *  Per-factor effects
 * ====================================================================== */

it('reports per-factor effects marginalised over the other factors', function (): void {
    $variants = mvtSeedDesign($this->campaign);

    $effects = app(MultivariateAnalysis::class)->effects($this->campaign->fresh());

    expect($effects['available'])->toBeTrue()
        ->and($effects['factors'])->toHaveCount(2);

    $subject = collect($effects['factors'])->firstWhere('variable', 'subject');
    $content = collect($effects['factors'])->firstWhere('variable', 'content');

    // Subject marginals: A = 116/400 = 29 %, B = 64/400 = 16 %.
    expect($subject['levels'][0]['level'])->toBe('Subject A')
        ->and($subject['levels'][0]['successes'])->toBe(116)
        ->and($subject['levels'][0]['trials'])->toBe(400)
        ->and($subject['levels'][0]['rate'])->toBe(29.0)
        ->and($subject['levels'][0]['is_best'])->toBeTrue()
        // Each marginal pools TWO variants — that is the marginalisation.
        ->and($subject['levels'][0]['variants'])->toBe(['A', 'B'])
        ->and($subject['levels'][1]['level'])->toBe('Subject B')
        ->and($subject['levels'][1]['successes'])->toBe(64)
        ->and($subject['levels'][1]['rate'])->toBe(16.0)
        ->and($subject['levels'][1]['variants'])->toBe(['C', 'D']);

    // Hand-computed p for 116/400 vs 64/400 (see the file header).
    expect($subject['levels'][1]['computable'])->toBeTrue()
        ->and(abs((float) $subject['levels'][1]['p_value'] - 0.000010701362))->toBeLessThan(1e-9)
        ->and($subject['levels'][1]['significant'])->toBeTrue();

    // The image factor is exactly tied at 90/400 each, so neither level wins.
    expect($content['levels'][0]['successes'])->toBe(90)
        ->and($content['levels'][0]['trials'])->toBe(400)
        ->and($content['levels'][1]['successes'])->toBe(90)
        ->and($content['levels'][1]['rate'])->toBe($content['levels'][0]['rate'])
        ->and($content['levels'][1]['significant'])->toBeFalse()
        ->and($content['levels'][1]['significant_uncorrected'])->toBeFalse();

    expect($variants)->toHaveCount(4);
});

it('applies the Holm-Bonferroni correction across the whole family', function (): void {
    mvtSeedDesign($this->campaign);

    $effects = app(MultivariateAnalysis::class)->effects($this->campaign->fresh());

    // One comparison per non-best level per factor: (2−1) + (2−1) = 2.
    expect($effects['comparisons'])->toBe(2)
        ->and($effects['alpha'])->toBe(0.05);

    $subject = collect($effects['factors'])->firstWhere('variable', 'subject');
    $content = collect($effects['factors'])->firstWhere('variable', 'content');

    // Holm, step-down over m = 2 sorted p-values: the smallest is tested at
    // α/2 = 0.025 and the next at α/1 = 0.05. p_subject = 1.07e-5 ≤ 0.025 ⇒
    // rejected at threshold 0.025; the image comparison (p = 1.0) is not.
    expect($subject['levels'][1]['holm_threshold'])->toBe(0.025)
        ->and($subject['levels'][1]['significant'])->toBeTrue()
        ->and($subject['levels'][1]['significant_uncorrected'])->toBeTrue()
        ->and($content['levels'][1]['significant'])->toBeFalse();

    // The correction is not cosmetic: a borderline result loses its verdict.
    // 210/1000 vs 175/1000 gives p = 0.047141 — significant on its own at
    // 95 %, and NOT significant once it is one of two comparisons in the same
    // family (Holm tests the smallest p at α/2 = 0.025).
    $borderline = mvtBorderlineCampaign($this->customer, $this->list);

    $effects = app(MultivariateAnalysis::class)->effects($borderline);
    $factor = collect($effects['factors'])->firstWhere('variable', 'subject');

    expect($effects['comparisons'])->toBe(2)
        ->and(abs((float) $factor['levels'][1]['p_value'] - 0.047141331))->toBeLessThan(1e-6)
        ->and($factor['levels'][1]['significant_uncorrected'])->toBeTrue()
        ->and($factor['levels'][1]['holm_threshold'])->toBe(0.025)
        ->and($factor['levels'][1]['significant'])->toBeFalse();
});

it('says nothing at all for a campaign that is not a factorial design', function (): void {
    // The ordinary side-by-side composer writes no `factors` map.
    $this->campaign->variants()->create(['position' => 0, 'label' => 'A', 'weight' => 1, 'subject' => 'A']);
    $this->campaign->variants()->create(['position' => 1, 'label' => 'B', 'weight' => 1, 'subject' => 'B']);

    $effects = app(MultivariateAnalysis::class)->effects($this->campaign->fresh());

    expect(app(MultivariateDesign::class)->isFactorial($this->campaign->fresh()))->toBeFalse()
        ->and($effects['available'])->toBeFalse()
        ->and($effects['factors'])->toBe([]);
});

/* ====================================================================== *
 *  UI
 * ====================================================================== */

it('renders the per-factor block on the report page', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('customer'));

    mvtSeedDesign($this->campaign);

    Livewire::test(CampaignReport::class, ['record' => $this->campaign->getRouteKey()])
        ->assertOk()
        ->assertSee(__('campaigns.mvt.report.title'))
        ->assertSee(__('campaigns.mvt.report.best'))
        // The multiple-comparison caveat is stated to the customer, not buried
        // in a docblock.
        ->assertSee(__('campaigns.mvt.report.correction', [
            'comparisons' => 2,
            'alpha' => 5.0,
            'inflated' => 9.8,
        ]));
});

it('parses the composer modal into a factor map', function (): void {
    $fields = CampaignResource::multivariateFactorFields(['subject', 'content', 'nonsense']);

    expect($fields)->toHaveCount(2);

    $factors = CampaignResource::multivariateFactorsFromForm([
        'factor_subject' => "Subject A\nSubject B\n\n  \n",
        'factor_content' => "<p>Image 1</p>\r\n<p>Image 2</p>",
        'factor_nonsense' => "x\ny",
        'something_else' => 'ignored',
    ]);

    expect($factors)->toBe([
        'subject' => ['Subject A', 'Subject B'],
        'content' => ['<p>Image 1</p>', '<p>Image 2</p>'],
    ])
        ->and(app(MultivariateDesign::class)->size($factors))->toBe(4);
});

/* ---------------------------------------------------------------------- */

/**
 * The worked example from the file header: 2 × 2, 200 delivered per variant,
 * unique opens 60 / 56 / 30 / 34.
 *
 * @return list<CampaignVariant>
 */
function mvtSeedDesign(Campaign $campaign): array
{
    $variants = app(MultivariateDesign::class)->generate($campaign, [
        AbTestVariable::Subject->value => ['Subject A', 'Subject B'],
        AbTestVariable::Content->value => ['<p>Image 1</p>', '<p>Image 2</p>'],
    ]);

    foreach ([[$variants[0], 60], [$variants[1], 56], [$variants[2], 30], [$variants[3], 34]] as [$variant, $opens]) {
        mvtSeedArm($campaign, $variant, 200, $opens);
    }

    return $variants;
}

/** A campaign whose subject effect is borderline: 210/1000 vs 175/1000. */
function mvtBorderlineCampaign(Customer $customer, EmailList $list): Campaign
{
    $campaign = Campaign::factory()->create([
        'customer_id' => $customer->id,
        'email_list_id' => $list->id,
        'status' => CampaignStatus::Sending,
        'subject' => 'Borderline',
        'content_html' => '<p>x <a href="{UNSUBSCRIBE_URL}">bye</a></p>',
        'ab_enabled' => true,
        'ab_winner_criteria' => AbWinnerCriteria::UniqueOpens->value,
        'ab_confidence_level' => 95,
    ]);

    $variants = app(MultivariateDesign::class)->generate($campaign, [
        AbTestVariable::Subject->value => ['S1', 'S2'],
        AbTestVariable::Content->value => ['C1', 'C2'],
    ]);

    // Subject marginals 210/1000 vs 175/1000; the image factor is tied.
    foreach ([[$variants[0], 105], [$variants[1], 105], [$variants[2], 88], [$variants[3], 87]] as [$variant, $opens]) {
        mvtSeedArm($campaign, $variant, 500, $opens);
    }

    return $campaign->fresh();
}

function mvtSeedArm(Campaign $campaign, CampaignVariant $variant, int $delivered, int $opens): void
{
    static $counter = 0;

    $now = now();
    $subscribers = [];
    $assignments = [];
    $logs = [];
    $openRows = [];

    for ($i = 0; $i < $delivered; $i++) {
        $id = 700000 + $counter++;
        $uid = 'mvt-'.$id;

        $subscribers[] = [
            'id' => $id,
            'uid' => $uid,
            'customer_id' => $campaign->customer_id,
            'list_id' => $campaign->email_list_id,
            'email' => $uid.'@fixture.test',
            'status' => 'confirmed',
            'source' => 'manual',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $assignments[] = [
            'campaign_id' => $campaign->id,
            'campaign_variant_id' => $variant->id,
            'subscriber_id' => $id,
            'subscriber_uid' => $uid,
            'created_at' => $now,
        ];

        $logs[] = [
            'campaign_id' => $campaign->id,
            'subscriber_id' => $id,
            'status' => CampaignDeliveryLog::STATUS_SENT,
            'retries' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if ($i < $opens) {
            $openRows[] = [
                'campaign_id' => $campaign->id,
                'campaign_uid' => $campaign->uid,
                'subscriber_uid' => $uid,
                'created_at' => $now,
            ];
        }
    }

    foreach (array_chunk($subscribers, 200) as $chunk) {
        DB::table('subscribers')->insert($chunk);
    }

    foreach (array_chunk($assignments, 200) as $chunk) {
        CampaignVariantRecipient::query()->insert($chunk);
    }

    foreach (array_chunk($logs, 200) as $chunk) {
        DB::table('campaign_delivery_logs')->insert($chunk);
    }

    foreach (array_chunk($openRows, 200) as $chunk) {
        CampaignTrackOpen::query()->insert($chunk);
    }
}
