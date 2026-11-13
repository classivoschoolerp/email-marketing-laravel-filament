<?php

declare(strict_types=1);

use App\Enums\AbDecisionMode;
use App\Enums\AbTestVariable;
use App\Enums\AbWinnerCriteria;
use App\Enums\CampaignStatus;
use App\Filament\Customer\Resources\CampaignResource\Pages\CampaignReport;
use App\Models\Campaign;
use App\Models\CampaignDeliveryLog;
use App\Models\CampaignTrackOpen;
use App\Models\CampaignVariant;
use App\Models\CampaignVariantRecipient;
use App\Models\Customer;
use App\Models\EmailList;
use App\Services\Campaigns\AbTest\AbWinnerSelector;
use App\Services\Campaigns\AbTest\SignificanceTest;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * §2.3.6 F-2.3.6-010 — "statistical significance indicator".
 *
 * WP-35 already shipped the whole chain (SignificanceTest → SignificanceResult
 * → AbWinnerSelector::currentSignificance() → CampaignReport::variantSignificance()
 * → report-ab.blade.php). Nothing is rebuilt here. This file is the PROOF that
 * the number the customer reads is the number the maths produces, end to end.
 *
 * THE HAND-COMPUTED REFERENCE, in full.
 *
 * Contingency table (unique opens out of delivered), one arm per variant:
 *
 *                opened    not opened    delivered
 *     variant A      60           140          200
 *     variant B      40           160          200
 *
 *     p̂A = 60/200 = 0.30
 *     p̂B = 40/200 = 0.20
 *     pooled p̂ = (60 + 40) / (200 + 200) = 100/400 = 0.25   (exact)
 *     SE² = p̂(1 − p̂)(1/nA + 1/nB)
 *         = 0.25 · 0.75 · (1/200 + 1/200)
 *         = 0.1875 · 0.01 = 0.001875                        (exact)
 *     SE  = √0.001875 = 0.043301270189221932…
 *     z   = (0.30 − 0.20) / SE = 0.1 / 0.043301270189221932
 *         = 2.309401076758503…                              ( = 4/√3 · … , see below)
 *
 *   z is exactly 0.1/√0.001875. Squaring: z² = 0.01/0.001875 = 16/3, so
 *   z = 4/√3 = 2.3094010767585030580…, an exact closed form — that is why the
 *   z assertion below is an equality to 1e-12 rather than a tolerance band.
 *
 *     p = 2(1 − Φ(|z|)) = 2(1 − Φ(2.3094010767585031))
 *       = 0.0209213353377942…                               (two-tailed)
 *
 *   Φ was evaluated independently of the shipped code with the Maclaurin
 *   series erf(x) = (2/√π) Σ (−1)ⁿ x^(2n+1)/(n!(2n+1)) summed to 1e-20 — a
 *   different algorithm from the Abramowitz & Stegun 7.1.26 rational form the
 *   product uses. The two agree to 5.3·10⁻⁸, comfortably inside the |ε| ≤
 *   1.5·10⁻⁷ bound A&S 7.1.26 claims and which SignificanceTest documents. The
 *   assertion tolerance below (1e-6) is therefore ~20× the algorithm's own
 *   error and still pins the p-value to six decimal places — the exact
 *   precision the report page prints.
 */

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);

    $this->campaign = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
        'status' => CampaignStatus::Sending,
        'subject' => 'Significance fixture',
        'content_html' => '<p>Body <a href="{UNSUBSCRIBE_URL}">bye</a></p>',
        'ab_enabled' => true,
        'ab_variables' => [AbTestVariable::Subject->value],
        'ab_test_percent' => 20,
        'ab_winner_criteria' => AbWinnerCriteria::UniqueOpens->value,
        'ab_decision_mode' => AbDecisionMode::Confidence->value,
        'ab_decision_hours' => 48,
        'ab_confidence_level' => 95,
        'ab_auto_send_winner' => true,
        'send_started_at' => now(),
    ]);

    $this->variantA = $this->campaign->variants()->create([
        'position' => 0, 'label' => 'A', 'weight' => 1, 'subject' => 'A subject',
    ]);
    $this->variantB = $this->campaign->variants()->create([
        'position' => 1, 'label' => 'B', 'weight' => 1, 'subject' => 'B subject',
    ]);
});

// The maths itself, against the hand-computed table above.
it('computes the two-proportion z-test to the hand-computed value', function (): void {
    $result = app(SignificanceTest::class)->compare(60, 200, 40, 200);

    expect($result->computable)->toBeTrue()
        // Rates are exact binary fractions — no tolerance needed.
        ->and($result->rateA)->toBe(0.3)
        ->and($result->rateB)->toBe(0.2)
        // z = 4/√3, closed form (see the header derivation).
        ->and(abs($result->z - 4 / sqrt(3)))->toBeLessThan(1e-12)
        ->and(abs($result->z - 2.3094010767585031))->toBeLessThan(1e-12)
        // p = 2(1 − Φ(4/√3)), independently series-computed.
        ->and(abs($result->pValue - 0.0209213353377942))->toBeLessThan(1e-6)
        // …and the A&S approximation is inside its own published error bound.
        ->and(abs($result->pValue - 0.0209213353377942))->toBeLessThan(1.5e-7)
        // Confidence is literally 1 − p as a percentage, rounded to 4dp.
        ->and(abs($result->confidencePercent() - 97.9079))->toBeLessThan(1e-4);
});

// The z-test is symmetric: swapping the arms flips z and leaves p alone.
it('is symmetric in its two arms', function (): void {
    $forward = app(SignificanceTest::class)->compare(60, 200, 40, 200);
    $backward = app(SignificanceTest::class)->compare(40, 200, 60, 200);

    expect(abs($backward->z + $forward->z))->toBeLessThan(1e-12)
        ->and(abs($backward->pValue - $forward->pValue))->toBeLessThan(1e-12);
});

// MIN_TRIALS is a refusal, not a clamp: it must not return a confident number.
it('refuses to compute below the minimum trial count', function (): void {
    $test = app(SignificanceTest::class);

    expect(SignificanceTest::MIN_TRIALS)->toBe(30);

    // One arm under the floor.
    $under = $test->compare(20, 29, 5, 200);

    expect($under->computable)->toBeFalse()
        ->and($under->pValue)->toBe(1.0)
        ->and($under->z)->toBe(0.0)
        ->and($under->confidencePercent())->toBe(0.0)
        ->and($under->isSignificantAt(95))->toBeFalse()
        // The observed rates are still reported honestly.
        ->and($under->rateA)->toBe(20 / 29);

    // Exactly at the floor it computes.
    expect($test->compare(20, 30, 5, 30)->computable)->toBeTrue();

    // Zero-variance (both arms 100 %) is also a refusal, not a p of 1 dressed
    // up as a result.
    expect($test->compare(50, 50, 50, 50)->computable)->toBeFalse();

    // Nonsense input (more successes than trials) never yields a number.
    expect($test->compare(500, 200, 40, 200)->computable)->toBeFalse();
});

// isSignificantAt at both offered thresholds, on a table that separates them.
it('separates the 95 and 99 percent thresholds', function (): void {
    $test = app(SignificanceTest::class);

    // 30/100 vs 15/100: pooled 0.225, SE = √(0.225·0.775·0.02) = √0.0034875,
    // z = 0.15/0.05905506… = 2.5399…, p = 0.011085… — inside 95 %, outside 99 %.
    $mid = $test->compare(30, 100, 15, 100);

    expect($mid->computable)->toBeTrue()
        ->and(abs($mid->z - 0.15 / sqrt(0.0034875)))->toBeLessThan(1e-12)
        ->and(abs($mid->pValue - 0.0110852))->toBeLessThan(1e-6)
        ->and($mid->isSignificantAt(90))->toBeTrue()
        ->and($mid->isSignificantAt(95))->toBeTrue()
        ->and($mid->isSignificantAt(99))->toBeFalse();

    // The 60/200 vs 40/200 table clears 95 % but not 99 % either (p = 0.0209).
    expect($test->compare(60, 200, 40, 200)->isSignificantAt(95))->toBeTrue()
        ->and($test->compare(60, 200, 40, 200)->isSignificantAt(99))->toBeFalse();

    // A decisive table clears both.
    $strong = $test->compare(150, 1000, 100, 1000);

    expect(abs($strong->pValue - 0.0007233386))->toBeLessThan(1e-6)
        ->and($strong->isSignificantAt(95))->toBeTrue()
        ->and($strong->isSignificantAt(99))->toBeTrue();

    // A null result clears nothing.
    expect($test->compare(55, 100, 50, 100)->isSignificantAt(90))->toBeFalse();
});

// The indicator the report reads: leader vs runner-up over real tracking rows.
it('reads the same value back through the live report indicator', function (): void {
    seedSignificanceArms($this->campaign, $this->variantA, $this->variantB, 200, 60, 40);

    $result = app(AbWinnerSelector::class)->currentSignificance($this->campaign->refresh());

    expect($result)->not->toBeNull()
        // Leader first — A opened 60/200, B 40/200.
        ->and($result->rateA)->toBe(0.3)
        ->and($result->rateB)->toBe(0.2)
        ->and(abs($result->pValue - 0.0209213353377942))->toBeLessThan(1e-6)
        ->and($result->isSignificantAt(95))->toBeTrue();

    // The page-level accessor returns the same object shape.
    Filament::setCurrentPanel(Filament::getPanel('customer'));

    $component = Livewire::test(CampaignReport::class, ['record' => $this->campaign->getRouteKey()]);
    $page = $component->instance()->variantSignificance();

    expect($page)->not->toBeNull()
        ->and(abs($page->pValue - $result->pValue))->toBeLessThan(1e-12);
});

// The number actually reaching the rendered HTML — 97.91 % and p = 0.020921.
it('renders the significance read-out on the report page', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('customer'));

    seedSignificanceArms($this->campaign, $this->variantA, $this->variantB, 200, 60, 40);

    $expected = __('campaigns.ab.report.confidence', [
        'percent' => number_format(97.9079, 2),   // 97.91
        'p' => number_format(0.020921, 6),        // 0.020921
    ]);

    expect($expected)->toBe('Confidence 97.91% (p = 0.020921).');

    Livewire::test(CampaignReport::class, ['record' => $this->campaign->getRouteKey()])
        ->assertOk()
        ->assertSee(__('campaigns.ab.report.title'))
        ->assertSee($expected)
        ->assertDontSee(__('campaigns.ab.report.not_computable', ['min' => SignificanceTest::MIN_TRIALS]));
});

// Below MIN_TRIALS the page says so instead of printing a confident number.
it('renders the not-computable notice instead of a number below the floor', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('customer'));

    // 10 per arm — under SignificanceTest::MIN_TRIALS.
    seedSignificanceArms($this->campaign, $this->variantA, $this->variantB, 10, 6, 2);

    Livewire::test(CampaignReport::class, ['record' => $this->campaign->getRouteKey()])
        ->assertOk()
        ->assertSee(__('campaigns.ab.report.not_computable', ['min' => SignificanceTest::MIN_TRIALS]))
        ->assertDontSee('(p = ');
});

// Not significant → the page says "not yet", and still prints the live number.
it('renders the not-significant notice while the difference is noise', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('customer'));

    // 55/100 vs 50/100 ⇒ p = 0.4789…, confidence 52.105 %.
    seedSignificanceArms($this->campaign, $this->variantA, $this->variantB, 100, 55, 50);

    Livewire::test(CampaignReport::class, ['record' => $this->campaign->getRouteKey()])
        ->assertOk()
        ->assertSee(__('campaigns.ab.report.not_significant', ['level' => 95]))
        ->assertSee(__('campaigns.ab.report.confidence', [
            'percent' => number_format(52.105, 2),
            'p' => number_format(0.478950, 6),
        ]));
});

// A declared winner freezes the p-value on the campaign row, and the report
// prints the frozen number rather than a recomputed one.
it('freezes the deciding p-value on the campaign when a winner is declared', function (): void {
    seedSignificanceArms($this->campaign, $this->variantA, $this->variantB, 200, 60, 40);

    $this->campaign->forceFill([
        'ab_phase' => 'test',
        'ab_test_finished_at' => now(),
    ])->save();

    $winner = app(AbWinnerSelector::class)->evaluate($this->campaign->refresh());

    expect($winner)->not->toBeNull()
        ->and($winner->id)->toBe($this->variantA->id)
        ->and($this->campaign->refresh()->ab_winner_decision)->toBe(AbWinnerSelector::DECISION_CONFIDENCE)
        ->and(abs((float) $this->campaign->refresh()->ab_winner_p_value - 0.0209213353377942))
        ->toBeLessThan(1e-6);

    Filament::setCurrentPanel(Filament::getPanel('customer'));

    Livewire::test(CampaignReport::class, ['record' => $this->campaign->getRouteKey()])
        ->assertOk()
        ->assertSee(__('campaigns.ab.report.confidence', [
            'percent' => number_format((1 - 0.020921) * 100, 2),
            'p' => number_format(0.020921, 6),
        ]));
});

/* ---------------------------------------------------------------------- */

/**
 * Materialise one A/B arm per variant with EXACT delivered and unique-open
 * counts, so the contingency table the z-test sees is the one in the header.
 * Subscribers are inserted through the query builder (no factory) to keep the
 * fixture cost linear and the uids predictable.
 */
function seedSignificanceArms(
    Campaign $campaign,
    CampaignVariant $a,
    CampaignVariant $b,
    int $perArm,
    int $opensA,
    int $opensB,
): void {
    $n = 0;
    $now = now();

    foreach ([[$a, $opensA], [$b, $opensB]] as [$variant, $opens]) {
        $subscribers = [];
        $assignments = [];
        $logs = [];
        $openRows = [];

        for ($i = 0; $i < $perArm; $i++) {
            $uid = sprintf('sig-%s-%05d', $variant->label, $i);
            $id = 500000 + $n++;

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

    $campaign->forceFill([
        'delivery_total' => $perArm * 2,
        'delivery_sent' => $perArm * 2,
    ])->save();
}
