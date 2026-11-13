<?php

declare(strict_types=1);

use App\Enums\SubscriberStatus;
use App\Filament\Customer\Resources\SubscriberResource;
use App\Filament\Customer\Resources\SubscriberResource\Pages\ListSubscribers;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ListField;
use App\Models\ListSegment;
use App\Models\Subscriber;
use App\Services\Lists\FieldValueService;
use App\Services\Segments\SegmentQueryBuilder;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->for($this->customer)->create();
});

it('renders the subscribers index with the standard columns (F-2.2.4-001)', function (): void {
    Subscriber::factory()->for($this->list, 'list')->create(['email' => 'seen@x.example']);

    $this->get(SubscriberResource::getUrl('index', panel: 'customer'))->assertOk();

    Livewire::test(ListSubscribers::class)
        ->assertTableColumnExists('email')
        ->assertTableColumnExists('status')
        ->assertTableColumnExists('source')
        ->assertTableColumnExists('subscribed_at')
        ->assertTableColumnExists('ip')
        ->assertTableColumnExists('last_open_at')
        ->assertTableColumnExists('last_click_at')
        ->assertCanSeeTableRecords(Subscriber::query()->get());
});

it('shows custom-field columns when filtered to a list (F-2.2.4-001)', function (): void {
    $field = ListField::factory()->for($this->list, 'list')->create([
        'tag' => 'FNAME',
        'label' => 'First name',
        'show_in_table' => true,
    ]);
    $hidden = ListField::factory()->for($this->list, 'list')->create([
        'tag' => 'INTERNAL',
        'show_in_table' => false,
    ]);

    $subscriber = Subscriber::factory()->for($this->list, 'list')->create();
    app(FieldValueService::class)->set($subscriber, $field, 'Ada');

    Livewire::test(ListSubscribers::class)
        ->filterTable('list_id', $this->list->id)
        // The dynamic columns are built at component boot, so they appear on
        // the render following the filter change.
        ->call('$refresh')
        ->assertTableColumnExists('field_'.$field->id)
        ->assertTableColumnStateSet('field_'.$field->id, 'Ada', record: $subscriber->getKey());
});

it('filters by every subscriber status (F-2.2.4-002)', function (): void {
    $confirmed = Subscriber::factory()->for($this->list, 'list')->create();
    $unsubscribed = Subscriber::factory()->for($this->list, 'list')->unsubscribed()->create();
    $bounced = Subscriber::factory()->for($this->list, 'list')->bounced()->create();

    Livewire::test(ListSubscribers::class)
        ->filterTable('status', ['unsubscribed'])
        ->assertCanSeeTableRecords([$unsubscribed])
        ->assertCanNotSeeTableRecords([$confirmed, $bounced])
        ->resetTableFilters()
        ->filterTable('status', ['confirmed', 'bounced'])
        ->assertCanSeeTableRecords([$confirmed, $bounced])
        ->assertCanNotSeeTableRecords([$unsubscribed]);
});

it('searches by email and by custom field values (F-2.2.4-003)', function (): void {
    $field = ListField::factory()->for($this->list, 'list')->create(['tag' => 'CITY']);

    $byEmail = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'findme@x.example']);
    $byField = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'other@y.example']);
    $noise = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'noise@z.example']);

    app(FieldValueService::class)->set($byField, $field, 'Casablanca');

    Livewire::test(ListSubscribers::class)
        ->searchTable('findme')
        ->assertCanSeeTableRecords([$byEmail])
        ->assertCanNotSeeTableRecords([$byField, $noise])
        ->searchTable('Casablanca')
        ->assertCanSeeTableRecords([$byField])
        ->assertCanNotSeeTableRecords([$byEmail, $noise]);
});

it('sorts by table columns (F-2.2.4-005)', function (): void {
    $b = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'bbb@x.example']);
    $a = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'aaa@x.example']);

    Livewire::test(ListSubscribers::class)
        ->sortTable('email')
        ->assertCanSeeTableRecords([$a, $b], inOrder: true)
        ->sortTable('email', 'desc')
        ->assertCanSeeTableRecords([$b, $a], inOrder: true);
});

it('runs the row status actions through the model so cascades apply (F-2.2.4-006)', function (): void {
    $subscriber = Subscriber::factory()->for($this->list, 'list')->unconfirmed()->create();

    Livewire::test(ListSubscribers::class)
        ->callTableAction('confirm', $subscriber);

    expect($subscriber->refresh()->status)->toBe(SubscriberStatus::Confirmed)
        ->and($subscriber->confirmed_at)->not->toBeNull();

    Livewire::test(ListSubscribers::class)
        ->callTableAction('unsubscribe', $subscriber);

    expect($subscriber->refresh()->status)->toBe(SubscriberStatus::Unsubscribed)
        ->and($subscriber->unsubscribed_at)->not->toBeNull();

    Livewire::test(ListSubscribers::class)
        ->callTableAction('blacklist', $subscriber);

    expect($subscriber->refresh()->status)->toBe(SubscriberStatus::Blacklisted);

    Livewire::test(ListSubscribers::class)
        ->callTableAction('delete', $subscriber);

    expect(Subscriber::query()->whereKey($subscriber->id)->exists())->toBeFalse();
});

it('quick-adds a subscriber inline (F-2.2.4-010)', function (): void {
    Livewire::test(ListSubscribers::class)
        ->callAction('quick_add', data: [
            'list_id' => $this->list->id,
            'email' => 'Quick@X.example',
            'status' => 'confirmed',
        ])
        ->assertHasNoActionErrors();

    $subscriber = Subscriber::query()->where('list_id', $this->list->id)->firstOrFail();

    expect($subscriber->email)->toBe('quick@x.example')
        ->and($subscriber->status)->toBe(SubscriberStatus::Confirmed)
        ->and($subscriber->customer_id)->toBe($this->customer->id);
});

it('rejects quick-add duplicates for the same list', function (): void {
    Subscriber::factory()->for($this->list, 'list')->create(['email' => 'dupe@x.example']);

    Livewire::test(ListSubscribers::class)
        ->callAction('quick_add', data: [
            'list_id' => $this->list->id,
            'email' => 'dupe@x.example',
            'status' => 'confirmed',
        ])
        ->assertHasActionErrors(['email']);
});

it('saves the current search and status filter as a segment (F-2.2.4-004)', function (): void {
    Subscriber::factory()->for($this->list, 'list')->create(['email' => 'ada@corp.example']);

    Livewire::test(ListSubscribers::class)
        ->filterTable('list_id', $this->list->id)
        ->filterTable('status', ['confirmed'])
        ->searchTable('corp')
        ->callAction('save_segment', data: ['name' => 'Corp confirmed'])
        ->assertHasNoActionErrors();

    $segment = ListSegment::query()->where('name', 'Corp confirmed')->firstOrFail();

    expect($segment->list_id)->toBe($this->list->id)
        ->and($segment->match_type->value)->toBe('all')
        ->and($segment->conditions)->toHaveCount(2)
        ->and($segment->conditions->pluck('type')->map(fn ($t) => $t->value)->all())
        ->toBe(['field', 'status'])
        ->and(app(SegmentQueryBuilder::class)->count($segment))->toBe(1);
});

it('hides other tenants\' subscribers and blocks direct access', function (): void {
    $foreign = Subscriber::factory()->create(); // other customer + list

    Livewire::test(ListSubscribers::class)->assertCanNotSeeTableRecords([$foreign]);

    $this->get(SubscriberResource::getUrl('edit', ['record' => $foreign], panel: 'customer'))
        ->assertNotFound();
    $this->get(SubscriberResource::getUrl('view', ['record' => $foreign], panel: 'customer'))
        ->assertNotFound();
});
