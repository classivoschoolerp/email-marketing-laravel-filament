<?php

declare(strict_types=1);

use App\Enums\ListFieldType;
use App\Enums\SubscriberStatus;
use App\Filament\Customer\Resources\SubscriberResource;
use App\Filament\Customer\Resources\SubscriberResource\Pages\CreateSubscriber;
use App\Filament\Customer\Resources\SubscriberResource\Pages\EditSubscriber;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\EmailList;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Services\Lists\FieldValueService;
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

it('creates a subscriber with custom field values rendered by type (F-2.2.6-001)', function (): void {
    $text = ListField::factory()->for($this->list, 'list')->create(['tag' => 'FNAME']);
    $number = ListField::factory()->for($this->list, 'list')->type(ListFieldType::Number)->create(['tag' => 'AGE']);
    $date = ListField::factory()->for($this->list, 'list')->type(ListFieldType::Date)->create(['tag' => 'DAY']);
    $consent = ListField::factory()->for($this->list, 'list')->type(ListFieldType::Consent)->create(['tag' => 'GDPR']);

    Livewire::test(CreateSubscriber::class)
        ->fillForm([
            'list_id' => $this->list->id,
            'email' => 'Typed@X.example',
            'status' => 'confirmed',
            'source' => 'manual',
            'field_values' => [
                $text->id => 'Ada',
                $number->id => 30,
                $date->id => '2020-05-01',
                $consent->id => true,
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $subscriber = Subscriber::query()->where('list_id', $this->list->id)->firstOrFail();
    $service = app(FieldValueService::class);

    expect($subscriber->email)->toBe('typed@x.example')
        ->and($subscriber->customer_id)->toBe($this->customer->id)
        ->and($subscriber->status)->toBe(SubscriberStatus::Confirmed)
        ->and($service->get($subscriber, $text))->toBe('Ada')
        ->and($service->get($subscriber, $number))->toBe(30)
        ->and($service->get($subscriber, $date)->format('Y-m-d'))->toBe('2020-05-01')
        ->and($service->get($subscriber, $consent))->toBeTrue();
});

it('enforces field validation config in the form (F-2.2.9-004)', function (): void {
    $number = ListField::factory()->for($this->list, 'list')->type(ListFieldType::Number)->create([
        'tag' => 'AGE',
        'validation' => ['min_value' => 18, 'max_value' => 99],
    ]);

    Livewire::test(CreateSubscriber::class)
        ->fillForm([
            'list_id' => $this->list->id,
            'email' => 'young@x.example',
            'status' => 'confirmed',
            'source' => 'manual',
            'field_values' => [$number->id => 5],
        ])
        ->call('create')
        ->assertHasFormErrors(['field_values.'.$number->id]);

    expect(Subscriber::query()->where('list_id', $this->list->id)->count())->toBe(0);
});

it('honours the status selector (F-2.2.6-002)', function (): void {
    Livewire::test(CreateSubscriber::class)
        ->fillForm([
            'list_id' => $this->list->id,
            'email' => 'listed@x.example',
            'status' => 'unsubscribed',
            'source' => 'manual',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Subscriber::query()->where('email', 'listed@x.example')->first()->status)
        ->toBe(SubscriberStatus::Unsubscribed);
});

it('skips confirmation on demand for double opt-in lists (F-2.2.6-003)', function (): void {
    $this->list->update(['opt_in' => 'double']);

    Livewire::test(CreateSubscriber::class)
        ->fillForm([
            'list_id' => $this->list->id,
            'email' => 'skip@x.example',
            'status' => 'unconfirmed',
            'source' => 'manual',
            'skip_confirmation' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $subscriber = Subscriber::query()->where('email', 'skip@x.example')->firstOrFail();

    expect($subscriber->status)->toBe(SubscriberStatus::Confirmed)
        ->and($subscriber->confirmed_at)->not->toBeNull();

    // Without the toggle the subscriber stays unconfirmed, awaiting the
    // confirmation email.
    Livewire::test(CreateSubscriber::class)
        ->fillForm([
            'list_id' => $this->list->id,
            'email' => 'wait@x.example',
            'status' => 'unconfirmed',
            'source' => 'manual',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Subscriber::query()->where('email', 'wait@x.example')->first()->status)
        ->toBe(SubscriberStatus::Unconfirmed);
});

it('rejects duplicates without merge and merges when requested (F-2.2.6-004)', function (): void {
    $field = ListField::factory()->for($this->list, 'list')->create(['tag' => 'FNAME']);
    $existing = Subscriber::factory()->for($this->list, 'list')->unconfirmed()->create(['email' => 'dupe@x.example']);
    app(FieldValueService::class)->set($existing, $field, 'Old');

    // Duplicate without merge → validation error.
    Livewire::test(CreateSubscriber::class)
        ->fillForm([
            'list_id' => $this->list->id,
            'email' => 'dupe@x.example',
            'status' => 'confirmed',
            'source' => 'manual',
        ])
        ->call('create')
        ->assertHasFormErrors(['email']);

    // Duplicate with merge → the existing row is updated, no new row.
    Livewire::test(CreateSubscriber::class)
        ->fillForm([
            'list_id' => $this->list->id,
            'email' => 'dupe@x.example',
            'status' => 'confirmed',
            'source' => 'manual',
            'merge_duplicate' => true,
            'field_values' => [$field->id => 'New'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Subscriber::query()->where('email', 'dupe@x.example')->count())->toBe(1)
        ->and($existing->refresh()->status)->toBe(SubscriberStatus::Confirmed)
        ->and(app(FieldValueService::class)->get($existing, $field))->toBe('New');
});

it('edits a subscriber, updating custom field values', function (): void {
    $field = ListField::factory()->for($this->list, 'list')->create(['tag' => 'FNAME']);
    $subscriber = Subscriber::factory()->for($this->list, 'list')->create();
    app(FieldValueService::class)->set($subscriber, $field, 'Before');

    Livewire::test(EditSubscriber::class, ['record' => $subscriber->getRouteKey()])
        ->assertFormSet(['field_values' => [$field->id => 'Before']])
        ->fillForm([
            'email' => 'renamed@x.example',
            'field_values' => [$field->id => 'After'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($subscriber->refresh()->email)->toBe('renamed@x.example')
        ->and(app(FieldValueService::class)->get($subscriber, $field))->toBe('After');
});

it('blocks creating subscribers over the max_subscribers quota', function (): void {
    $group = CustomerGroup::factory()->create();
    $group->limits()->create(['key' => 'max_subscribers', 'value' => 1]);
    $this->customer->update(['customer_group_id' => $group->id]);

    Subscriber::factory()->for($this->list, 'list')->create();

    expect($this->customer->refresh()->can('create', Subscriber::class))->toBeFalse();

    $this->get(SubscriberResource::getUrl('create', panel: 'customer'))->assertForbidden();
});
