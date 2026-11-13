<?php

declare(strict_types=1);

use App\Enums\CampaignStatus;
use App\Jobs\Sending\RefreshSendTimeProfilesJob;
use App\Jobs\Sending\StartCampaignSendJob;
use App\Models\Campaign;
use App\Models\CampaignTrackClick;
use App\Models\CampaignTrackOpen;
use App\Models\CampaignTrackUrl;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Models\SubscriberSendTimeProfile;
use App\Sending\Pipeline\SendTimeOptimizer;
use App\Services\Campaigns\CampaignRecipients;
use App\Services\Sending\SendTimeProfiler;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;

/*
 * §2.3.4 F-2.3.4-011 — Send Time Optimization.
 *
 * THE HISTOGRAM IS ASSERTED AGAINST HAND-COMPUTED WEIGHTS. The weights are
 * SendTimeProfiler's published constants:
 *
 *     open  = 1.0        click = 3.0        half-life = 90 days
 *     weight = base · 0.5^(ageDays / 90)
 *
 * Frozen "now" = 2026-08-13 12:00:00 UTC (a Thursday, dayOfWeek 4). The
 * fixture events and their weights, computed by hand from that formula:
 *
 *   1. open,  at now              → age 0 d       → 1.0 · 0.5^0        = 1.0
 *      bucket: hour 12, Thursday (4)
 *   2. open,  at now − 90 d       → age 90 d      → 1.0 · 0.5^1        = 0.5
 *      2026-05-15 12:00, bucket: hour 12, Friday (5)
 *   3. click, at now − 6 h        → age 0.25 d    → 3.0 · 0.5^(0.25/90)
 *      = 3.0 · 0.99807644…  = 2.99422933…
 *      bucket: hour 6, Thursday (4)
 *   4. click, at now − 400 d − 3 h → age 400.125 d → DROPPED, outside the
 *      365-day lookback window; asserted, not assumed.
 *
 *     hours:  {12 → 1.5,  6 → 2.99422933…}      best_hour = 6
 *     days:   { 4 → 3.99422933…, 5 → 0.5}       best_day  = 4
 *     weight_total = 4.49422933…
 *     confidence   = 2.99422933… / 4.49422933… = 0.66623866…
 *
 * Note what case 3 demonstrates on its own: ONE click from this morning
 * outweighs TWO opens, one of them today — which is exactly what the 3× click
 * weight and the 90-day half-life were chosen to mean.
 */

beforeEach(function (): void {
    // A fixed instant so every age is exact. 2026-08-13 is a Thursday.
    Carbon::setTestNow(Carbon::parse('2026-08-13 12:00:00', 'UTC'));

    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/* ====================================================================== *
 *  The learned histogram
 * ====================================================================== */

it('weights engagement events exactly as documented', function (): void {
    $now = Carbon::parse('2026-08-13 12:00:00', 'UTC');

    // The hand-computed click weight, written as the formula it comes from.
    $click = 3.0 * 0.5 ** (0.25 / 90);   // = 2.99422933072688…

    expect(abs($click - 2.9942293307))->toBeLessThan(1e-9);

    $histogram = app(SendTimeProfiler::class)->histogram([
        ['at' => $now->copy(), 'weight' => SendTimeProfiler::WEIGHT_OPEN],
        ['at' => $now->copy()->subDays(90), 'weight' => SendTimeProfiler::WEIGHT_OPEN],
        ['at' => $now->copy()->subHours(6), 'weight' => SendTimeProfiler::WEIGHT_CLICK],
        // Outside the 365-day window: contributes nothing.
        ['at' => $now->copy()->subDays(400)->subHours(3), 'weight' => SendTimeProfiler::WEIGHT_CLICK],
    ], 'UTC', $now);

    expect($histogram['events'])->toBe(3)
        ->and(abs($histogram['hours'][12] - 1.5))->toBeLessThan(1e-9)
        ->and(abs($histogram['hours'][6] - $click))->toBeLessThan(1e-9)
        // The 400-day-old click bucketed to hour 9 and was dropped entirely.
        ->and($histogram['hours'])->not->toHaveKey(9)
        ->and(abs($histogram['weight_total'] - (1.5 + $click)))->toBeLessThan(1e-9)
        ->and(abs($histogram['weight_total'] - 4.4942293307))->toBeLessThan(1e-9)
        // One fresh click beats two opens.
        ->and($histogram['best_hour'])->toBe(6)
        ->and(abs($histogram['confidence'] - $click / (1.5 + $click)))->toBeLessThan(1e-9)
        ->and(abs($histogram['confidence'] - 0.6662386608))->toBeLessThan(1e-9);

    // 2026-08-13 is a Thursday (dayOfWeek 4); 90 days earlier is 2026-05-15,
    // a Friday (5).
    expect(abs($histogram['days'][4] - (1.0 + $click)))->toBeLessThan(1e-9)
        ->and(abs($histogram['days'][5] - 0.5))->toBeLessThan(1e-9)
        ->and($histogram['best_day'])->toBe(4);
});

it('weights a click three times an open of the same age', function (): void {
    $now = Carbon::parse('2026-08-13 12:00:00', 'UTC');
    $profiler = app(SendTimeProfiler::class);

    $open = $profiler->histogram(
        [['at' => $now->copy()->subHours(4), 'weight' => SendTimeProfiler::WEIGHT_OPEN]],
        'UTC',
        $now,
    );
    $click = $profiler->histogram(
        [['at' => $now->copy()->subHours(4), 'weight' => SendTimeProfiler::WEIGHT_CLICK]],
        'UTC',
        $now,
    );

    expect(abs($click['weight_total'] - 3 * $open['weight_total']))->toBeLessThan(1e-9);

    // And an old open really does lose to a recent one: 0.5^(365/90) = 0.0611…
    $old = $profiler->histogram(
        [['at' => $now->copy()->subDays(365), 'weight' => SendTimeProfiler::WEIGHT_OPEN]],
        'UTC',
        $now,
    );

    expect(abs($old['weight_total'] - 0.5 ** (365 / 90)))->toBeLessThan(1e-9)
        ->and($old['weight_total'])->toBeLessThan(0.07);
});

it('buckets the histogram in the subscribers own timezone', function (): void {
    $now = Carbon::parse('2026-08-13 12:00:00', 'UTC');
    // 23:30 UTC on the 12th is 08:30 on the 13th in Tokyo (UTC+9).
    $event = [['at' => Carbon::parse('2026-08-12 23:30:00', 'UTC'), 'weight' => SendTimeProfiler::WEIGHT_OPEN]];

    $utc = app(SendTimeProfiler::class)->histogram($event, 'UTC', $now);
    $tokyo = app(SendTimeProfiler::class)->histogram($event, 'Asia/Tokyo', $now);

    expect($utc['best_hour'])->toBe(23)
        ->and($tokyo['best_hour'])->toBe(8)
        // Same weight, different label — the moment did not move.
        ->and(abs($utc['weight_total'] - $tokyo['weight_total']))->toBeLessThan(1e-12);
});

/* ====================================================================== *
 *  Profiles, and the documented fallback chain
 * ====================================================================== */

it('writes a personal profile once there is enough evidence', function (): void {
    $subscriber = sto_subscriber($this->customer->id, $this->list->id, 'Europe/Paris');

    // Three opens at 07:00 UTC = 09:00 Paris (CEST, UTC+2) in August.
    sto_opens($subscriber->uid, ['2026-08-12 07:00:00', '2026-08-10 07:00:00', '2026-08-05 07:00:00']);

    $profile = app(SendTimeProfiler::class)->refreshSubscriber($subscriber);

    expect($profile->source)->toBe(SubscriberSendTimeProfile::SOURCE_SUBSCRIBER)
        ->and($profile->best_hour)->toBe(9)
        ->and($profile->timezone)->toBe('Europe/Paris')
        ->and($profile->events)->toBe(3)
        ->and($profile->predicts())->toBeTrue()
        // All the weight is in one hour.
        ->and(abs($profile->confidence - 1.0))->toBeLessThan(1e-9);
});

it('refuses to predict below the documented minimum evidence', function (): void {
    $subscriber = sto_subscriber($this->customer->id, $this->list->id, 'UTC');

    // Two events — one below MIN_EVENTS.
    sto_opens($subscriber->uid, ['2026-08-12 07:00:00', '2026-08-11 07:00:00']);

    $profile = app(SendTimeProfiler::class)->refreshSubscriber($subscriber);

    expect(SendTimeProfiler::MIN_EVENTS)->toBe(3)
        ->and($profile->events)->toBe(2)
        ->and($profile->source)->toBe(SubscriberSendTimeProfile::SOURCE_NONE)
        ->and($profile->best_hour)->toBeNull()
        ->and($profile->predicts())->toBeFalse();
});

it('falls back to the account best hour, then to nothing at all', function (): void {
    $quiet = sto_subscriber($this->customer->id, $this->list->id, 'UTC');

    // No account history either ⇒ source none, no hour invented.
    expect(app(SendTimeProfiler::class)->refreshSubscriber($quiet)->source)
        ->toBe(SubscriberSendTimeProfile::SOURCE_NONE);

    // Give the tenant one sent campaign with delivered mail and opens: the
    // account-level analysis now has a best hour, and a subscriber with no
    // history of their own inherits it.
    $accountHour = sto_seed_account_history($this->customer, $this->list);

    $profile = app(SendTimeProfiler::class)->refreshSubscriber($quiet->refresh());

    expect($profile->source)->toBe(SubscriberSendTimeProfile::SOURCE_ACCOUNT)
        ->and($profile->best_hour)->toBe($accountHour)
        // An account hour is NOT the subscriber's own timezone — it comes from
        // campaign start times, so the row must not claim one.
        ->and($profile->timezone)->toBeNull()
        ->and($profile->confidence)->toBe(0.0);
});

/* ====================================================================== *
 *  The send path
 * ====================================================================== */

it('delays each recipient to their predicted hour', function (): void {
    $campaign = sto_campaign($this->customer, $this->list, ['send_time_optimized' => true]);

    $morning = sto_subscriber($this->customer->id, $this->list->id, 'UTC');
    $evening = sto_subscriber($this->customer->id, $this->list->id, 'UTC');

    sto_profile($morning, hour: 9);   // now is 12:00 UTC ⇒ 09:00 tomorrow
    sto_profile($evening, hour: 18);  // ⇒ 18:00 today

    $rows = collect([(object) ['id' => $morning->id], (object) ['id' => $evening->id]]);
    $delays = app(SendTimeOptimizer::class)->delaysFor($campaign, $rows);

    // 12:00 → 18:00 today = 6 h; 12:00 → 09:00 tomorrow = 21 h.
    expect($delays[$evening->id])->toBe(6 * 3600)
        ->and($delays[$morning->id])->toBe(21 * 3600);
});

it('produces no delay at all when the campaign has not enabled it', function (): void {
    $campaign = sto_campaign($this->customer, $this->list, ['send_time_optimized' => false]);
    $subscriber = sto_subscriber($this->customer->id, $this->list->id, 'UTC');
    sto_profile($subscriber, hour: 9);

    expect(app(SendTimeOptimizer::class)->delaysFor($campaign, collect([(object) ['id' => $subscriber->id]])))
        ->toBe([]);
});

it('leaves a recipient with no prediction on the campaign time', function (): void {
    $campaign = sto_campaign($this->customer, $this->list, ['send_time_optimized' => true]);

    $known = sto_subscriber($this->customer->id, $this->list->id, 'UTC');
    $unknown = sto_subscriber($this->customer->id, $this->list->id, 'UTC');

    sto_profile($known, hour: 18);
    sto_profile($unknown, hour: null, source: SubscriberSendTimeProfile::SOURCE_NONE);

    $delays = app(SendTimeOptimizer::class)->delaysFor(
        $campaign,
        collect([(object) ['id' => $known->id], (object) ['id' => $unknown->id]]),
    );

    expect($delays)->toHaveKey($known->id)
        ->and($delays)->not->toHaveKey($unknown->id);
});

it('never delays a recipient more than a day', function (): void {
    $optimizer = app(SendTimeOptimizer::class);
    $now = Carbon::parse('2026-08-13 12:00:00', 'UTC');

    foreach (range(0, 23) as $hour) {
        expect($optimizer->delayFor($now, $hour, 'UTC'))->toBeLessThanOrEqual(86400);
    }

    // Same clock hour as now ⇒ the next occurrence is tomorrow, not now.
    expect($optimizer->delayFor($now, 12, 'UTC'))->toBe(86400)
        // An unknown timezone is not guessed — no delay.
        ->and($optimizer->delayFor($now, 9, 'Mars/Olympus'))->toBe(0);
});

it('lets the optimizer win over the local-time shift only where it predicts', function (): void {
    Bus::fake();

    $campaign = sto_campaign($this->customer, $this->list, [
        'status' => CampaignStatus::Sending,
        'send_time_optimized' => true,
        'send_at_recipient_local_time' => true,
        'timezone' => 'UTC',
        // 08:00 UTC is the customer's chosen clock time.
        'send_at' => Carbon::parse('2026-08-13 08:00:00', 'UTC'),
        'send_started_at' => Carbon::parse('2026-08-13 08:00:00', 'UTC'),
    ]);

    $predicted = sto_subscriber($this->customer->id, $this->list->id, 'UTC');
    $shiftedOnly = sto_subscriber($this->customer->id, $this->list->id, 'UTC');

    // 18:00 prediction ⇒ 6 h from the frozen 12:00 now.
    sto_profile($predicted, hour: 18);
    // No profile for $shiftedOnly ⇒ RecipientLocalTime's 08:00 UTC shift
    // applies: 12:00 → 08:00 tomorrow = 20 h.
    (new StartCampaignSendJob($campaign->id))->handle(app(CampaignRecipients::class));

    $delays = [];

    Bus::assertBatched(function ($batch) use (&$delays): bool {
        foreach ($batch->jobs as $job) {
            $delays[$job->subscriberId] = $job->delay;
        }

        return true;
    });

    expect($delays[$predicted->id])->toBe(6 * 3600)
        ->and($delays[$shiftedOnly->id])->toBe(20 * 3600);
});

/* ====================================================================== *
 *  Heavy work stays queued
 * ====================================================================== */

it('queues the profile rebuild instead of computing it in the send path', function (): void {
    // Bus::fake() intercepts queued-job dispatches as well as batches, so the
    // assertion lives on the Bus side.
    Bus::fake();

    $campaign = sto_campaign($this->customer, $this->list, [
        'status' => CampaignStatus::Sending,
        'send_time_optimized' => true,
        'send_started_at' => now(),
    ]);

    sto_subscriber($this->customer->id, $this->list->id, 'UTC');

    (new StartCampaignSendJob($campaign->id))->handle(app(CampaignRecipients::class));

    // No profiles exist ⇒ coverage is stale ⇒ exactly one maintenance job.
    Bus::assertDispatched(
        RefreshSendTimeProfilesJob::class,
        fn (RefreshSendTimeProfilesJob $job): bool => $job->customerId === (int) $this->customer->id
            && $job->listId === (int) $this->list->id,
    );

    // …and nothing was written synchronously.
    expect(SubscriberSendTimeProfile::withoutTenancy()->count())->toBe(0);
});

it('queues one job per tenant from the console command', function (): void {
    Queue::fake();

    Customer::factory()->create();

    test()->artisan('send-time:profile')->assertExitCode(0);

    Queue::assertPushed(RefreshSendTimeProfilesJob::class, 2);

    test()->artisan('send-time:profile', ['--list' => 5])->assertExitCode(1);
});

it('rebuilds every profile of a tenant from the queued job', function (): void {
    $engaged = sto_subscriber($this->customer->id, $this->list->id, 'UTC');
    sto_opens($engaged->uid, ['2026-08-12 15:00:00', '2026-08-11 15:00:00', '2026-08-10 15:00:00']);

    $quiet = sto_subscriber($this->customer->id, $this->list->id, 'UTC');

    (new RefreshSendTimeProfilesJob((int) $this->customer->id, (int) $this->list->id))
        ->handle(app(SendTimeProfiler::class));

    $profiles = SubscriberSendTimeProfile::withoutTenancy()->get()->keyBy('subscriber_id');

    expect($profiles)->toHaveCount(2)
        ->and($profiles[$engaged->id]->best_hour)->toBe(15)
        ->and($profiles[$engaged->id]->source)->toBe(SubscriberSendTimeProfile::SOURCE_SUBSCRIBER)
        ->and($profiles[$quiet->id]->source)->toBe(SubscriberSendTimeProfile::SOURCE_NONE);

    // Re-running is idempotent — it updates, never duplicates (unique index).
    (new RefreshSendTimeProfilesJob((int) $this->customer->id, (int) $this->list->id))
        ->handle(app(SendTimeProfiler::class));

    expect(SubscriberSendTimeProfile::withoutTenancy()->count())->toBe(2);
});

/* ====================================================================== *
 *  Tenancy
 * ====================================================================== */

it('never reads another tenants profile into a send', function (): void {
    $campaign = sto_campaign($this->customer, $this->list, ['send_time_optimized' => true]);

    $other = Customer::factory()->create();
    $otherList = EmailList::factory()->create(['customer_id' => $other->id]);
    $foreign = sto_subscriber($other->id, $otherList->id, 'UTC');
    sto_profile($foreign, hour: 18, customerId: (int) $other->id);

    // The foreign subscriber id is fed in deliberately: even then, the
    // customer_id predicate keeps the profile out of this campaign.
    expect(app(SendTimeOptimizer::class)->delaysFor($campaign, collect([(object) ['id' => $foreign->id]])))
        ->toBe([]);

    // …and the policy denies the foreign row outright.
    $profile = SubscriberSendTimeProfile::withoutTenancy()->where('subscriber_id', $foreign->id)->firstOrFail();

    expect($this->customer->can('view', $profile))->toBeFalse()
        ->and($other->can('view', $profile))->toBeTrue();

    // The global scope hides it too.
    app(CurrentCustomer::class)->set($this->customer);
    expect(SubscriberSendTimeProfile::query()->count())->toBe(0);
});

/* ---------------------------------------------------------------------- */

function sto_subscriber(int $customerId, int $listId, ?string $timezone): Subscriber
{
    return Subscriber::factory()->create([
        'customer_id' => $customerId,
        'list_id' => $listId,
        'timezone' => $timezone,
    ]);
}

/** @param list<string> $timestamps UTC */
function sto_opens(string $uid, array $timestamps): void
{
    foreach ($timestamps as $at) {
        CampaignTrackOpen::query()->create([
            'campaign_uid' => 'sto-fixture',
            'subscriber_uid' => $uid,
            'created_at' => Carbon::parse($at, 'UTC'),
        ]);
    }
}

function sto_profile(
    Subscriber $subscriber,
    ?int $hour,
    string $source = SubscriberSendTimeProfile::SOURCE_SUBSCRIBER,
    ?int $customerId = null,
): SubscriberSendTimeProfile {
    $profile = new SubscriberSendTimeProfile;

    $profile->forceFill([
        'customer_id' => $customerId ?? (int) $subscriber->customer_id,
        'subscriber_id' => $subscriber->id,
        'list_id' => $subscriber->list_id,
        'timezone' => $source === SubscriberSendTimeProfile::SOURCE_SUBSCRIBER ? 'UTC' : null,
        'best_hour' => $hour,
        'source' => $hour === null ? SubscriberSendTimeProfile::SOURCE_NONE : $source,
        'events' => 5,
        'computed_at' => now(),
    ])->save();

    return $profile;
}

function sto_campaign(Customer $customer, EmailList $list, array $attributes = []): Campaign
{
    return Campaign::factory()->create(array_merge([
        'customer_id' => $customer->id,
        'email_list_id' => $list->id,
        'subject' => 'STO fixture',
        'content_html' => '<p>Hi <a href="{UNSUBSCRIBE_URL}">bye</a></p>',
    ], $attributes));
}

/**
 * One finished campaign with delivered mail and opens, so
 * CampaignComparisonService::sendTimeAnalysis() has a best hour to report.
 * Returns the hour it will find (the hour the campaign started sending, read
 * in the application timezone).
 */
function sto_seed_account_history(Customer $customer, EmailList $list): int
{
    $startedAt = Carbon::parse('2026-07-01 16:00:00', 'UTC');

    $past = Campaign::factory()->create([
        'customer_id' => $customer->id,
        'email_list_id' => $list->id,
        'status' => CampaignStatus::Sent,
        'send_started_at' => $startedAt,
        'delivery_total' => 10,
        'delivery_sent' => 10,
    ]);

    $url = CampaignTrackUrl::query()->create([
        'campaign_id' => $past->id,
        'campaign_uid' => $past->uid,
        'url' => 'https://example.com/sto',
        'hash' => str_repeat('d', 64),
    ]);

    for ($i = 0; $i < 10; $i++) {
        $subscriber = Subscriber::factory()->create([
            'customer_id' => $customer->id,
            'list_id' => $list->id,
        ]);

        \App\Models\CampaignDeliveryLog::query()->create([
            'campaign_id' => $past->id,
            'subscriber_id' => $subscriber->id,
            'status' => \App\Models\CampaignDeliveryLog::STATUS_SENT,
            'retries' => 0,
        ]);

        if ($i < 6) {
            CampaignTrackOpen::query()->create([
                'campaign_id' => $past->id,
                'campaign_uid' => $past->uid,
                'subscriber_uid' => $subscriber->uid,
                'created_at' => $startedAt,
            ]);
        }

        if ($i < 2) {
            CampaignTrackClick::query()->create([
                'campaign_id' => $past->id,
                'campaign_uid' => $past->uid,
                'subscriber_uid' => $subscriber->uid,
                'url_id' => $url->id,
                'created_at' => $startedAt,
            ]);
        }
    }

    return (int) $startedAt->copy()->setTimezone(config('app.timezone', 'UTC'))->hour;
}
