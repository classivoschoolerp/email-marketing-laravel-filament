<?php

declare(strict_types=1);

use App\Enums\AutomationNodeType;
use App\Filament\Customer\Resources\AutomationResource;
use App\Filament\Customer\Resources\AutomationResource\Pages\BuildAutomation;
use App\Models\Automation;
use App\Models\Customer;
use App\Services\Automations\CanvasLayout;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Support\AutomationFixtures as Fx;

/*
 * F-2.4.2-001 — drag & drop node canvas with zoom/pan/minimap, auto-layout,
 * undo/redo (spec §2.4.2).
 *
 * Everything assertable server-side is asserted here: the canvas JSON now
 * carries integer x/y per node, drag commits them, dropping a card on a
 * branch re-routes the graph, auto-layout writes exact tidy-tree coordinates
 * and undo/redo walk the saved snapshots back and forward through the same
 * save path. Zoom, pan and the minimap are browser-only state
 * (resources/js/automation-canvas.js) and are verified by hand.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-09 10:00:00');
    $this->customer = Fx::actingAsCustomer();
});

afterEach(fn () => Carbon::setTestNow());

/** t1 → i1 (if/else) → a1 (yes) | a2 (no) — a two-level branching canvas. */
function branchingCanvas(): array
{
    return [
        Fx::node('t1', AutomationNodeType::TriggerManual, [], ['next' => 'i1']),
        Fx::node('i1', AutomationNodeType::LogicIfElse, [
            'match' => 'all',
            'conditions' => [['type' => 'status', 'operator' => 'is', 'value' => 'confirmed']],
        ], ['yes' => 'a1', 'no' => 'a2']),
        Fx::node('a1', AutomationNodeType::ActionAddTag, ['tag' => 'yes'], []),
        Fx::node('a2', AutomationNodeType::ActionAddTag, ['tag' => 'no'], []),
    ];
}

function canvasAutomation(array $nodes): Automation
{
    return Automation::factory()->create([
        'customer_id' => test()->customer->id,
        'canvas' => ['nodes' => $nodes],
    ]);
}

/** @return array<string, array{x: int, y: int}> */
function savedPositions(Automation $automation): array
{
    $positions = [];

    foreach ($automation->refresh()->canvas['nodes'] as $node) {
        $positions[(string) $node['uid']] = ['x' => (int) $node['x'], 'y' => (int) $node['y']];
    }

    return $positions;
}

it('lays out a position-less legacy canvas on open and persists the coordinates (F-2.4.2-001)', function (): void {
    $automation = canvasAutomation(branchingCanvas());

    foreach ($automation->canvas['nodes'] as $node) {
        expect($node)->not->toHaveKey('x');
    }

    Livewire::test(BuildAutomation::class, ['record' => $automation->getRouteKey()]);

    // Tidy tree: leaves take columns 0 and 2 (X_UNIT = 140), the if/else
    // centres over them at column 1, the trigger inherits that column.
    expect(savedPositions($automation))->toBe([
        't1' => ['x' => 140, 'y' => 0],
        'i1' => ['x' => 140, 'y' => 170],
        'a1' => ['x' => 0, 'y' => 340],
        'a2' => ['x' => 280, 'y' => 340],
    ]);
});

it('auto-layout rewrites hand-moved coordinates back into a tidy tree (F-2.4.2-001)', function (): void {
    $nodes = branchingCanvas();
    $nodes[0]['x'] = 4000;
    $nodes[0]['y'] = 4000;
    $nodes[1]['x'] = 17;
    $nodes[1]['y'] = 33;
    $nodes[2]['x'] = 900;
    $nodes[2]['y'] = 12;
    $nodes[3]['x'] = 12;
    $nodes[3]['y'] = 900;

    $automation = canvasAutomation($nodes);

    Livewire::test(BuildAutomation::class, ['record' => $automation->getRouteKey()])
        ->call('autoLayout');

    expect(savedPositions($automation))->toBe([
        't1' => ['x' => 140, 'y' => 0],
        'i1' => ['x' => 140, 'y' => 170],
        'a1' => ['x' => 0, 'y' => 340],
        'a2' => ['x' => 280, 'y' => 340],
    ]);

    // A straight chain collapses onto a single column.
    $chain = canvasAutomation([
        Fx::node('t1', AutomationNodeType::TriggerManual, [], ['next' => 'n2']),
        Fx::node('n2', AutomationNodeType::ActionAddTag, ['tag' => 'a'], ['next' => 'n3']),
        Fx::node('n3', AutomationNodeType::LogicExit, [], []),
    ]);

    Livewire::test(BuildAutomation::class, ['record' => $chain->getRouteKey()])->call('autoLayout');

    expect(savedPositions($chain))->toBe([
        't1' => ['x' => 0, 'y' => 0],
        'n2' => ['x' => 0, 'y' => 170],
        'n3' => ['x' => 0, 'y' => 340],
    ]);
});

it('persists dragged node coordinates and ignores foreign or malformed uids (F-2.4.2-001)', function (): void {
    $automation = canvasAutomation(branchingCanvas());

    Livewire::test(BuildAutomation::class, ['record' => $automation->getRouteKey()])
        ->call('updateNodePositions', [
            'a1' => ['x' => 512, 'y' => 640],
            'a2' => ['x' => -30, 'y' => 999_999],
            'ghost' => ['x' => 10, 'y' => 10],
            't1' => 'not-a-point',
        ]);

    expect(savedPositions($automation))->toBe([
        't1' => ['x' => 140, 'y' => 0],
        'i1' => ['x' => 140, 'y' => 170],
        'a1' => ['x' => 512, 'y' => 640],
        // clamped into the canvas: 0 .. 100000
        'a2' => ['x' => 0, 'y' => 100_000],
    ]);
});

it('re-routes the graph when a card is dropped on another branch (F-2.4.2-001)', function (): void {
    $automation = canvasAutomation([
        Fx::node('t1', AutomationNodeType::TriggerManual, [], ['next' => 'a1']),
        Fx::node('a1', AutomationNodeType::ActionAddTag, ['tag' => 'one'], ['next' => 'a2']),
        Fx::node('a2', AutomationNodeType::ActionAddTag, ['tag' => 'two'], ['next' => 'x1']),
        Fx::node('x1', AutomationNodeType::LogicExit, [], []),
    ]);

    Livewire::test(BuildAutomation::class, ['record' => $automation->getRouteKey()])
        ->call('moveNodeToBranch', 'a2', 't1', 'next');

    $edges = collect($automation->refresh()->canvas['nodes'])->keyBy('uid')
        ->map(fn (array $node): array => (array) ($node['edges'] ?? []))
        ->all();

    // a1 handed its child to the parent on detach, then a2 took the head slot.
    expect($edges['t1']['next'])->toBe('a2')
        ->and($edges['a2']['next'])->toBe('a1')
        ->and($edges['a1']['next'])->toBe('x1')
        ->and($edges['x1'])->toBe([])
        ->and($automation->canvas['nodes'])->toHaveCount(4);
});

it('refuses moves that would loop the flow or unseat the trigger (F-2.4.2-001)', function (): void {
    $automation = canvasAutomation(array_merge(branchingCanvas(), []));

    // a1 (the yes branch) now flows into a2, so hanging i1 under a2 would
    // close a cycle i1 → a2 → … → i1.
    $nodes = collect($automation->canvas['nodes'])->map(function (array $node): array {
        if ($node['uid'] === 'a1') {
            $node['edges'] = ['next' => 'a2'];
        }

        return $node;
    })->values()->all();

    $automation->forceFill(['canvas' => ['nodes' => $nodes]])->save();

    $page = Livewire::test(BuildAutomation::class, ['record' => $automation->getRouteKey()]);

    $page->call('moveNodeToBranch', 'i1', 'a2', 'next');

    $edges = collect($automation->refresh()->canvas['nodes'])->keyBy('uid')
        ->map(fn (array $node): array => (array) ($node['edges'] ?? []))->all();

    expect($edges['t1']['next'])->toBe('i1')
        ->and($edges['i1'])->toBe(['yes' => 'a1', 'no' => 'a2']);

    // The trigger itself can never be dragged onto an edge.
    $page->call('moveNodeToBranch', 't1', 'a2', 'next');

    expect(collect($automation->refresh()->canvas['nodes'])->firstWhere('uid', 'a2')['edges'])->toBe([]);
});

it('undoes and redoes canvas edits through the save path (F-2.4.2-001)', function (): void {
    $automation = canvasAutomation([
        Fx::node('t1', AutomationNodeType::TriggerManual, [], []),
    ]);

    $page = Livewire::test(BuildAutomation::class, ['record' => $automation->getRouteKey()]);

    expect($page->instance()->canUndo())->toBeFalse()
        ->and($page->instance()->canRedo())->toBeFalse();

    $page->callAction('addNode', ['type' => AutomationNodeType::LogicExit->value], ['parent' => 't1', 'branch' => 'next']);
    $page->call('updateNodePositions', ['t1' => ['x' => 77, 'y' => 88]]);

    expect($automation->refresh()->canvas['nodes'])->toHaveCount(2)
        ->and(savedPositions($automation)['t1'])->toBe(['x' => 77, 'y' => 88])
        ->and($page->instance()->canUndo())->toBeTrue();

    // Undo the drag: the node count stays, the coordinate reverts.
    $page->call('undo');

    expect($automation->refresh()->canvas['nodes'])->toHaveCount(2)
        ->and(savedPositions($automation)['t1'])->toBe(['x' => 0, 'y' => 0]);

    // Undo the add: back to the single trigger, and nothing left to undo.
    $page->call('undo');

    expect($automation->refresh()->canvas['nodes'])->toHaveCount(1)
        ->and($page->instance()->canUndo())->toBeFalse()
        ->and($page->instance()->canRedo())->toBeTrue();

    $page->call('undo');
    expect($automation->refresh()->canvas['nodes'])->toHaveCount(1);

    // Redo walks forward again through the same saves.
    $page->call('redo');
    expect($automation->refresh()->canvas['nodes'])->toHaveCount(2)
        ->and(savedPositions($automation)['t1'])->toBe(['x' => 0, 'y' => 0]);

    $page->call('redo');
    expect(savedPositions($automation)['t1'])->toBe(['x' => 77, 'y' => 88])
        ->and($page->instance()->canRedo())->toBeFalse();
});

it('never lets one tenant move another tenant nodes (cross-tenant fence)', function (): void {
    $foreignCustomer = Customer::factory()->create();
    $foreign = Automation::withoutTenancy()->create([
        'customer_id' => $foreignCustomer->id,
        'name' => 'Foreign flow',
        'canvas' => ['nodes' => [Fx::node('f1', AutomationNodeType::TriggerManual, [], [])]],
    ]);

    $mine = canvasAutomation([Fx::node('t1', AutomationNodeType::TriggerManual, [], [])]);

    // The foreign builder page is not resolvable at all.
    $this->get(AutomationResource::getUrl('builder', ['record' => $foreign]))
        ->assertNotFound();

    // And a uid from the other tenant is silently ignored on my own canvas.
    Livewire::test(BuildAutomation::class, ['record' => $mine->getRouteKey()])
        ->call('updateNodePositions', ['f1' => ['x' => 500, 'y' => 500]])
        ->call('moveNodeToBranch', 'f1', 't1', 'next');

    expect(collect($mine->refresh()->canvas['nodes'])->pluck('uid')->all())->toBe(['t1'])
        ->and(collect($foreign->refresh()->canvas['nodes'])->firstWhere('uid', 'f1'))->not->toHaveKey('x');
});

it('exposes canvas geometry for the browser layer (F-2.4.2-001)', function (): void {
    $automation = canvasAutomation(branchingCanvas());

    $page = Livewire::test(BuildAutomation::class, ['record' => $automation->getRouteKey()]);
    $instance = $page->instance();

    $cards = collect($instance->canvasNodes())->keyBy('uid');
    $edges = collect($instance->canvasEdges());

    expect($cards)->toHaveCount(4)
        ->and($cards['a2']['x'])->toBe(280)
        ->and($cards['t1']['type'])->toBe(AutomationNodeType::TriggerManual)
        // one row per branch slot: trigger next + if/else yes/no + 2 leaf nexts
        ->and($edges)->toHaveCount(5)
        ->and($edges->firstWhere('branch', 'yes')['to'])->toBe('a1')
        ->and($edges->firstWhere('branch', 'no')['to'])->toBe('a2')
        ->and($edges->where('from', 'a1')->first()['to'])->toBeNull()
        ->and($instance->canvasExtent()['node_width'])->toBe(CanvasLayout::NODE_WIDTH)
        ->and($instance->branchLabel('case:2'))->toContain('3');

    $page->assertOk();
});
