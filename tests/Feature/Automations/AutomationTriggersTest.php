<?php

declare(strict_types=1);

use App\Enums\AutomationNodeType;
use App\Enums\AutomationRunStatus;
use App\Enums\SubscriberSource;
use App\Enums\SubscriberStatus;
use App\Jobs\Automations\EnrollSubscribersJob;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Campaign;
use App\Models\CampaignDeliveryLog;
use App\Models\CampaignTrackOpen;
use App\Models\ListField;
use App\Models\ListSegment;
use App\Models\Subscriber;
use App\Services\Lists\FieldValueService;
use App\Services\Subscribers\SubscriberTagService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\AutomationFixtures as Fx;

/*
 * §2.4.2 trigger nodes: event-driven (subscribe/unsubscribe/tag/field/
 * campaign-behaviour/form), polled (date, not-opened, segment enter/exit),
 * inbound webhook, manual/bulk enrollment, re-entry rules (F-2.4.2-043).
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-08 10:00:00');
    $this->customer = Fx::actingAsCustomer();
});

afterEach(fn () => Carbon::setTestNow());

function simpleFlow(AutomationNodeType $trigger, array $config = []): array
{
    return [
        Fx::node('t1', $trigger, $config, ['next' => 'x1']),
        Fx::node('x1', AutomationNodeType::LogicExit, [], []),
    ];
}

function runsFor(Automation $automation): int
{
    return AutomationRun::withoutTenancy()->where('automation_id', $automation->id)->count();
}

it('enrolls on subscribe, honoring an optional segment filter (F-2.4.2-005)', function (): void {
    $list = App\Models\EmailList::factory()->create(['customer_id' => $this->customer->id]);

    $segment = ListSegment::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $list->id,
    ]);
    $segment->conditions()->create([
        'customer_id' => $this->customer->id,
        'type' => 'field',
        'operator' => 'contains',
        'value' => 'anna',
        'sort_order' => 1,
    ]);

    $automation = Fx::automation($this->customer, simpleFlow(
        AutomationNodeType::TriggerSubscribed,
        ['list_id' => $list->id, 'segment_id' => $segment->id],
    ));

    Subscriber::factory()->create(['list_id' => $list->id, 'customer_id' => $this->customer->id, 'email' => 'anna@x.test']);
    Subscriber::factory()->create(['list_id' => $list->id, 'customer_id' => $this->customer->id, 'email' => 'bob@x.test']);

    expect(runsFor($automation))->toBe(1);
});

it('enrolls on unsubscribe (F-2.4.2-006)', function (): void {
    [$list, $subscriber] = Fx::listWithSubscriber($this->customer);

    $automation = Fx::automation($this->customer, simpleFlow(
        AutomationNodeType::TriggerUnsubscribed,
        ['list_id' => $list->id],
    ));

    $subscriber->status = SubscriberStatus::Unsubscribed;
    $subscriber->save();

    expect(runsFor($automation))->toBe(1);
});

it('enrolls on tag added / removed with tag filter (F-2.4.2-007)', function (): void {
    [, $subscriber] = Fx::listWithSubscriber($this->customer);

    $added = Fx::automation($this->customer, simpleFlow(AutomationNodeType::TriggerTagAdded, ['tag' => 'vip']));
    $removed = Fx::automation($this->customer, simpleFlow(AutomationNodeType::TriggerTagRemoved, ['tag' => 'vip']));

    $tags = app(SubscriberTagService::class);

    $tags->attach($subscriber, 'other');
    expect(runsFor($added))->toBe(0);

    $tags->attach($subscriber, 'VIP'); // normalized lowercase
    expect(runsFor($added))->toBe(1);

    $tags->detach($subscriber, 'vip');
    expect(runsFor($removed))->toBe(1);
});

it('enrolls when a custom field changes to a matching value (F-2.4.2-008)', function (): void {
    [$list, $subscriber] = Fx::listWithSubscriber($this->customer);

    $field = ListField::factory()->create([
        'list_id' => $list->id,
        'customer_id' => $this->customer->id,
        'tag' => 'PLAN',
    ]);

    $automation = Fx::automation($this->customer, simpleFlow(AutomationNodeType::TriggerFieldChanged, [
        'list_field_id' => $field->id,
        'operator' => 'is',
        'value' => 'gold',
    ]));

    $values = app(FieldValueService::class);

    $values->set($subscriber, $field, 'silver');
    expect(runsFor($automation))->toBe(0);

    $values->set($subscriber, $field, 'gold');
    expect(runsFor($automation))->toBe(1);
});

it('enrolls on campaign open events (F-2.4.2-009)', function (): void {
    [$list, $subscriber] = Fx::listWithSubscriber($this->customer);
    $campaign = Campaign::factory()->create(['customer_id' => $this->customer->id, 'email_list_id' => $list->id]);

    $automation = Fx::automation($this->customer, simpleFlow(AutomationNodeType::TriggerCampaignOpened, [
        'campaign_id' => $campaign->id,
    ]));

    CampaignTrackOpen::query()->create([
        'campaign_id' => $campaign->id,
        'campaign_uid' => $campaign->uid,
        'subscriber_uid' => $subscriber->uid,
        'ip' => '127.0.0.1',
    ]);

    expect(runsFor($automation))->toBe(1);
});

it('polls did-NOT-open-after-N-days (F-2.4.2-010)', function (): void {
    [$list, $subscriber] = Fx::listWithSubscriber($this->customer);
    $opener = Subscriber::factory()->create(['list_id' => $list->id, 'customer_id' => $this->customer->id, 'email' => 'opener@x.test']);
    $campaign = Campaign::factory()->create(['customer_id' => $this->customer->id, 'email_list_id' => $list->id]);

    foreach ([$subscriber, $opener] as $recipient) {
        CampaignDeliveryLog::query()->create([
            'campaign_id' => $campaign->id,
            'subscriber_id' => $recipient->id,
            'status' => CampaignDeliveryLog::STATUS_SENT,
            'created_at' => Carbon::now()->subDays(4),
            'updated_at' => Carbon::now()->subDays(4),
        ]);
    }

    CampaignTrackOpen::query()->create([
        'campaign_id' => $campaign->id,
        'campaign_uid' => $campaign->uid,
        'subscriber_uid' => $opener->uid,
        'ip' => '127.0.0.1',
    ]);

    $automation = Fx::automation($this->customer, simpleFlow(AutomationNodeType::TriggerCampaignNotOpened, [
        'campaign_id' => $campaign->id,
        'days' => 3,
    ]));

    $this->artisan('automations:tick')->assertExitCode(0);

    expect(runsFor($automation))->toBe(1)
        ->and(AutomationRun::withoutTenancy()->where('automation_id', $automation->id)->value('subscriber_id'))
        ->toBe($subscriber->id);

    // Second tick: no duplicates (previous run blocks this polled trigger).
    $this->artisan('automations:tick')->assertExitCode(0);
    expect(runsFor($automation))->toBe(1);
});

it('enrolls on form-sourced subscriptions (F-2.4.2-011)', function (): void {
    $list = App\Models\EmailList::factory()->create(['customer_id' => $this->customer->id]);

    $automation = Fx::automation($this->customer, simpleFlow(AutomationNodeType::TriggerFormSubmitted, [
        'list_id' => $list->id,
    ]));

    Subscriber::factory()->create([
        'list_id' => $list->id,
        'customer_id' => $this->customer->id,
        'source' => SubscriberSource::Form,
    ]);

    expect(runsFor($automation))->toBe(1);
});

it('polls date-based triggers on the stored date field (F-2.4.2-013)', function (): void {
    [$list, $subscriber] = Fx::listWithSubscriber($this->customer);

    $field = ListField::factory()->create([
        'list_id' => $list->id,
        'customer_id' => $this->customer->id,
        'tag' => 'BIRTHDAY',
        'type' => 'date',
    ]);

    app(FieldValueService::class)->set($subscriber, $field, '1990-08-08');

    $automation = Fx::automation($this->customer, simpleFlow(AutomationNodeType::TriggerDate, [
        'list_field_id' => $field->id,
        'offset_days' => 0,
        'annual' => true,
    ]), ['reentry_mode' => Automation::REENTRY_MULTIPLE]);

    $this->artisan('automations:tick')->assertExitCode(0);
    expect(runsFor($automation))->toBe(1);

    // Same occurrence never double-fires.
    $this->artisan('automations:tick')->assertExitCode(0);
    expect(runsFor($automation))->toBe(1);
});

it('diffs segment membership for enters/exits triggers (F-2.4.2-018)', function (): void {
    [$list, $existing] = Fx::listWithSubscriber($this->customer, 'gold-already@x.test');

    $field = ListField::factory()->create([
        'list_id' => $list->id,
        'customer_id' => $this->customer->id,
        'tag' => 'TIER',
    ]);

    $segment = ListSegment::factory()->create(['customer_id' => $this->customer->id, 'list_id' => $list->id]);
    $segment->conditions()->create([
        'customer_id' => $this->customer->id,
        'type' => 'field',
        'list_field_id' => $field->id,
        'operator' => 'is',
        'value' => 'gold',
        'sort_order' => 1,
    ]);

    $values = app(FieldValueService::class);
    $values->set($existing, $field, 'gold'); // member BEFORE activation → baseline only

    $enters = Fx::automation($this->customer, simpleFlow(AutomationNodeType::TriggerSegmentEntered, ['segment_id' => $segment->id]));
    $exits = Fx::automation($this->customer, simpleFlow(AutomationNodeType::TriggerSegmentExited, ['segment_id' => $segment->id]));

    $this->artisan('automations:tick')->assertExitCode(0);
    expect(runsFor($enters))->toBe(0)->and(runsFor($exits))->toBe(0);

    // A subscriber becomes gold → enters.
    $joiner = Subscriber::factory()->create(['list_id' => $list->id, 'customer_id' => $this->customer->id, 'email' => 'joiner@x.test']);
    $values->set($joiner, $field, 'gold');

    $this->artisan('automations:tick')->assertExitCode(0);
    expect(runsFor($enters))->toBe(1)->and(runsFor($exits))->toBe(0);

    // The original member loses gold → exits.
    $values->set($existing, $field, 'silver');

    $this->artisan('automations:tick')->assertExitCode(0);
    expect(runsFor($enters))->toBe(1)->and(runsFor($exits))->toBe(1);
});

it('accepts inbound webhook events with the right token (F-2.4.2-014)', function (): void {
    [$list, $subscriber] = Fx::listWithSubscriber($this->customer);
    $token = Str::random(32);

    $automation = Fx::automation($this->customer, simpleFlow(AutomationNodeType::TriggerWebhook, [
        'list_id' => $list->id,
        'token' => $token,
    ]));

    $this->postJson(route('automations.hook', ['uid' => $automation->uid, 'token' => 'wrong']), [
        'email' => $subscriber->email,
    ])->assertNotFound();

    $this->postJson(route('automations.hook', ['uid' => $automation->uid, 'token' => $token]), [
        'email' => $subscriber->email,
        'plan' => 'pro',
    ])->assertOk()->assertJson(['ok' => true, 'entered' => true]);

    $run = AutomationRun::withoutTenancy()->where('automation_id', $automation->id)->first();
    expect($run)->not->toBeNull()
        ->and(data_get($run->payload, 'webhook.plan'))->toBe('pro');
});

it('bulk-enrolls a list through the queued job (F-2.4.2-019)', function (): void {
    $list = App\Models\EmailList::factory()->create(['customer_id' => $this->customer->id]);
    Subscriber::factory()->count(3)->create(['list_id' => $list->id, 'customer_id' => $this->customer->id]);

    $automation = Fx::automation($this->customer, simpleFlow(AutomationNodeType::TriggerManual));
    // Manual trigger enrolls nobody automatically.
    expect(runsFor($automation))->toBe(0);

    EnrollSubscribersJob::dispatch($automation->id, $list->id, null, [], (string) Str::uuid());

    expect(runsFor($automation))->toBe(3);
});

it('enforces re-entry rules: once / multiple / cooldown (F-2.4.2-043)', function (): void {
    [$list, $subscriber] = Fx::listWithSubscriber($this->customer);

    $enroller = app(App\Services\Automations\Enroller::class);

    $once = Fx::automation($this->customer, simpleFlow(AutomationNodeType::TriggerManual));
    expect($enroller->enter($once, $subscriber))->not->toBeNull();
    // First run completed instantly (exit node); once = blocked forever.
    expect($enroller->enter($once, $subscriber))->toBeNull();

    $multiple = Fx::automation($this->customer, simpleFlow(AutomationNodeType::TriggerManual), [
        'reentry_mode' => Automation::REENTRY_MULTIPLE,
    ]);
    expect($enroller->enter($multiple, $subscriber))->not->toBeNull()
        ->and($enroller->enter($multiple, $subscriber))->not->toBeNull()
        ->and(runsFor($multiple))->toBe(2);

    $cooldown = Fx::automation($this->customer, simpleFlow(AutomationNodeType::TriggerManual), [
        'reentry_mode' => Automation::REENTRY_COOLDOWN,
        'reentry_cooldown_minutes' => 60,
    ]);
    expect($enroller->enter($cooldown, $subscriber))->not->toBeNull()
        ->and($enroller->enter($cooldown, $subscriber))->toBeNull();

    Carbon::setTestNow(Carbon::now()->addMinutes(61));
    expect($enroller->enter($cooldown, $subscriber))->not->toBeNull()
        ->and(runsFor($cooldown))->toBe(2);
});

it('blocks concurrent duplicate active runs even with re-entry allowed', function (): void {
    [$list, $subscriber] = Fx::listWithSubscriber($this->customer);

    $automation = Fx::automation($this->customer, [
        Fx::node('t1', AutomationNodeType::TriggerManual, [], ['next' => 'd1']),
        Fx::node('d1', AutomationNodeType::LogicDelay, ['mode' => 'duration', 'value' => 1, 'unit' => 'days'], []),
    ], ['reentry_mode' => Automation::REENTRY_MULTIPLE]);

    $enroller = app(App\Services\Automations\Enroller::class);

    expect($enroller->enter($automation, $subscriber))->not->toBeNull()
        ->and($enroller->enter($automation, $subscriber))->toBeNull(); // still active on the delay
});
