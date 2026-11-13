<?php

declare(strict_types=1);

use App\Enums\AbDecisionMode;
use App\Enums\AbTestVariable;
use App\Enums\AbWinnerCriteria;
use App\Enums\CampaignStatus;
use App\Filament\Customer\Resources\CampaignResource\Pages\CampaignReport;
use App\Jobs\Sending\StartCampaignSendJob;
use App\Models\Campaign;
use App\Models\CampaignDeliveryLog;
use App\Models\CampaignTrackOpen;
use App\Models\CampaignVariant;
use App\Models\CampaignVariantRecipient;
use App\Models\ConversionEvent;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Services\Campaigns\AbTest\AbTestManager;
use App\Services\Campaigns\AbTest\AbTestSplitter;
use App\Services\Campaigns\AbTest\AbTestStats;
use App\Services\Campaigns\AbTest\AbWinnerSelector;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

/*
 * §2.3.6 A/B testing — F-2.3.6-001/002/003/005/006/007/008/009/011/012.
 * Fixture: 100 confirmed subscribers, a 20 % test group, two variants at
 * equal weight ⇒ exactly 10 recipients per variant, 80 held back.
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
        'subject' => 'Base subject',
        'from_name' => 'Base sender',
        'preheader' => 'Base preheader',
        'content_html' => '<html><body><p>Base body</p><a href="{UNSUBSCRIBE_URL}">bye</a></body></html>',
        'ab_enabled' => true,
        'ab_variables' => [AbTestVariable::Subject->value],
        'ab_test_percent' => 20,
        'ab_winner_criteria' => AbWinnerCriteria::UniqueOpens->value,
        'ab_decision_mode' => AbDecisionMode::Window->value,
        'ab_decision_hours' => 4,
        'ab_confidence_level' => 95,
        'ab_auto_send_winner' => true,
        'send_started_at' => now(),
    ]);

    $this->subscribers = collect();

    for ($i = 1; $i <= 100; $i++) {
        $this->subscribers->push(Subscriber::factory()->create([
            'customer_id' => $this->customer->id,
            'list_id' => $this->list->id,
            'email' => 'ab'.str_pad((string) $i, 3, '0', STR_PAD_LEFT).'@fixture.test',
        ]));
    }

    $this->variantA = $this->campaign->variants()->create([
        'position' => 0, 'label' => 'A', 'weight' => 1, 'subject' => 'Variant A subject',
    ]);
    $this->variantB = $this->campaign->variants()->create([
        'position' => 1, 'label' => 'B', 'weight' => 1, 'subject' => 'Variant B subject',
    ]);
});

// F-2.3.6-005 — test group size, exact.
it('sizes the test group from the configured percentage', function (): void {
    $splitter = app(AbTestSplitter::class);

    expect($splitter->testGroupSize(100, 20, 2))->toBe(20)
        ->and($splitter->testGroupSize(100, 100, 2))->toBe(100)
        // Never fewer recipients than variants — a 1 % test of 100 people
        // still has to be able to fill both arms.
        ->and($splitter->testGroupSize(100, 1, 2))->toBe(2)
        ->and($splitter->testGroupSize(0, 20, 2))->toBe(0);
});

// F-2.3.6-002/012 — weighted apportionment, exact and total.
it('apportions the test group across variants by weight', function (): void {
    $splitter = app(AbTestSplitter::class);

    $even = $splitter->seats([$this->variantA, $this->variantB], 20);
    expect(array_values($even))->toBe([10, 10]);

    $this->variantA->update(['weight' => 3]);
    $this->variantB->update(['weight' => 1]);

    $weighted = $splitter->seats([$this->variantA->refresh(), $this->variantB->refresh()], 20);
    expect(array_values($weighted))->toBe([15, 5])
        ->and(array_sum($weighted))->toBe(20);

    // Largest remainder: 10 seats over 3 equal variants = 4/3/3, summing to 10.
    $variantC = $this->campaign->variants()->create(['position' => 2, 'label' => 'C', 'weight' => 1]);
    $this->variantA->update(['weight' => 1]);

    $three = $splitter->seats([$this->variantA->refresh(), $this->variantB->refresh(), $variantC], 10);
    expect(array_sum($three))->toBe(10)
        ->and(array_values($three))->toBe([4, 3, 3]);
});

// The split must be reproducible — a resume wave may not redraw it.
it('splits deterministically for the same campaign and subscribers', function (): void {
    $splitter = app(AbTestSplitter::class);
    $rows = Subscriber::query()->get(['id', 'uid']);

    $first = $splitter->split($this->campaign, $rows, [$this->variantA, $this->variantB]);
    $second = $splitter->split($this->campaign, $rows->shuffle(), [$this->variantA, $this->variantB]);

    expect($first['assignments'])->toBe($second['assignments'])
        ->and(count($first['assignments']))->toBe(20);
});

// F-2.3.6-001/005/008 — the send path only mails the test group first.
it('sends only the test group during the test phase', function (): void {
    Bus::fake();

    app(StartCampaignSendJob::class, ['campaignId' => $this->campaign->id]);
    (new StartCampaignSendJob($this->campaign->id))->handle(app(\App\Services\Campaigns\CampaignRecipients::class));

    $this->campaign->refresh();

    expect($this->campaign->ab_phase)->toBe('test')
        ->and($this->campaign->delivery_total)->toBe(20);

    $assignments = CampaignVariantRecipient::query()->where('campaign_id', $this->campaign->id)->get();

    expect($assignments)->toHaveCount(20)
        ->and($assignments->where('campaign_variant_id', $this->variantA->id))->toHaveCount(10)
        ->and($assignments->where('campaign_variant_id', $this->variantB->id))->toHaveCount(10);

    expect((int) $this->variantA->refresh()->recipients)->toBe(10)
        ->and((int) $this->variantB->refresh()->recipients)->toBe(10);

    Bus::assertBatched(fn ($batch): bool => $batch->jobs->count() === 20);
});

// F-2.3.6-003 — the variant overrides only the variable under test.
it('renders the variant subject and inherits everything else', function (): void {
    $subscriber = $this->subscribers->first();

    $base = app(\App\Sending\Pipeline\CampaignEmailFactory::class)->make($this->campaign, $subscriber);
    $variant = app(\App\Sending\Pipeline\CampaignEmailFactory::class)
        ->make($this->campaign, $subscriber, $this->variantA);

    expect($base->subject)->toBe('Base subject')
        ->and($variant->subject)->toBe('Variant A subject')
        // from_name / preheader / body are NOT part of this test.
        ->and($variant->fromName)->toBe($base->fromName)
        ->and($variant->html)->toContain('Base body')
        ->and($variant->html)->toContain('Base preheader');
});

it('renders a from-name, preheader and content variant when those are the variables', function (): void {
    $this->campaign->update([
        'ab_variables' => [
            AbTestVariable::FromName->value,
            AbTestVariable::Preheader->value,
            AbTestVariable::Content->value,
        ],
    ]);

    $this->variantA->update([
        'from_name' => 'Variant sender',
        'preheader' => 'Variant preheader',
        'content_html' => '<html><body><p>Variant body</p></body></html>',
    ]);

    $message = app(\App\Sending\Pipeline\CampaignEmailFactory::class)
        ->make($this->campaign->refresh(), $this->subscribers->first(), $this->variantA->refresh());

    expect($message->fromName)->toBe('Variant sender')
        ->and($message->html)->toContain('Variant body')
        ->and($message->html)->toContain('Variant preheader')
        ->and($message->html)->not->toContain('Base body');
});

// F-2.3.6-009 — per-variant stats, exact.
it('computes per-variant stats exactly', function (): void {
    seedAbTestSend($this->campaign, $this->variantA, $this->variantB);

    // A: 10 delivered, 6 unique openers. B: 10 delivered, 2 unique openers.
    $rows = app(AbTestStats::class)->table($this->campaign);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['label'])->toBe('A')
        ->and($rows[0]['recipients'])->toBe(10)
        ->and($rows[0]['delivered'])->toBe(10)
        ->and($rows[0]['unique_opens'])->toBe(6)
        ->and($rows[0]['opens'])->toBe(7)
        ->and($rows[0]['open_rate'])->toBe(60.0)
        ->and($rows[0]['criterion_value'])->toBe(60.0)
        ->and($rows[1]['label'])->toBe('B')
        ->and($rows[1]['unique_opens'])->toBe(2)
        ->and($rows[1]['open_rate'])->toBe(20.0);
});

// F-2.3.6-007 — decision window: nothing happens before N hours.
it('waits for the decision window before picking a winner', function (): void {
    seedAbTestSend($this->campaign, $this->variantA, $this->variantB);

    $this->campaign->forceFill([
        'ab_phase' => 'test',
        'ab_test_finished_at' => now()->subHours(1),
    ])->save();

    expect(app(AbWinnerSelector::class)->evaluate($this->campaign->refresh()))->toBeNull()
        ->and($this->campaign->refresh()->ab_winner_variant_id)->toBeNull();

    Carbon::setTestNow(now()->addHours(4));

    $winner = app(AbWinnerSelector::class)->evaluate($this->campaign->refresh());

    expect($winner)->not->toBeNull()
        ->and($winner->id)->toBe($this->variantA->id)
        ->and($this->campaign->refresh()->ab_winner_decision)->toBe(AbWinnerSelector::DECISION_WINDOW)
        ->and($this->campaign->refresh()->ab_phase)->toBe('winner');
});

// F-2.3.6-007 — manual mode never decides on its own.
it('never decides automatically in manual mode', function (): void {
    seedAbTestSend($this->campaign, $this->variantA, $this->variantB);

    $this->campaign->forceFill([
        'ab_decision_mode' => AbDecisionMode::Manual->value,
        'ab_phase' => 'test',
        'ab_test_finished_at' => now()->subDays(3),
    ])->save();

    expect(app(AbWinnerSelector::class)->evaluate($this->campaign->refresh()))->toBeNull();

    // …but the customer can.
    $winner = app(AbWinnerSelector::class)->chooseManually($this->campaign->refresh(), $this->variantB);

    expect($winner->id)->toBe($this->variantB->id)
        ->and($this->campaign->refresh()->ab_winner_decision)->toBe(AbWinnerSelector::DECISION_MANUAL);
});

// F-2.3.6-011 — significance, not a timer.
it('declares a winner as soon as statistical confidence is reached', function (): void {
    // 1000 per arm: A 15 % vs B 10 % ⇒ p = 0.000723, well inside 95 %.
    seedLargeAbTest($this->campaign, $this->variantA, $this->variantB, 1000, 150, 100);

    $this->campaign->forceFill([
        'ab_decision_mode' => AbDecisionMode::Confidence->value,
        'ab_confidence_level' => 95,
        // Zero hours elapsed: the ONLY reason to decide is significance.
        'ab_phase' => 'test',
        'ab_test_finished_at' => now(),
        'ab_decision_hours' => 48,
    ])->save();

    $winner = app(AbWinnerSelector::class)->evaluate($this->campaign->refresh());

    expect($winner)->not->toBeNull()
        ->and($winner->id)->toBe($this->variantA->id)
        ->and($this->campaign->refresh()->ab_winner_decision)->toBe(AbWinnerSelector::DECISION_CONFIDENCE)
        ->and(round((float) $this->campaign->refresh()->ab_winner_p_value, 8))->toBe(0.00072334);
});

it('does not declare a winner while the difference is not significant', function (): void {
    // 100 per arm: 55 % vs 50 % ⇒ p = 0.479, nowhere near 95 %.
    seedLargeAbTest($this->campaign, $this->variantA, $this->variantB, 100, 55, 50);

    $this->campaign->forceFill([
        'ab_decision_mode' => AbDecisionMode::Confidence->value,
        'ab_confidence_level' => 95,
        'ab_phase' => 'test',
        'ab_test_finished_at' => now(),
        'ab_decision_hours' => 48,
    ])->save();

    expect(app(AbWinnerSelector::class)->evaluate($this->campaign->refresh()))->toBeNull()
        ->and($this->campaign->refresh()->ab_winner_variant_id)->toBeNull();

    // The documented fallback ceiling still ships the campaign eventually.
    Carbon::setTestNow(now()->addHours(48));

    $winner = app(AbWinnerSelector::class)->evaluate($this->campaign->refresh());

    expect($winner)->not->toBeNull()
        ->and($this->campaign->refresh()->ab_winner_decision)->toBe(AbWinnerSelector::DECISION_FALLBACK);
});

/*
 * F-2.3.6-006 — a criterion with no data source still refuses to decide.
 *
 * WP-35 shipped this test against `conversions`, which had NO producer in the
 * product at all. WP-45 (§2.12) built one — the goal pixel and revenue
 * attribution — so the criterion is now measurable, and the honest form of
 * the same guard is per CAMPAIGN: a campaign that never opted into goal
 * tracking and has recorded no conversion has nothing to rank, and declaring
 * a 0-vs-0 winner would be exactly the invention this guard exists to stop.
 */
it('refuses to decide on a criterion with no data source', function (): void {
    seedLargeAbTest($this->campaign, $this->variantA, $this->variantB, 1000, 150, 100);

    $this->campaign->forceFill([
        'ab_winner_criteria' => AbWinnerCriteria::Conversions->value,
        'ab_decision_mode' => AbDecisionMode::Window->value,
        'ab_phase' => 'test',
        'ab_test_finished_at' => now()->subDays(2),
        // Goal tracking OFF and no conversion recorded: nothing could arrive.
        'goal_tracking_enabled' => false,
    ])->save();

    $campaign = $this->campaign->refresh();

    expect(AbWinnerCriteria::Conversions->isAvailableFor($campaign))->toBeFalse()
        ->and(app(AbTestStats::class)->proportionFor($campaign, $this->variantA))->toBeNull()
        ->and(app(AbWinnerSelector::class)->evaluate($campaign))->toBeNull();
});

// F-2.3.6-006 — with a real producer, conversions is a first-class criterion.
it('offers conversions as a winner criterion now that revenue attribution exists', function (): void {
    expect(AbWinnerCriteria::Conversions->isAvailable())->toBeTrue()
        ->and(AbWinnerCriteria::options())->toHaveKey('conversions')
        // Measured as CONVERTERS over DELIVERED: one shopper buying twice is
        // one converted recipient, and a z-test needs a proportion.
        ->and(AbWinnerCriteria::Conversions->proportion())->toBe(['converters', 'delivered']);
});

// F-2.3.6-006 — the criterion ranks on real, per-variant conversion rows.
it('ranks and decides on conversions when the campaign records them', function (): void {
    seedAbTestSend($this->campaign, $this->variantA, $this->variantB);

    $this->campaign->forceFill([
        'ab_winner_criteria' => AbWinnerCriteria::Conversions->value,
        'ab_decision_mode' => AbDecisionMode::Window->value,
        'ab_phase' => 'test',
        'ab_test_finished_at' => now()->subDays(2),
        'goal_tracking_enabled' => true,
    ])->save();

    // Two conversions worth 12500 and 4999 minor units, both on variant B's
    // recipients; variant A converts nobody.
    $bRecipients = CampaignVariantRecipient::query()
        ->where('campaign_variant_id', $this->variantB->id)
        ->pluck('subscriber_uid');

    foreach ([[0, 12500], [1, 4999]] as $index => $pair) {
        [$position, $value] = $pair;

        ConversionEvent::query()->create([
            'customer_id' => $this->customer->id,
            'campaign_id' => $this->campaign->id,
            'campaign_uid' => $this->campaign->uid,
            'subscriber_uid' => $bRecipients[$position],
            'origin' => ConversionEvent::ORIGIN_PIXEL,
            'external_ref' => 'ab-conv-'.$index,
            'value' => $value,
            'currency' => 'USD',
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    $rows = collect(app(AbTestStats::class)->table($this->campaign->refresh()))->keyBy('label');

    expect($rows['B']['conversions'])->toBe(2)
        ->and($rows['B']['converters'])->toBe(2)
        // Money stays integer minor units all the way to the report table.
        ->and($rows['B']['revenue'])->toBe(17499)
        ->and($rows['A']['conversions'])->toBe(0)
        ->and($rows['A']['revenue'])->toBe(0);

    $winner = app(AbWinnerSelector::class)->evaluate($this->campaign->refresh());

    expect($winner?->id)->toBe($this->variantB->id)
        ->and($this->campaign->refresh()->ab_winner_decision)->toBe(AbWinnerSelector::DECISION_WINDOW);
});

// F-2.3.6-006 — click-to-open is a proportion of OPENERS, not of delivered.
it('ranks on click-to-open when that is the criterion', function (): void {
    seedAbTestSend($this->campaign, $this->variantA, $this->variantB);

    $this->campaign->update(['ab_winner_criteria' => AbWinnerCriteria::ClickToOpen->value]);

    $rows = app(AbTestStats::class)->table($this->campaign->refresh());

    // A: 0 clicks / 6 openers = 0.0. B: 1 click / 2 openers = 50.0.
    expect($rows[0]['criterion_value'])->toBe(0.0)
        ->and($rows[1]['criterion_value'])->toBe(50.0);
});

// F-2.3.6-008 — the winner phase mails the remainder.
it('sends the winner to the remainder once a winner is declared', function (): void {
    Bus::fake();

    // Test wave: 20 recipients logged.
    (new StartCampaignSendJob($this->campaign->id))->handle(app(\App\Services\Campaigns\CampaignRecipients::class));

    foreach (CampaignVariantRecipient::query()->where('campaign_id', $this->campaign->id)->get() as $row) {
        CampaignDeliveryLog::query()->create([
            'campaign_id' => $this->campaign->id,
            'subscriber_id' => $row->subscriber_id,
            'status' => CampaignDeliveryLog::STATUS_SENT,
            'retries' => 0,
        ]);
    }

    $this->campaign->forceFill([
        'ab_phase' => 'winner',
        'ab_winner_variant_id' => $this->variantA->id,
        'ab_winner_decided_at' => now(),
    ])->save();

    (new StartCampaignSendJob($this->campaign->id))->handle(app(\App\Services\Campaigns\CampaignRecipients::class));

    // 100 total − 20 already logged = 80 remaining, all on variant A.
    Bus::assertBatched(function ($batch): bool {
        return $batch->jobs->count() === 80
            && $batch->jobs->every(fn ($job): bool => $job->variantId === $this->variantA->id);
    });
});

// F-2.3.6-008 — with auto-send off the campaign simply finishes.
it('does not mail the remainder when auto-send winner is off', function (): void {
    seedAbTestSend($this->campaign, $this->variantA, $this->variantB);

    $this->campaign->forceFill([
        'ab_auto_send_winner' => false,
        'ab_phase' => 'test',
        'ab_test_finished_at' => now()->subDays(1),
    ])->save();

    app(AbWinnerSelector::class)->evaluate($this->campaign->refresh());

    expect($this->campaign->refresh()->ab_phase)->toBe('done')
        ->and($this->campaign->refresh()->send_batch_id)->toBe(AbWinnerSelector::WAITING_HANDLE);
});

// F-2.3.6-002 — the 2..8 bound is enforced, not silently clamped away.
it('reports a configuration problem outside the 2..8 variant range', function (): void {
    $manager = app(AbTestManager::class);

    expect($manager->problems($this->campaign))->toBe([]);

    $this->variantB->delete();

    expect($manager->problems($this->campaign->refresh()))
        ->toContain(__('campaigns.ab.errors.variant_count', ['min' => 2, 'max' => 8]))
        // A mis-configured test never reaches the send path.
        ->and($manager->sendableVariants($this->campaign->refresh()))->toBe([]);
});

it('normalises variant labels, positions and weights on save', function (): void {
    $this->campaign->variants()->create(['position' => 9, 'label' => 'Z', 'weight' => 0]);

    app(AbTestManager::class)->normalize($this->campaign);

    $labels = $this->campaign->variants()->pluck('label')->all();

    expect($labels)->toBe(['A', 'B', 'C'])
        ->and($this->campaign->variants()->pluck('position')->all())->toBe([0, 1, 2])
        ->and($this->campaign->variants()->min('weight'))->toBe(1);
});

it('clears overrides for variables the test does not vary', function (): void {
    $this->variantA->update(['from_name' => 'Stale override', 'content_html' => '<p>stale</p>']);

    // Only the subject is under test.
    app(AbTestManager::class)->normalize($this->campaign);

    expect($this->variantA->refresh()->from_name)->toBeNull()
        ->and($this->variantA->refresh()->content_html)->toBeNull()
        ->and($this->variantA->refresh()->subject)->toBe('Variant A subject');
});

// F-2.3.6-009 — the report renders the comparison table.
it('renders the variant comparison table on the report page', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    seedAbTestSend($this->campaign, $this->variantA, $this->variantB);

    Livewire::test(CampaignReport::class, ['record' => $this->campaign->getRouteKey()])
        ->assertOk()
        ->assertSee(__('campaigns.ab.report.title'))
        ->assertSee(__('campaigns.ab.report.variant'));
});

// F-2.3.6-007 — the manual pick action on the report page.
it('lets the customer pick the winner from the report page', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    seedAbTestSend($this->campaign, $this->variantA, $this->variantB);

    $this->campaign->forceFill([
        'ab_decision_mode' => AbDecisionMode::Manual->value,
        'ab_phase' => 'test',
        'ab_test_finished_at' => now(),
    ])->save();

    Livewire::test(CampaignReport::class, ['record' => $this->campaign->getRouteKey()])
        ->call('chooseWinner', $this->variantB->id);

    expect($this->campaign->refresh()->ab_winner_variant_id)->toBe($this->variantB->id)
        ->and($this->variantB->refresh()->is_winner)->toBeTrue();
});

// F-2.3.6-001/012 — the wizard writes the A/B configuration + variants.
it('configures an a/b test through the wizard composer', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('customer'));

    $plain = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
        'name' => 'Plain campaign',
        'subject' => 'Plain subject',
        'content_html' => '<p>Body <a href="{UNSUBSCRIBE_URL}">bye</a></p>',
    ]);

    Livewire::test(\App\Filament\Customer\Resources\CampaignResource\Pages\EditCampaign::class, [
        'record' => $plain->getRouteKey(),
    ])
        ->fillForm([
            'ab_enabled' => true,
            'ab_variables' => [AbTestVariable::Subject->value],
            'ab_test_percent' => 30,
            'ab_winner_criteria' => AbWinnerCriteria::UniqueClicks->value,
            'ab_decision_mode' => AbDecisionMode::Confidence->value,
            'ab_decision_hours' => 12,
            'ab_confidence_level' => 99,
            'ab_auto_send_winner' => true,
            'variants' => [
                'v1' => ['subject' => 'Wizard A', 'weight' => 3, 'send_delay_minutes' => 0],
                'v2' => ['subject' => 'Wizard B', 'weight' => 1, 'send_delay_minutes' => 0],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $plain->refresh();

    expect($plain->ab_enabled)->toBeTrue()
        ->and($plain->ab_test_percent)->toBe(30)
        ->and($plain->ab_winner_criteria)->toBe(AbWinnerCriteria::UniqueClicks->value)
        ->and($plain->ab_decision_mode)->toBe(AbDecisionMode::Confidence->value)
        ->and((int) $plain->ab_confidence_level)->toBe(99)
        ->and($plain->variants()->count())->toBe(2)
        // Labels and positions are re-established by AbTestManager on save.
        ->and($plain->variants()->pluck('label')->all())->toBe(['A', 'B'])
        ->and($plain->variants()->pluck('subject')->all())->toBe(['Wizard A', 'Wizard B'])
        ->and($plain->variants()->pluck('weight')->all())->toBe([3, 1]);
});

// The winner tick lives in campaigns:send, the only every-minute hook
// available (routes/console.php is frozen).
it('decides winners from the campaigns:send tick and releases the send handle', function (): void {
    seedAbTestSend($this->campaign, $this->variantA, $this->variantB);

    $this->campaign->forceFill([
        'ab_phase' => 'test',
        'ab_test_finished_at' => now()->subHours(6),
        'send_batch_id' => AbWinnerSelector::WAITING_HANDLE,
    ])->save();

    $this->artisan('campaigns:send')->assertExitCode(0);

    $this->campaign->refresh();

    expect($this->campaign->ab_winner_variant_id)->toBe($this->variantA->id)
        ->and($this->campaign->ab_phase)->toBe('winner')
        // Handle released ⇒ the next tick starts the remainder wave.
        ->and($this->campaign->send_batch_id)->toBeNull();
});

// The test wave must NOT flip the campaign to sent.
it('parks the campaign after the test wave instead of marking it sent', function (): void {
    seedAbTestSend($this->campaign, $this->variantA, $this->variantB);

    $this->campaign->forceFill(['ab_phase' => 'test'])->save();

    (new \App\Jobs\Sending\FinishCampaignSendJob($this->campaign->id))
        ->handle(app(\App\Services\Campaigns\CampaignStatusMachine::class));

    $this->campaign->refresh();

    expect($this->campaign->status)->toBe(CampaignStatus::Sending)
        ->and($this->campaign->ab_test_finished_at)->not->toBeNull()
        ->and($this->campaign->send_batch_id)->toBe(AbWinnerSelector::WAITING_HANDLE);
});

// F-2.3.5-008 — preflight blocks a broken A/B configuration, so a test that
// cannot run never silently degrades into a plain send.
it('blocks scheduling a mis-configured a/b test', function (): void {
    $preflight = app(\App\Services\Campaigns\CampaignPreflight::class);

    $checks = collect($preflight->checks($this->campaign))->keyBy('key');

    expect($checks)->toHaveKey('ab_test')
        ->and($checks['ab_test']['passed'])->toBeTrue();

    $this->campaign->update(['ab_variables' => []]);

    $checks = collect($preflight->checks($this->campaign->fresh()))->keyBy('key');

    expect($checks['ab_test']['passed'])->toBeFalse()
        ->and($checks['ab_test']['detail'])->toContain(__('campaigns.ab.errors.no_variable'))
        ->and($preflight->passes($this->campaign->fresh()))->toBeFalse();
});

// F-2.3.3-008 — an unbalanced [IF] would swallow the rest of the email.
it('blocks scheduling content with unbalanced conditional blocks', function (): void {
    $this->campaign->update([
        'ab_enabled' => false,
        'content_html' => "<p>[IF FNAME = 'Ana']Hi Ana</p><a href=\"{UNSUBSCRIBE_URL}\">bye</a>",
    ]);

    $checks = collect(app(\App\Services\Campaigns\CampaignPreflight::class)
        ->checks($this->campaign->fresh()))->keyBy('key');

    expect($checks)->toHaveKey('conditional_blocks')
        ->and($checks['conditional_blocks']['passed'])->toBeFalse();
});

// Cross-tenant denial.
it('denies a foreign customer the a/b report page', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('customer'));

    $other = Customer::factory()->create();
    $this->actingAs($other, 'customer');
    app(CurrentCustomer::class)->set($other);

    $this->get(\App\Filament\Customer\Resources\CampaignResource::getUrl('report', [
        'record' => $this->campaign,
    ]))->assertNotFound();
});

/* ---------------------------------------------------------------------- */

/**
 * Test wave already sent: 10 recipients per variant, all delivered.
 * Opens: variant A → 6 distinct openers (one opens twice ⇒ 7 hits),
 *        variant B → 2 distinct openers, one of whom also clicks.
 */
function seedAbTestSend(Campaign $campaign, CampaignVariant $a, CampaignVariant $b): void
{
    $subscribers = Subscriber::query()->orderBy('id')->get();
    $index = 0;

    $url = \App\Models\CampaignTrackUrl::query()->create([
        'campaign_id' => $campaign->id,
        'campaign_uid' => $campaign->uid,
        'url' => 'https://example.com/ab',
        'hash' => str_repeat('c', 64),
    ]);

    foreach ([[$a, 6, 0], [$b, 2, 1]] as [$variant, $openers, $clickers]) {
        for ($i = 0; $i < 10; $i++) {
            $subscriber = $subscribers[$index++];

            CampaignVariantRecipient::query()->create([
                'campaign_id' => $campaign->id,
                'campaign_variant_id' => $variant->id,
                'subscriber_id' => $subscriber->id,
                'subscriber_uid' => $subscriber->uid,
                'created_at' => now(),
            ]);

            CampaignDeliveryLog::query()->create([
                'campaign_id' => $campaign->id,
                'subscriber_id' => $subscriber->id,
                'status' => CampaignDeliveryLog::STATUS_SENT,
                'retries' => 0,
            ]);

            if ($i < $openers) {
                CampaignTrackOpen::query()->create([
                    'campaign_id' => $campaign->id,
                    'campaign_uid' => $campaign->uid,
                    'subscriber_uid' => $subscriber->uid,
                    'created_at' => now(),
                ]);

                // One extra hit on variant A so total ≠ unique.
                if ($variant->id === $a->id && $i === 0) {
                    CampaignTrackOpen::query()->create([
                        'campaign_id' => $campaign->id,
                        'campaign_uid' => $campaign->uid,
                        'subscriber_uid' => $subscriber->uid,
                        'created_at' => now(),
                    ]);
                }
            }

            if ($i < $clickers) {
                \App\Models\CampaignTrackClick::query()->create([
                    'campaign_id' => $campaign->id,
                    'campaign_uid' => $campaign->uid,
                    'subscriber_uid' => $subscriber->uid,
                    'url_id' => $url->id,
                    'created_at' => now(),
                ]);
            }
        }
    }

    $campaign->forceFill(['delivery_total' => 20, 'delivery_sent' => 20])->save();
}

/** A large synthetic test wave with exact per-arm open counts. */
function seedLargeAbTest(Campaign $campaign, CampaignVariant $a, CampaignVariant $b, int $perArm, int $opensA, int $opensB): void
{
    $customerId = $campaign->customer_id;
    $listId = $campaign->email_list_id;
    $n = 0;

    foreach ([[$a, $opensA], [$b, $opensB]] as [$variant, $opens]) {
        for ($i = 0; $i < $perArm; $i++) {
            $uid = sprintf('ab-%s-%05d', $variant->label, $i);
            $id = 100000 + $n++;

            \Illuminate\Support\Facades\DB::table('subscribers')->insert([
                'id' => $id,
                'uid' => $uid,
                'customer_id' => $customerId,
                'list_id' => $listId,
                'email' => $uid.'@fixture.test',
                'status' => 'confirmed',
                'source' => 'manual',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            CampaignVariantRecipient::query()->create([
                'campaign_id' => $campaign->id,
                'campaign_variant_id' => $variant->id,
                'subscriber_id' => $id,
                'subscriber_uid' => $uid,
                'created_at' => now(),
            ]);

            CampaignDeliveryLog::query()->create([
                'campaign_id' => $campaign->id,
                'subscriber_id' => $id,
                'status' => CampaignDeliveryLog::STATUS_SENT,
                'retries' => 0,
            ]);

            if ($i < $opens) {
                CampaignTrackOpen::query()->create([
                    'campaign_id' => $campaign->id,
                    'campaign_uid' => $campaign->uid,
                    'subscriber_uid' => $uid,
                    'created_at' => now(),
                ]);
            }
        }
    }
}
