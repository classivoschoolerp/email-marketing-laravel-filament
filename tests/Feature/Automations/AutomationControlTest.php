<?php

declare(strict_types=1);

use App\Enums\AutomationNodeType;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationStatus;
use App\Filament\Customer\Resources\AutomationResource\Pages\ListAutomations;
use App\Jobs\Automations\AdvanceAutomationRunJob;
use App\Jobs\Automations\AutomationSendJob;
use App\Models\Automation;
use App\Models\AutomationEmailSend;
use App\Models\AutomationRun;
use App\Services\Automations\Enroller;
use App\Services\Automations\GraphPublisher;
use App\Services\Subscribers\SubscriberTagService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\AutomationFixtures as Fx;

/*
 * Engine control invariants: idempotent advancement (tick twice = no double
 * action), pause blocks advancement, publish validation (F-2.4.2-002),
 * versioning, plus the §2.4.1 index behaviours (filters, duplicate,
 * activate/pause, D-28 bulks, blueprint gallery).
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-08 10:00:00');
    $this->customer = Fx::actingAsCustomer();
    [$this->list, $this->subscriber] = Fx::listWithSubscriber($this->customer);
});

afterEach(fn () => Carbon::setTestNow());

function delayedTagFlow(): Automation
{
    return Fx::automation(test()->customer, [
        Fx::node('t1', AutomationNodeType::TriggerManual, [], ['next' => 'd1']),
        Fx::node('d1', AutomationNodeType::LogicDelay, ['mode' => 'duration', 'value' => 1, 'unit' => 'hours'], ['next' => 'a1']),
        Fx::node('a1', AutomationNodeType::ActionAddTag, ['tag' => 'done'], []),
    ]);
}

it('never double-executes a wave: ticking twice performs the action once', function (): void {
    $automation = delayedTagFlow();
    $run = app(Enroller::class)->enter($automation, $this->subscriber);

    Carbon::setTestNow('2026-08-08 11:01:00');
    $this->artisan('automations:tick')->assertExitCode(0);
    $this->artisan('automations:tick')->assertExitCode(0);

    expect($run->refresh()->status)->toBe(AutomationRunStatus::Completed)
        ->and(App\Models\SubscriberTag::withoutTenancy()
            ->where('subscriber_id', $this->subscriber->id)
            ->where('name', 'done')->count())->toBe(1)
        ->and($automation->refresh()->completed_count)->toBe(1);
});

it('drops a replayed advance job on the idempotency key', function (): void {
    $automation = delayedTagFlow();
    $run = app(Enroller::class)->enter($automation, $this->subscriber);
    $run->refresh();

    $wake = Carbon::parse($run->wake_at)->getTimestamp();

    // Pre-claim the key the way a previously-executed wave would have.
    DB::table('job_idempotency_keys')->insert([
        'key' => 'automation-advance:'.$run->id.':'.$run->current_node_id.':'.$wake,
        'created_at' => now(),
    ]);

    Carbon::setTestNow('2026-08-08 11:01:00');
    AdvanceAutomationRunJob::dispatch($run->id, (int) $run->current_node_id, $wake);

    // Dropped silently — the run did not move.
    expect($run->refresh()->currentNode->uid)->toBe('d1');
});

it('no-ops a stale advance job whose run already moved on', function (): void {
    $automation = delayedTagFlow();
    $run = app(Enroller::class)->enter($automation, $this->subscriber);
    $run->refresh();

    $triggerNodeId = $automation->nodes()->where('uid', 't1')->value('id');

    AdvanceAutomationRunJob::dispatch($run->id, (int) $triggerNodeId, Carbon::now()->getTimestamp());

    expect($run->refresh()->currentNode->uid)->toBe('d1')
        ->and($run->status)->toBe(AutomationRunStatus::Active);
});

it('sends an automation email exactly once per (run, node)', function (): void {
    $driver = Fx::fakeDeliveryServer($this->customer);

    $automation = Fx::automation($this->customer, [
        Fx::node('t1', AutomationNodeType::TriggerManual, [], ['next' => 'e1']),
        Fx::node('e1', AutomationNodeType::ActionSendEmail, [
            'mode' => 'inline', 'subject' => 'Once', 'content_html' => '<p>Hi</p>', 'track' => false,
        ], []),
    ]);

    $run = app(Enroller::class)->enter($automation, $this->subscriber);
    $node = $automation->nodes()->where('uid', 'e1')->first();

    // Replay the send job — key + unique claim row both block.
    AutomationSendJob::dispatch($run->id, (int) $node->id);
    AutomationSendJob::dispatch($run->id, (int) $node->id);

    expect($driver->sent)->toHaveCount(1)
        ->and(AutomationEmailSend::withoutTenancy()->where('run_id', $run->id)->count())->toBe(1);
});

it('blocks advancement while paused and resumes after reactivation', function (): void {
    $automation = delayedTagFlow();
    $run = app(Enroller::class)->enter($automation, $this->subscriber);

    $automation->forceFill(['status' => AutomationStatus::Paused])->save();

    Carbon::setTestNow('2026-08-08 11:01:00');
    $this->artisan('automations:tick')->assertExitCode(0);

    expect($run->refresh()->currentNode->uid)->toBe('d1')
        ->and($run->status)->toBe(AutomationRunStatus::Active);

    // Paused automations also refuse new enrollments.
    $other = App\Models\Subscriber::factory()->create(['list_id' => $this->list->id, 'customer_id' => $this->customer->id]);
    expect(app(Enroller::class)->enter($automation, $other))->toBeNull();

    $automation->forceFill(['status' => AutomationStatus::Active])->save();
    $this->artisan('automations:tick')->assertExitCode(0);

    expect($run->refresh()->status)->toBe(AutomationRunStatus::Completed)
        ->and(app(SubscriberTagService::class)->has($this->subscriber, 'done'))->toBeTrue();
});

it('validates the graph on publish: one trigger, reachable nodes, no orphans (F-2.4.2-002)', function (): void {
    $automation = Automation::factory()->create([
        'customer_id' => $this->customer->id,
        'canvas' => ['nodes' => [
            Fx::node('t1', AutomationNodeType::TriggerManual, [], ['next' => 'missing']),
            Fx::node('t2', AutomationNodeType::TriggerUnsubscribed, [], []),
            Fx::node('orphan', AutomationNodeType::LogicExit, [], []),
        ]],
    ]);

    try {
        app(GraphPublisher::class)->publish($automation);
        $this->fail('expected ValidationException');
    } catch (ValidationException $exception) {
        $errors = implode(' ', $exception->errors()['canvas']);

        expect($errors)->toContain('exactly one trigger')
            ->and($errors)->toContain('missing');
    }

    expect($automation->refresh()->version)->toBe(0);
});

it('increments the version and snapshots the canvas on each publish (F-2.4.2-002)', function (): void {
    $automation = delayedTagFlow();

    expect($automation->version)->toBe(1)
        ->and($automation->versions()->count())->toBe(1);

    $canvas = $automation->canvas;
    $canvas['nodes'][2]['config']['tag'] = 'renamed';
    app(GraphPublisher::class)->publish($automation, $canvas);

    $automation->refresh();
    expect($automation->version)->toBe(2)
        ->and($automation->versions()->count())->toBe(2)
        ->and($automation->nodes()->where('uid', 'a1')->value('config'))->toContain('renamed');
});

it('republish keeps node identity and exits runs parked on removed nodes', function (): void {
    $automation = delayedTagFlow();
    $run = app(Enroller::class)->enter($automation, $this->subscriber);

    $nodeIdBefore = $automation->nodes()->where('uid', 'd1')->value('id');

    // v2 drops the delay node entirely.
    $canvas = ['nodes' => [
        Fx::node('t1', AutomationNodeType::TriggerManual, [], ['next' => 'a1']),
        Fx::node('a1', AutomationNodeType::ActionAddTag, ['tag' => 'done'], []),
    ]];
    app(GraphPublisher::class)->publish($automation, $canvas);

    expect(AutomationRun::withoutTenancy()->find($run->id)->status)->toBe(AutomationRunStatus::Exited)
        ->and(AutomationRun::withoutTenancy()->find($run->id)->exit_reason)->toBe('node_removed')
        ->and($automation->nodes()->where('uid', 'd1')->exists())->toBeFalse()
        ->and($automation->nodes()->where('uid', 'a1')->exists())->toBeTrue();
});

it('renders the index with counters and supports duplicate + pause (F-2.4.1-001/003)', function (): void {
    $automation = delayedTagFlow();
    app(Enroller::class)->enter($automation, $this->subscriber);

    Livewire::test(ListAutomations::class)
        ->assertCanSeeTableRecords([$automation])
        ->callTableAction('pause', $automation)
        ->callTableAction('duplicate', $automation);

    expect($automation->refresh()->status)->toBe(AutomationStatus::Paused);

    $copy = Automation::query()->where('name', 'like', 'Copy of%')->first();
    expect($copy)->not->toBeNull()
        ->and($copy->status)->toBe(AutomationStatus::Draft)
        ->and($copy->version)->toBe(0)
        ->and($copy->entered_count)->toBe(0)
        ->and($copy->uid)->not->toBe($automation->uid);
});

it('filters the index by status and trigger type (F-2.4.1-002)', function (): void {
    $active = delayedTagFlow();
    $draft = Automation::factory()->create(['customer_id' => $this->customer->id, 'trigger_type' => AutomationNodeType::TriggerUnsubscribed->value]);

    Livewire::test(ListAutomations::class)
        ->filterTable('status', AutomationStatus::Active->value)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$draft])
        ->resetTableFilters()
        ->filterTable('trigger_type', AutomationNodeType::TriggerUnsubscribed->value)
        ->assertCanSeeTableRecords([$draft])
        ->assertCanNotSeeTableRecords([$active]);
});

it('pauses only active rows in bulk (D-28)', function (): void {
    $active = delayedTagFlow();
    $draft = Automation::factory()->create(['customer_id' => $this->customer->id]);

    Livewire::test(ListAutomations::class)
        ->callTableBulkAction('pause', [$active, $draft]);

    expect($active->refresh()->status)->toBe(AutomationStatus::Paused)
        ->and($draft->refresh()->status)->toBe(AutomationStatus::Draft);
});

it('instantiates a blueprint as a draft (F-2.4.1-004)', function (): void {
    Livewire::test(ListAutomations::class)
        ->callAction('fromBlueprint', ['blueprint' => 'welcome_series']);

    $automation = Automation::query()->first();

    expect($automation)->not->toBeNull()
        ->and($automation->status)->toBe(AutomationStatus::Draft)
        ->and(collect($automation->canvas['nodes'])->pluck('type'))
        ->toContain(AutomationNodeType::TriggerSubscribed->value)
        ->toContain(AutomationNodeType::ActionSendEmail->value);
});
