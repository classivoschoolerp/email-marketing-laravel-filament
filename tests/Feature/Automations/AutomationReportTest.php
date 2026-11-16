<?php

declare(strict_types=1);

use App\Enums\AutomationNodeType;
use App\Filament\Customer\Resources\AutomationResource;
use App\Models\AutomationEmailSend;
use App\Services\Automations\AutomationStats;
use App\Services\Automations\Enroller;
use App\Services\Automations\TrackSigner;
use Illuminate\Support\Carbon;
use Tests\Support\AutomationFixtures as Fx;

/*
 * §2.4.3 report: funnel + per-node stats (F-2.4.3-001/002), email
 * performance incl. WP-21's own open/click tracking (F-2.4.3-003),
 * conversion + revenue (F-2.4.3-004), drill-down (F-2.4.3-005) and CSV
 * export (F-2.4.3-007).
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-08 10:00:00');
    $this->customer = Fx::actingAsCustomer();
    $this->driver = Fx::fakeDeliveryServer($this->customer);
    [$this->list, $this->subscriber] = Fx::listWithSubscriber($this->customer);

    $this->field = App\Models\ListField::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->customer->id,
        'tag' => 'CONVERTED',
    ]);

    $this->automation = Fx::automation($this->customer, [
        Fx::node('t1', AutomationNodeType::TriggerManual, [], ['next' => 'e1']),
        Fx::node('e1', AutomationNodeType::ActionSendEmail, [
            'mode' => 'inline',
            'subject' => 'Report email',
            'content_html' => '<html><body><a href="https://x.example/go">Go</a></body></html>',
        ], ['next' => 'u1']),
        Fx::node('u1', AutomationNodeType::ActionUpdateField, [
            'list_field_id' => $this->field->id,
            'value' => 'yes',
        ], ['next' => 'g1']),
        Fx::node('g1', AutomationNodeType::LogicGoal, [
            'match' => 'all',
            'conditions' => [[
                'type' => 'field',
                'list_field_id' => $this->field->id,
                'operator' => 'is',
                'value' => 'yes',
            ]],
            'conversion_value' => 250,
        ], []),
    ]);

    app(Enroller::class)->enter($this->automation, $this->subscriber);
});

afterEach(fn () => Carbon::setTestNow());

it('tracks opens and clicks on automation emails via the signed endpoints', function (): void {
    $send = AutomationEmailSend::withoutTenancy()->where('automation_id', $this->automation->id)->first();
    $signer = app(TrackSigner::class);

    expect($send)->not->toBeNull()->and($send->opened_at)->toBeNull();

    // Bad signature → 404 for clicks, blind pixel for opens (no write).
    $this->get(route('automations.track.click', ['uid' => $send->uid, 'sig' => 'bad']).'?u='.rawurlencode('https://x.example/go'))
        ->assertNotFound();
    $this->get(route('automations.track.open', ['uid' => $send->uid, 'sig' => 'bad']))->assertOk();
    expect($send->refresh()->opened_at)->toBeNull();

    // Valid open pixel.
    $this->get(route('automations.track.open', ['uid' => $send->uid, 'sig' => $signer->signOpen($send->uid)]))
        ->assertOk()
        ->assertHeader('content-type', 'image/gif');
    expect($send->refresh()->opened_at)->not->toBeNull();

    // Valid click → redirect + timestamps.
    $url = 'https://x.example/go';
    $this->get(route('automations.track.click', ['uid' => $send->uid, 'sig' => $signer->signClick($send->uid, $url)]).'?u='.rawurlencode($url))
        ->assertRedirect($url);

    expect($send->refresh()->clicked_at)->not->toBeNull()
        ->and($send->last_click_url)->toBe($url);
});

it('computes summary, funnel and per-node stats (F-2.4.3-001/002/004)', function (): void {
    $stats = app(AutomationStats::class);

    $summary = $stats->summary($this->automation);
    expect($summary['entered'])->toBe(1)
        ->and($summary['completed'])->toBe(1)
        ->and($summary['converted'])->toBe(1)
        ->and($summary['conversion_rate'])->toBe(100.0)
        ->and($summary['revenue'])->toBe(250);

    $perNode = collect($stats->perNode($this->automation))->keyBy(fn (array $row): string => $row['node']->uid);

    expect($perNode->keys()->all())->toBe(['t1', 'e1', 'u1', 'g1']) // BFS funnel order
        ->and($perNode['t1']['reached'])->toBe(1)
        ->and($perNode['e1']['reached'])->toBe(1)
        ->and($perNode['e1']['sent'])->toBe(1)
        ->and($perNode['g1']['converted'])->toBe(1);
});

it('renders the report page and drills into contacts at a node (F-2.4.3-005)', function (): void {
    $this->get(AutomationResource::getUrl('report', ['record' => $this->automation]))
        ->assertOk()
        ->assertSee(__('automations.node_type.action_send_email'));

    // Park a second contact mid-flow to drill into.
    $automation = Fx::automation($this->customer, [
        Fx::node('t1', AutomationNodeType::TriggerManual, [], ['next' => 'd1']),
        Fx::node('d1', AutomationNodeType::LogicDelay, ['mode' => 'duration', 'value' => 2, 'unit' => 'days'], []),
    ]);

    [, $parked] = Fx::listWithSubscriber($this->customer, 'parked@example.com');
    app(Enroller::class)->enter($automation, $parked);

    $node = $automation->nodes()->where('uid', 'd1')->first();
    $contacts = app(AutomationStats::class)->contactsAtNode($automation, (int) $node->id);

    expect($contacts)->toHaveCount(1)
        ->and($contacts->first()->subscriber->email)->toBe('parked@example.com');

    Livewire\Livewire::test(AutomationResource\Pages\AutomationReport::class, ['record' => $automation->getRouteKey()])
        ->set('drilldownNode', 'd1')
        ->assertSee('parked@example.com');
});

it('exports the report as CSV (F-2.4.3-007)', function (): void {
    $response = $this->get(route('automations.report.export', ['uid' => $this->automation->uid]));

    $response->assertOk();

    $csv = $response->streamedContent();

    expect($csv)->toContain('action.send_email')
        ->and($csv)->toContain('Entered,1')
        ->and($csv)->toContain('"Revenue (minor units)",250');
});
