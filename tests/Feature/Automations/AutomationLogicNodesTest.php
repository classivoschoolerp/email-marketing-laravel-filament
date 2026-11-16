<?php

declare(strict_types=1);

use App\Enums\AutomationNodeType;
use App\Enums\AutomationRunStatus;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\AutomationRunLog;
use App\Models\ListField;
use App\Services\Automations\Enroller;
use App\Services\Lists\FieldValueService;
use App\Services\Subscribers\SubscriberTagService;
use Illuminate\Support\Carbon;
use Tests\Support\AutomationFixtures as Fx;

/*
 * §2.4.2 logic nodes: delay variants (F-2.4.2-034), wait-for-event with
 * timeout branch (035), multi-branch split (037), percentage split (038),
 * jump + loop guard (041), frequency cap / quiet hours (042).
 */

beforeEach(function (): void {
    // 2026-08-08 is a Saturday.
    Carbon::setTestNow('2026-08-08 10:00:00');
    $this->customer = Fx::actingAsCustomer();
    [$this->list, $this->subscriber] = Fx::listWithSubscriber($this->customer);
    $this->enroller = app(Enroller::class);
});

afterEach(fn () => Carbon::setTestNow());

it('sleeps until day-of-week + time-of-day (F-2.4.2-034)', function (): void {
    $automation = Fx::automation($this->customer, [
        Fx::node('t1', AutomationNodeType::TriggerManual, [], ['next' => 'd1']),
        Fx::node('d1', AutomationNodeType::LogicDelay, ['mode' => 'until_time', 'weekday' => 1, 'time' => '09:00'], ['next' => 'x1']),
        Fx::node('x1', AutomationNodeType::LogicExit, [], []),
    ]);

    $run = $this->enroller->enter($automation, $this->subscriber);

    expect(Carbon::parse($run->refresh()->wake_at)->toDateTimeString())->toBe('2026-08-10 09:00:00');
});

it('sleeps until a specific date (F-2.4.2-034)', function (): void {
    $automation = Fx::automation($this->customer, [
        Fx::node('t1', AutomationNodeType::TriggerManual, [], ['next' => 'd1']),
        Fx::node('d1', AutomationNodeType::LogicDelay, ['mode' => 'until_date', 'date' => '2026-09-01 08:00'], ['next' => 'x1']),
        Fx::node('x1', AutomationNodeType::LogicExit, [], []),
    ]);

    $run = $this->enroller->enter($automation, $this->subscriber);

    expect(Carbon::parse($run->refresh()->wake_at)->toDateTimeString())->toBe('2026-09-01 08:00:00');
});

it('waits for an event and takes the event branch when it happens (F-2.4.2-035)', function (): void {
    $automation = Fx::automation($this->customer, [
        Fx::node('t1', AutomationNodeType::TriggerManual, [], ['next' => 'w1']),
        Fx::node('w1', AutomationNodeType::LogicWaitEvent, [
            'event' => 'tag_added', 'tag' => 'vip',
            'timeout_value' => 3, 'timeout_unit' => 'days',
        ], ['event' => 'e1', 'timeout' => 'o1']),
        Fx::node('e1', AutomationNodeType::ActionAddTag, ['tag' => 'engaged'], []),
        Fx::node('o1', AutomationNodeType::ActionAddTag, ['tag' => 'timed-out'], []),
    ]);

    $run = $this->enroller->enter($automation, $this->subscriber);

    expect($run->refresh()->waiting_for)->toBe('tag_added')
        ->and(Carbon::parse($run->wake_at)->toDateTimeString())->toBe('2026-08-11 10:00:00');

    $tags = app(SubscriberTagService::class);

    // Non-matching tag: still waiting.
    $tags->attach($this->subscriber, 'random');
    expect($run->refresh()->waiting_for)->toBe('tag_added');

    // Matching tag: event branch taken.
    $tags->attach($this->subscriber, 'vip');
    expect($run->refresh()->status)->toBe(AutomationRunStatus::Completed)
        ->and($tags->has($this->subscriber, 'engaged'))->toBeTrue()
        ->and($tags->has($this->subscriber, 'timed-out'))->toBeFalse();
});

it('takes the timeout branch when the event never happens (F-2.4.2-035)', function (): void {
    $automation = Fx::automation($this->customer, [
        Fx::node('t1', AutomationNodeType::TriggerManual, [], ['next' => 'w1']),
        Fx::node('w1', AutomationNodeType::LogicWaitEvent, [
            'event' => 'campaign_opened',
            'timeout_value' => 2, 'timeout_unit' => 'days',
        ], ['event' => 'e1', 'timeout' => 'o1']),
        Fx::node('e1', AutomationNodeType::ActionAddTag, ['tag' => 'engaged'], []),
        Fx::node('o1', AutomationNodeType::ActionAddTag, ['tag' => 'timed-out'], []),
    ]);

    $run = $this->enroller->enter($automation, $this->subscriber);

    Carbon::setTestNow('2026-08-10 10:01:00');
    $this->artisan('automations:tick')->assertExitCode(0);

    expect($run->refresh()->status)->toBe(AutomationRunStatus::Completed)
        ->and(app(SubscriberTagService::class)->has($this->subscriber, 'timed-out'))->toBeTrue();
});

it('switches on a custom field value with a default branch (F-2.4.2-037)', function (): void {
    $field = ListField::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->customer->id,
        'tag' => 'PLAN',
    ]);

    app(FieldValueService::class)->set($this->subscriber, $field, 'Pro');

    $automation = Fx::automation($this->customer, [
        Fx::node('t1', AutomationNodeType::TriggerManual, [], ['next' => 's1']),
        Fx::node('s1', AutomationNodeType::LogicSplit, [
            'list_field_id' => $field->id,
            'cases' => [['value' => 'free'], ['value' => 'pro']],
        ], ['case:0' => 'c0', 'case:1' => 'c1', 'default' => 'cd']),
        Fx::node('c0', AutomationNodeType::ActionAddTag, ['tag' => 'free-plan'], []),
        Fx::node('c1', AutomationNodeType::ActionAddTag, ['tag' => 'pro-plan'], []),
        Fx::node('cd', AutomationNodeType::ActionAddTag, ['tag' => 'no-plan'], []),
    ]);

    $this->enroller->enter($automation, $this->subscriber);

    $tags = app(SubscriberTagService::class);
    expect($tags->has($this->subscriber, 'pro-plan'))->toBeTrue()
        ->and($tags->has($this->subscriber, 'free-plan'))->toBeFalse()
        ->and($tags->has($this->subscriber, 'no-plan'))->toBeFalse();
});

it('splits deterministically by percentage (F-2.4.2-038)', function (): void {
    $subscribers = App\Models\Subscriber::factory()->count(20)->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->customer->id,
    ]);

    $automation = Fx::automation($this->customer, [
        Fx::node('t1', AutomationNodeType::TriggerManual, [], ['next' => 'p1']),
        Fx::node('p1', AutomationNodeType::LogicPercentage, [
            'variants' => [['percent' => 50], ['percent' => 50]],
        ], ['a' => 'ba', 'b' => 'bb']),
        Fx::node('ba', AutomationNodeType::ActionAddTag, ['tag' => 'variant-a'], []),
        Fx::node('bb', AutomationNodeType::ActionAddTag, ['tag' => 'variant-b'], []),
    ]);

    foreach ($subscribers as $subscriber) {
        $this->enroller->enter($automation, $subscriber);
    }

    $branchLogs = AutomationRunLog::query()
        ->where('automation_id', $automation->id)
        ->where('node_uid', 'p1')
        ->where('action', AutomationRunLog::ACTION_EXECUTED)
        ->get();

    $branches = $branchLogs->map(fn (AutomationRunLog $log): string => (string) data_get($log->detail, 'branch'))->unique();

    // Both branches occur across 20 contacts (50/50 hash split).
    expect($branches->sort()->values()->all())->toBe(['a', 'b']);
});

it('jumps back to an earlier node and yields at the step limit (F-2.4.2-041)', function (): void {
    config(['emp-automations.max_steps_per_advance' => 10]);

    $automation = Fx::automation($this->customer, [
        Fx::node('t1', AutomationNodeType::TriggerManual, [], ['next' => 'a1']),
        Fx::node('a1', AutomationNodeType::ActionAddTag, ['tag' => 'looped'], ['next' => 'j1']),
        Fx::node('j1', AutomationNodeType::LogicJump, ['target' => 'node', 'target_node_uid' => 'a1'], []),
    ]);

    $run = $this->enroller->enter($automation, $this->subscriber);
    $run->refresh();

    // Still active (yielded), parked ~1 minute out instead of looping forever.
    expect($run->status)->toBe(AutomationRunStatus::Active)
        ->and($run->wake_at)->not->toBeNull()
        ->and(AutomationRunLog::query()->where('run_id', $run->id)
            ->where('detail', 'like', '%yielded%')->exists())->toBeTrue();
});

it('jumps to another automation and exits the current one (F-2.4.2-041)', function (): void {
    $target = Fx::automation($this->customer, [
        Fx::node('t1', AutomationNodeType::TriggerManual, [], ['next' => 'g1']),
        Fx::node('g1', AutomationNodeType::ActionAddTag, ['tag' => 'landed'], []),
    ]);

    $automation = Fx::automation($this->customer, [
        Fx::node('t1', AutomationNodeType::TriggerManual, [], ['next' => 'j1']),
        Fx::node('j1', AutomationNodeType::LogicJump, ['target' => 'automation', 'target_automation_id' => $target->id], []),
    ]);

    $run = $this->enroller->enter($automation, $this->subscriber);

    expect($run->refresh()->status)->toBe(AutomationRunStatus::Exited)
        ->and($run->exit_reason)->toBe('jumped')
        ->and(AutomationRun::withoutTenancy()->where('automation_id', $target->id)->exists())->toBeTrue()
        ->and(app(SubscriberTagService::class)->has($this->subscriber, 'landed'))->toBeTrue();
});

it('holds contacts inside quiet hours until the window ends (F-2.4.2-042)', function (): void {
    Carbon::setTestNow('2026-08-08 23:30:00'); // inside 22:00–08:00 UTC

    $automation = Fx::automation($this->customer, [
        Fx::node('t1', AutomationNodeType::TriggerManual, [], ['next' => 'g1']),
        Fx::node('g1', AutomationNodeType::LogicFrequencyGuard, [
            'quiet_enabled' => true, 'quiet_start' => '22:00', 'quiet_end' => '08:00',
            'cap_enabled' => false,
        ], ['next' => 'x1']),
        Fx::node('x1', AutomationNodeType::LogicExit, [], []),
    ]);

    $run = $this->enroller->enter($automation, $this->subscriber);
    $run->refresh();

    expect($run->status)->toBe(AutomationRunStatus::Active)
        ->and(Carbon::parse($run->wake_at)->toDateTimeString())->toBe('2026-08-09 08:00:00');

    // After the window the guard passes through.
    Carbon::setTestNow('2026-08-09 08:01:00');
    $this->artisan('automations:tick')->assertExitCode(0);

    expect($run->refresh()->status)->toBe(AutomationRunStatus::Exited);
});
