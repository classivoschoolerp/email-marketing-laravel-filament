<?php

declare(strict_types=1);

use App\Enums\SubscriberStatus;
use App\Filament\Customer\Resources\EmailListResource\Pages\ListEmailLists;
use App\Jobs\Exports\RunExportJob;
use App\Jobs\Lists\PurgeListJob;
use App\Jobs\Lists\SplitListJob;
use App\Jobs\Lists\SyncListsJob;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ExportJob;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Services\Lists\FieldValueService;
use App\Services\Lists\ListCopyService;
use App\Services\Lists\SubscriberCloner;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);
    Storage::fake('local');

    $this->runSplit = fn (SplitListJob $job) => $job->handle(
        app(CurrentCustomer::class),
        app(ListCopyService::class),
        app(SubscriberCloner::class),
    );

    $this->runSync = fn (SyncListsJob $job) => $job->handle(
        app(CurrentCustomer::class),
        app(SubscriberCloner::class),
    );
});

// F-2.2.13-001: split by count with remainder handling.
it('splits a list into part lists by count, remainder in the last part', function (): void {
    $list = EmailList::factory()->for($this->customer)->create(['name' => 'Big']);
    $field = ListField::factory()->for($list, 'list')->create(['tag' => 'FNAME', 'label' => 'First name']);

    foreach (range(1, 7) as $i) {
        $subscriber = Subscriber::factory()->for($list, 'list')->create(['email' => "s{$i}@example.com"]);
        app(FieldValueService::class)->set($subscriber, $field, "Name {$i}");
    }

    ($this->runSplit)(new SplitListJob($this->customer->id, $list->id, SplitListJob::MODE_COUNT, 3, (string) Str::uuid()));

    $parts = EmailList::query()
        ->where('name', 'like', 'Big (part %')
        ->orderBy('id')
        ->get();

    expect($parts)->toHaveCount(3)
        ->and($parts[0]->subscribers()->count())->toBe(3)
        ->and($parts[1]->subscribers()->count())->toBe(3)
        ->and($parts[2]->subscribers()->count())->toBe(1) // remainder
        ->and($list->subscribers()->count())->toBe(7); // source untouched

    // Structure + field values travel with the parts.
    $partField = $parts[0]->fields()->where('tag', 'FNAME')->firstOrFail();
    $moved = $parts[0]->subscribers()->orderBy('id')->firstOrFail();
    expect(app(FieldValueService::class)->get($moved, $partField))->toBe('Name 1');
});

// F-2.2.13-001: split by percentage.
it('splits a list by percentage', function (): void {
    $list = EmailList::factory()->for($this->customer)->create(['name' => 'Perc']);
    Subscriber::factory()->count(10)->for($list, 'list')->create();

    ($this->runSplit)(new SplitListJob($this->customer->id, $list->id, SplitListJob::MODE_PERCENT, 50, (string) Str::uuid()));

    $parts = EmailList::query()->where('name', 'like', 'Perc (part %')->orderBy('id')->get();

    expect($parts)->toHaveCount(2)
        ->and($parts[0]->subscribers()->count())->toBe(5)
        ->and($parts[1]->subscribers()->count())->toBe(5);
});

// F-2.2.13-002: sync by presence — copy, move, remove.
it('syncs two lists by presence comparison', function (): void {
    $a = EmailList::factory()->for($this->customer)->create(['name' => 'A']);
    $b = EmailList::factory()->for($this->customer)->create(['name' => 'B']);

    Subscriber::factory()->for($a, 'list')->create(['email' => 'both@example.com']);
    Subscriber::factory()->for($a, 'list')->create(['email' => 'only-a@example.com']);
    Subscriber::factory()->for($b, 'list')->create(['email' => 'both@example.com']);

    // copy: subscribers of A missing from B are copied into B.
    ($this->runSync)(new SyncListsJob($this->customer->id, $a->id, $b->id, SyncListsJob::ACTION_COPY, (string) Str::uuid()));

    expect($b->subscribers()->where('email', 'only-a@example.com')->exists())->toBeTrue()
        ->and($b->subscribers()->count())->toBe(2)
        ->and($a->subscribers()->count())->toBe(2); // copy is non-destructive

    // remove: subscribers of A that exist in B are removed from A.
    ($this->runSync)(new SyncListsJob($this->customer->id, $a->id, $b->id, SyncListsJob::ACTION_REMOVE, (string) Str::uuid()));

    expect($a->subscribers()->count())->toBe(0); // both emails now exist in B

    // move: subscribers of B missing from A move into A (source marked moved).
    $c = EmailList::factory()->for($this->customer)->create(['name' => 'C']);
    Subscriber::factory()->for($c, 'list')->create(['email' => 'mover@example.com']);

    ($this->runSync)(new SyncListsJob($this->customer->id, $c->id, $a->id, SyncListsJob::ACTION_MOVE, (string) Str::uuid()));

    expect($a->subscribers()->where('email', 'mover@example.com')->exists())->toBeTrue()
        ->and($c->subscribers()->where('email', 'mover@example.com')->firstOrFail()->status)
        ->toBe(SubscriberStatus::Moved);
});

// The table actions queue the tools (docs/ARCHITECTURE.md rule 5).
it('queues split and sync jobs from the list table actions', function (): void {
    Queue::fake();

    $list = EmailList::factory()->for($this->customer)->create();
    $other = EmailList::factory()->for($this->customer)->create();

    Livewire::test(ListEmailLists::class)
        ->callTableAction('split', $list, data: ['mode' => SplitListJob::MODE_COUNT, 'value' => 100])
        ->callTableAction('sync', $list, data: [
            'target_list_id' => $other->id,
            'action' => SyncListsJob::ACTION_COPY,
        ]);

    Queue::assertPushed(SplitListJob::class, fn (SplitListJob $job): bool => $job->listId === $list->id
        && $job->mode === SplitListJob::MODE_COUNT
        && $job->value === 100);

    Queue::assertPushed(SyncListsJob::class, fn (SyncListsJob $job): bool => $job->sourceListId === $list->id
        && $job->targetListId === $other->id
        && $job->action === SyncListsJob::ACTION_COPY);
});

// F-2.2.13-007: mass delete with typed confirmation, queued purge.
it('mass-deletes lists only after typing the confirmation word', function (): void {
    Queue::fake();

    $lists = EmailList::factory()->count(2)->for($this->customer)->create();

    // Wrong word → validation error, nothing deleted.
    Livewire::test(ListEmailLists::class)
        ->callTableBulkAction('mass_delete', $lists, data: ['confirmation' => 'NOPE'])
        ->assertHasTableBulkActionErrors(['confirmation']);

    expect(EmailList::query()->count())->toBe(2);
    Queue::assertNotPushed(PurgeListJob::class);

    // Correct word → soft-deleted + purge queued per list.
    Livewire::test(ListEmailLists::class)
        ->callTableBulkAction('mass_delete', $lists, data: ['confirmation' => __('lists.tools.mass_delete_word')])
        ->assertHasNoTableBulkActionErrors();

    expect(EmailList::query()->count())->toBe(0)
        ->and(EmailList::withTrashed()->where('status', EmailList::STATUS_PENDING_DELETE)->count())->toBe(2);

    Queue::assertPushed(PurgeListJob::class, 2);
});

// F-2.2.13-007: mass export queues one full export per list.
it('mass-exports the selected lists', function (): void {
    Queue::fake();

    $lists = EmailList::factory()->count(2)->for($this->customer)->create();

    Livewire::test(ListEmailLists::class)
        ->callTableBulkAction('mass_export', $lists);

    expect(ExportJob::query()->where('scope', ExportJob::SCOPE_ALL)->count())->toBe(2);
    Queue::assertPushed(RunExportJob::class, 2);
});
