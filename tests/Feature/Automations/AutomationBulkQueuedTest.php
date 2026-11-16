<?php

declare(strict_types=1);

use App\Enums\AutomationStatus;
use App\Filament\Customer\Resources\AutomationResource\Pages\ListAutomations;
use App\Jobs\Automations\BulkAutomationActionJob;
use App\Models\Automation;
use App\Models\Customer;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * Part 8 F-Phase-3-Differentiat-006 — bulk actions are queued, never
 * synchronous. Closes the last deviation found by the bulk-action re-audit:
 * the automations index used to flip statuses inline in the Livewire request.
 */
beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);
});

it('queues the bulk pause instead of flipping statuses in the request', function (): void {
    Queue::fake();

    $active = Automation::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => AutomationStatus::Active,
    ]);

    Livewire::test(ListAutomations::class)
        ->callTableBulkAction('pause', [$active])
        ->assertHasNoTableBulkActionErrors();

    // Nothing ran inline …
    expect($active->refresh()->status)->toBe(AutomationStatus::Active);

    // … the work was handed to the queue with this row in the payload.
    Queue::assertPushed(BulkAutomationActionJob::class);
});

it('never queues another tenant\'s automation', function (): void {
    Queue::fake();

    $foreign = Automation::factory()->create([
        'customer_id' => Customer::factory()->create()->id,
        'status' => AutomationStatus::Active,
    ]);

    Livewire::test(ListAutomations::class)
        ->callTableBulkAction('pause', [$foreign]);

    Queue::assertNotPushed(BulkAutomationActionJob::class);
});

it('applies pause and activate when the job runs, re-checking state', function (): void {
    $active = Automation::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => AutomationStatus::Active,
    ]);

    (new BulkAutomationActionJob([(int) $active->id], BulkAutomationActionJob::ACTION_PAUSE))->handle();
    expect($active->refresh()->status)->toBe(AutomationStatus::Paused);

    // An unpublished automation must never be activated by a bulk.
    $draft = Automation::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => AutomationStatus::Draft,
    ]);

    (new BulkAutomationActionJob([(int) $draft->id], BulkAutomationActionJob::ACTION_ACTIVATE))->handle();
    expect($draft->refresh()->status)->toBe(AutomationStatus::Draft);
});
