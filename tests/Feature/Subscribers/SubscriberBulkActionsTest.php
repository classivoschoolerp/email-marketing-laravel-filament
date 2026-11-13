<?php

declare(strict_types=1);

use App\Enums\SubscriberStatus;
use App\Filament\Customer\Resources\SubscriberResource\Pages\ListSubscribers;
use App\Jobs\Subscribers\BulkSubscriberActionJob;
use App\Jobs\Subscribers\ExportSelectedSubscribersJob;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Services\Lists\FieldValueService;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->for($this->customer)->create();
});

function runBulkJob(string $action, array $ids, ?int $targetListId = null): void
{
    (new BulkSubscriberActionJob(
        test()->customer->id,
        $action,
        $ids,
        $targetListId,
        (string) Str::uuid(),
    ))->handle(app(CurrentCustomer::class));
}

it('dispatches a queued job for every bulk status action (F-2.2.4-007)', function (string $action): void {
    Queue::fake();

    $subscribers = Subscriber::factory()->for($this->list, 'list')->count(2)->create();

    Livewire::test(ListSubscribers::class)
        ->callTableBulkAction($action, $subscribers);

    Queue::assertPushed(
        BulkSubscriberActionJob::class,
        fn (BulkSubscriberActionJob $job): bool => $job->action === $action
            && $job->customerId === $this->customer->id
            && count($job->subscriberIds) === 2,
    );
})->with(['unsubscribe', 'confirm', 'disable', 'blacklist', 'unblacklist', 'delete']);

it('dispatches the copy/move bulk jobs with a target list', function (string $uiAction, string $jobAction): void {
    Queue::fake();

    $target = EmailList::factory()->for($this->customer)->create();
    $subscribers = Subscriber::factory()->for($this->list, 'list')->count(2)->create();

    Livewire::test(ListSubscribers::class)
        ->callTableBulkAction($uiAction, $subscribers, data: ['target_list_id' => $target->id]);

    Queue::assertPushed(
        BulkSubscriberActionJob::class,
        fn (BulkSubscriberActionJob $job): bool => $job->action === $jobAction
            && $job->targetListId === $target->id,
    );
})->with([
    'copy' => ['copy_to', BulkSubscriberActionJob::ACTION_COPY_TO_LIST],
    'move' => ['move_to', BulkSubscriberActionJob::ACTION_MOVE_TO_LIST],
]);

it('dispatches the export job for export-selected', function (): void {
    Queue::fake();

    $subscribers = Subscriber::factory()->for($this->list, 'list')->count(3)->create();

    Livewire::test(ListSubscribers::class)
        ->callTableBulkAction('export', $subscribers);

    Queue::assertPushed(
        ExportSelectedSubscribersJob::class,
        fn (ExportSelectedSubscribersJob $job): bool => count($job->subscriberIds) === 3,
    );
});

it('applies status transitions through the job, cascades included', function (): void {
    $linked = EmailList::factory()->for($this->customer)->create();
    $this->list->update(['on_unsubscribe_unsubscribe_ids' => [$linked->id]]);

    $subscriber = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'x@x.example']);
    Subscriber::factory()->for($linked, 'list')->create(['email' => 'x@x.example']);

    runBulkJob(BulkSubscriberActionJob::ACTION_UNSUBSCRIBE, [$subscriber->id]);

    expect($subscriber->refresh()->status)->toBe(SubscriberStatus::Unsubscribed)
        ->and(Subscriber::withoutTenancy()
            ->where('list_id', $linked->id)
            ->where('email', 'x@x.example')
            ->first()->status)->toBe(SubscriberStatus::Unsubscribed);
});

it('confirms, disables, blacklists, unblacklists and deletes through the job', function (): void {
    $unconfirmed = Subscriber::factory()->for($this->list, 'list')->unconfirmed()->create();
    runBulkJob(BulkSubscriberActionJob::ACTION_CONFIRM, [$unconfirmed->id]);
    expect($unconfirmed->refresh()->status)->toBe(SubscriberStatus::Confirmed);

    runBulkJob(BulkSubscriberActionJob::ACTION_DISABLE, [$unconfirmed->id]);
    expect($unconfirmed->refresh()->status)->toBe(SubscriberStatus::Disabled);

    runBulkJob(BulkSubscriberActionJob::ACTION_BLACKLIST, [$unconfirmed->id]);
    expect($unconfirmed->refresh()->status)->toBe(SubscriberStatus::Blacklisted);

    runBulkJob(BulkSubscriberActionJob::ACTION_UNBLACKLIST, [$unconfirmed->id]);
    expect($unconfirmed->refresh()->status)->toBe(SubscriberStatus::Confirmed);

    runBulkJob(BulkSubscriberActionJob::ACTION_DELETE, [$unconfirmed->id]);
    expect(Subscriber::query()->whereKey($unconfirmed->id)->exists())->toBeFalse();
});

it('copies subscribers to another list mapping field values by tag', function (): void {
    $target = EmailList::factory()->for($this->customer)->create();

    $sourceField = ListField::factory()->for($this->list, 'list')->create(['tag' => 'FNAME']);
    $targetField = ListField::factory()->for($target, 'list')->create(['tag' => 'FNAME']);
    ListField::factory()->for($this->list, 'list')->create(['tag' => 'ONLY_SOURCE']);

    $subscriber = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'copy@x.example']);
    app(FieldValueService::class)->set($subscriber, $sourceField, 'Ada');

    runBulkJob(BulkSubscriberActionJob::ACTION_COPY_TO_LIST, [$subscriber->id], $target->id);

    $copy = Subscriber::withoutTenancy()
        ->where('list_id', $target->id)
        ->where('email', 'copy@x.example')
        ->firstOrFail();

    expect($copy->uid)->not->toBe($subscriber->uid)
        ->and(app(FieldValueService::class)->get($copy, $targetField))->toBe('Ada')
        // Source row untouched by a copy.
        ->and($subscriber->refresh()->status)->toBe(SubscriberStatus::Confirmed);

    // Copying again is a no-op (duplicate protection).
    runBulkJob(BulkSubscriberActionJob::ACTION_COPY_TO_LIST, [$subscriber->id], $target->id);
    expect(Subscriber::withoutTenancy()->where('list_id', $target->id)->count())->toBe(1);
});

it('moves subscribers, marking the source row as moved (F-2.2.4-007)', function (): void {
    $target = EmailList::factory()->for($this->customer)->create();
    $subscriber = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'move@x.example']);

    runBulkJob(BulkSubscriberActionJob::ACTION_MOVE_TO_LIST, [$subscriber->id], $target->id);

    expect($subscriber->refresh()->status)->toBe(SubscriberStatus::Moved)
        ->and(Subscriber::withoutTenancy()
            ->where('list_id', $target->id)
            ->where('email', 'move@x.example')
            ->exists())->toBeTrue();
});

it('never touches another tenant\'s subscribers even with forged ids', function (): void {
    $foreign = Subscriber::factory()->create(); // other tenant

    runBulkJob(BulkSubscriberActionJob::ACTION_UNSUBSCRIBE, [$foreign->id]);

    expect($foreign->refresh()->status)->toBe(SubscriberStatus::Confirmed);
});
