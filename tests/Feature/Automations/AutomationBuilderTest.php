<?php

declare(strict_types=1);

use App\Enums\AutomationNodeType;
use App\Filament\Customer\Resources\AutomationResource\Pages\BuildAutomation;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\SubscriberTag;
use App\Services\Automations\Graph;
use App\Services\Automations\SimulateRunner;
use App\Services\Subscribers\SubscriberTagService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Support\AutomationFixtures as Fx;

/*
 * §2.4.2 builder page: add-node-between / edit / delete on the canvas,
 * publish from the page, live counts (F-2.4.2-004) and the test/simulate
 * run (F-2.4.2-003 — conditions evaluate, side effects don't).
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-08 10:00:00');
    $this->customer = Fx::actingAsCustomer();
    [$this->list, $this->subscriber] = Fx::listWithSubscriber($this->customer);
});

afterEach(fn () => Carbon::setTestNow());

function draftAutomation(): Automation
{
    return Automation::factory()->create([
        'customer_id' => test()->customer->id,
        'canvas' => ['nodes' => [
            Fx::node('t1', AutomationNodeType::TriggerManual, [], []),
        ]],
    ]);
}

it('adds a node between the trigger and an existing child, then deletes it', function (): void {
    $automation = draftAutomation();

    $page = Livewire::test(BuildAutomation::class, ['record' => $automation->getRouteKey()]);

    // Add an exit node after the trigger.
    $page->callAction('addNode', [
        'type' => AutomationNodeType::LogicExit->value,
    ], ['parent' => 't1', 'branch' => 'next']);

    $automation->refresh();
    $nodes = collect($automation->canvas['nodes']);
    expect($nodes)->toHaveCount(2);

    $exitUid = $nodes->firstWhere('type', AutomationNodeType::LogicExit->value)['uid'];
    expect($nodes->firstWhere('uid', 't1')['edges']['next'])->toBe($exitUid);

    // Insert a tag node BETWEEN trigger and exit.
    $page->callAction('addNode', [
        'type' => AutomationNodeType::ActionAddTag->value,
        'config' => ['tag' => 'inserted'],
    ], ['parent' => 't1', 'branch' => 'next']);

    $automation->refresh();
    $nodes = collect($automation->canvas['nodes']);
    $tagNode = $nodes->firstWhere('type', AutomationNodeType::ActionAddTag->value);

    expect($nodes)->toHaveCount(3)
        ->and($nodes->firstWhere('uid', 't1')['edges']['next'])->toBe($tagNode['uid'])
        ->and($tagNode['edges']['next'])->toBe($exitUid)
        ->and($tagNode['config']['tag'])->toBe('inserted');

    // Edit the node.
    $page->callAction('editNode', [
        'config' => ['tag' => 'renamed'],
    ], ['uid' => $tagNode['uid']]);

    $automation->refresh();
    expect(collect($automation->canvas['nodes'])->firstWhere('uid', $tagNode['uid'])['config']['tag'])->toBe('renamed');

    // Delete it — the trigger splices back to the exit node.
    $page->callAction('deleteNode', arguments: ['uid' => $tagNode['uid']]);

    $automation->refresh();
    $nodes = collect($automation->canvas['nodes']);
    expect($nodes)->toHaveCount(2)
        ->and($nodes->firstWhere('uid', 't1')['edges']['next'])->toBe($exitUid);
});

it('publishes from the builder and reports validation failures', function (): void {
    $automation = draftAutomation();

    // Valid publish.
    Livewire::test(BuildAutomation::class, ['record' => $automation->getRouteKey()])
        ->callAction('addNode', ['type' => AutomationNodeType::LogicExit->value], ['parent' => 't1', 'branch' => 'next'])
        ->callAction('publish');

    expect($automation->refresh()->version)->toBe(1)
        ->and($automation->nodes()->count())->toBe(2);

    // Broken canvas: dangling edge → publish refuses, version unchanged.
    $automation->forceFill(['canvas' => ['nodes' => [
        Fx::node('t1', AutomationNodeType::TriggerManual, [], ['next' => 'ghost']),
    ]]])->save();

    Livewire::test(BuildAutomation::class, ['record' => $automation->getRouteKey()])
        ->callAction('publish');

    expect($automation->refresh()->version)->toBe(1);
});

it('shows live contact counts per node (F-2.4.2-004)', function (): void {
    $automation = Fx::automation($this->customer, [
        Fx::node('t1', AutomationNodeType::TriggerManual, [], ['next' => 'd1']),
        Fx::node('d1', AutomationNodeType::LogicDelay, ['mode' => 'duration', 'value' => 1, 'unit' => 'days'], []),
    ]);

    app(App\Services\Automations\Enroller::class)->enter($automation, $this->subscriber);

    $page = Livewire::test(BuildAutomation::class, ['record' => $automation->getRouteKey()]);
    $counts = $page->instance()->liveCounts();

    expect($counts['d1']['active'])->toBe(1)
        ->and($counts['t1']['reached'])->toBe(1)
        ->and(AutomationRun::withoutTenancy()->where('automation_id', $automation->id)->count())->toBe(1);
});

it('simulates a draft without side effects (F-2.4.2-003)', function (): void {
    $canvasNodes = [
        Fx::node('t1', AutomationNodeType::TriggerManual, [], ['next' => 'i1']),
        Fx::node('i1', AutomationNodeType::LogicIfElse, [
            'match' => 'all',
            'conditions' => [['type' => 'status', 'operator' => 'is', 'value' => 'confirmed']],
        ], ['yes' => 'a1', 'no' => 'x1']),
        Fx::node('a1', AutomationNodeType::ActionAddTag, ['tag' => 'simulated'], ['next' => 'e1']),
        Fx::node('e1', AutomationNodeType::ActionSendEmail, [
            'mode' => 'inline', 'subject' => 'Sim', 'content_html' => '<p>x</p>',
        ], ['next' => 'x1']),
        Fx::node('x1', AutomationNodeType::LogicExit, [], []),
    ];

    $automation = Automation::factory()->create([
        'customer_id' => $this->customer->id,
        'canvas' => ['nodes' => $canvasNodes],
    ]);

    $steps = app(SimulateRunner::class)->simulateGraph(
        $automation,
        Graph::fromCanvas($canvasNodes),
        $this->subscriber,
    );

    $byUid = collect($steps)->keyBy('uid');

    // Condition evaluated for real → yes branch; actions reported, not run.
    expect($byUid['i1']['branch'])->toBe('yes')
        ->and($byUid['a1']['detail'])->toHaveKey('would_add_tag')
        ->and($byUid['e1']['detail'])->toHaveKey('would_send')
        ->and(collect($steps)->last()['result'])->toBe('exited');

    // No side effects: no tags, no runs, no email rows.
    expect(app(SubscriberTagService::class)->has($this->subscriber, 'simulated'))->toBeFalse()
        ->and(SubscriberTag::withoutTenancy()->count())->toBe(0)
        ->and(AutomationRun::withoutTenancy()->count())->toBe(0);
});

it('runs the simulate action from the builder page', function (): void {
    $automation = draftAutomation();

    $page = Livewire::test(BuildAutomation::class, ['record' => $automation->getRouteKey()])
        ->callAction('addNode', ['type' => AutomationNodeType::LogicExit->value], ['parent' => 't1', 'branch' => 'next'])
        ->callAction('simulate', ['email' => $this->subscriber->email]);

    expect($page->instance()->simulationSteps)->not->toBe([]);
});
