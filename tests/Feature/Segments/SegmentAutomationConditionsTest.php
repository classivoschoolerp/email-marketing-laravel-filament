<?php

declare(strict_types=1);

use App\Enums\AutomationRunStatus;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ListSegment;
use App\Models\Subscriber;
use App\Services\Segments\SegmentQueryBuilder;
use App\Support\Tenancy\CurrentCustomer;

/**
 * F-2.2.10-009 — automation conditions ("in automation X" / "completed
 * automation X") read from automation_runs, the engine's own journey record.
 * An unknown, deleted or cross-tenant automation matches NOTHING (D-90).
 */
beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->for($this->customer)->create();
    $this->builder = app(SegmentQueryBuilder::class);

    $this->welcome = Automation::factory()->for($this->customer)->create(['name' => 'Welcome']);
    $this->winback = Automation::factory()->for($this->customer)->create(['name' => 'Winback']);
    $this->deleted = Automation::factory()->for($this->customer)->create(['name' => 'Retired']);
    $this->foreign = Automation::factory()->create(['name' => 'Foreign']); // another tenant

    $this->active = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'active@x.example']);
    $this->done = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'done@x.example']);
    $this->other = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'other@x.example']);
    $this->exited = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'exited@x.example']);
    $this->never = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'never@x.example']);

    $run = fn (Subscriber $s, Automation $a, AutomationRunStatus $status, ?int $customerId = null) => AutomationRun::create([
        'automation_id' => $a->id,
        'customer_id' => $customerId ?? $a->customer_id,
        'subscriber_id' => $s->id,
        'status' => $status,
        'entered_at' => now()->subDay(),
        'completed_at' => $status === AutomationRunStatus::Completed ? now() : null,
    ]);

    $run($this->active, $this->welcome, AutomationRunStatus::Active);
    $run($this->done, $this->welcome, AutomationRunStatus::Completed);
    $run($this->other, $this->winback, AutomationRunStatus::Active);
    $run($this->exited, $this->welcome, AutomationRunStatus::Exited);
    // Retired automation: a real run, but the automation is soft-deleted below.
    $run($this->never, $this->deleted, AutomationRunStatus::Active);
    // A run of ANOTHER tenant's automation pointing at our subscriber.
    $run($this->never, $this->foreign, AutomationRunStatus::Active, (int) $this->foreign->customer_id);

    $this->deleted->delete();

    $this->emails = fn (string $operator, array $settings): array => $this->builder
        ->build($this->list, 'all', [[
            'type' => 'automation',
            'operator' => $operator,
            'settings' => $settings,
        ]])
        ->orderBy('email')
        ->pluck('email')
        ->all();
});

it('matches subscribers currently in an automation (F-2.2.10-009)', function (): void {
    $emails = $this->emails;

    // Only ACTIVE runs count as "in": done@ completed, exited@ left early.
    expect($emails('in_automation', ['automation_id' => $this->welcome->id]))->toBe(['active@x.example'])
        ->and($emails('in_automation', ['automation_id' => $this->winback->id]))->toBe(['other@x.example']);
});

it('matches subscribers who completed an automation (F-2.2.10-009)', function (): void {
    $emails = $this->emails;

    expect($emails('completed_automation', ['automation_id' => $this->welcome->id]))->toBe(['done@x.example'])
        ->and($emails('completed_automation', ['automation_id' => $this->winback->id]))->toBe([]);
});

it('matches nothing for a missing, deleted or cross-tenant automation (D-90, F-2.2.10-009)', function (): void {
    $emails = $this->emails;

    expect($emails('in_automation', ['automation_id' => $this->foreign->id]))->toBe([])
        ->and($emails('completed_automation', ['automation_id' => $this->foreign->id]))->toBe([])
        ->and($emails('in_automation', ['automation_id' => $this->deleted->id]))->toBe([])
        ->and($emails('in_automation', ['automation_id' => 999999]))->toBe([])
        ->and($emails('in_automation', []))->toBe([])
        ->and($emails('in_automation', ['automation_id' => '']))->toBe([]);
});

it('offers a tenant-scoped automation picker (F-2.2.10-009)', function (): void {
    $options = $this->builder->automationOptions($this->list);

    expect($options)->toHaveKey($this->welcome->id)
        ->and($options)->toHaveKey($this->winback->id)
        ->and($options)->not->toHaveKey($this->foreign->id)
        ->and($options[$this->welcome->id])->toBe('Welcome');
});

it('evaluates a saved automation segment through its stored settings (F-2.2.10-009)', function (): void {
    $segment = ListSegment::factory()->for($this->list, 'list')->create(['match_type' => 'any']);

    $segment->conditions()->create([
        'customer_id' => $this->customer->id,
        'type' => 'automation',
        'operator' => 'in_automation',
        'settings' => ['automation_id' => $this->welcome->id],
        'sort_order' => 0,
    ]);
    $segment->conditions()->create([
        'customer_id' => $this->customer->id,
        'type' => 'automation',
        'operator' => 'completed_automation',
        'settings' => ['automation_id' => $this->welcome->id],
        'sort_order' => 1,
    ]);

    expect($this->builder->subscribers($segment)->orderBy('email')->pluck('email')->all())
        ->toBe(['active@x.example', 'done@x.example'])
        ->and($this->builder->count($segment))->toBe(2);
});
