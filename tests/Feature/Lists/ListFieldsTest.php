<?php

declare(strict_types=1);

use App\Enums\ListFieldType;
use App\Filament\Customer\Resources\EmailListResource\Pages\ManageListFields;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Services\Lists\FieldValueService;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->for($this->customer)->create();
});

function fieldsPage(): Testable
{
    return Livewire::test(ManageListFields::class, ['record' => test()->list->getRouteKey()]);
}

it('creates a field with flags and an uppercase merge tag (F-2.2.9-002)', function (): void {
    fieldsPage()
        ->callTableAction('create', data: [
            'type' => ListFieldType::Text->value,
            'label' => 'First name',
            'tag' => 'fname',
            'default_value' => 'friend',
            'help_text' => 'Given name',
            'required' => true,
            'visible_on_form' => true,
            'visible_on_profile' => true,
            'show_in_table' => true,
        ])
        ->assertHasNoTableActionErrors();

    $field = ListField::query()->where('list_id', $this->list->id)->firstOrFail();

    expect($field->tag)->toBe('FNAME')
        ->and($field->label)->toBe('First name')
        ->and($field->required)->toBeTrue()
        ->and($field->show_in_table)->toBeTrue()
        ->and($field->customer_id)->toBe($this->customer->id);
});

it('rejects reserved tags such as EMAIL (F-2.2.9-006)', function (): void {
    fieldsPage()
        ->callTableAction('create', data: [
            'type' => ListFieldType::Text->value,
            'label' => 'Email again',
            'tag' => 'email',
        ])
        ->assertHasTableActionErrors(['tag']);

    expect(ListField::query()->where('list_id', $this->list->id)->count())->toBe(0);
});

it('rejects duplicate tags per list, case-insensitively', function (): void {
    ListField::factory()->for($this->list, 'list')->create(['tag' => 'FNAME']);

    fieldsPage()
        ->callTableAction('create', data: [
            'type' => ListFieldType::Text->value,
            'label' => 'Duplicate',
            'tag' => 'fname',
        ])
        ->assertHasTableActionErrors(['tag']);
});

it('manages options for choice types (F-2.2.9-003)', function (): void {
    $undoRepeaterFake = Repeater::fake();

    fieldsPage()
        ->callTableAction('create', data: [
            'type' => ListFieldType::Dropdown->value,
            'label' => 'Plan',
            'tag' => 'PLAN',
            'options' => [
                ['label' => 'Free', 'value' => 'free'],
                ['label' => 'Pro', 'value' => 'pro'],
            ],
        ])
        ->assertHasNoTableActionErrors();

    $undoRepeaterFake();

    $field = ListField::query()->where('tag', 'PLAN')->firstOrFail();

    expect($field->options)->toHaveCount(2)
        ->and($field->options->pluck('value')->all())->toBe(['free', 'pro'])
        ->and($field->options->first()->customer_id)->toBe($this->customer->id);
});

it('derives validation rules from the field config (F-2.2.9-004)', function (): void {
    $service = app(FieldValueService::class);

    $text = ListField::factory()->for($this->list, 'list')->create([
        'validation' => ['min_length' => 3, 'max_length' => 5, 'regex' => '/^[a-z]+$/'],
    ]);

    expect($service->validateValue($text, 'abcd'))->toBe([])
        ->and($service->validateValue($text, 'ab'))->not->toBe([])
        ->and($service->validateValue($text, 'abcdef'))->not->toBe([])
        ->and($service->validateValue($text, 'ABCD'))->not->toBe([]);

    $number = ListField::factory()->for($this->list, 'list')->type(ListFieldType::Number)->create([
        'tag' => 'AGE',
        'validation' => ['min_value' => 18, 'max_value' => 99],
    ]);

    expect($service->validateValue($number, 30))->toBe([])
        ->and($service->validateValue($number, 5))->not->toBe([])
        ->and($service->validateValue($number, 'abc'))->not->toBe([]);

    $date = ListField::factory()->for($this->list, 'list')->type(ListFieldType::Date)->create([
        'tag' => 'BIRTHDAY',
        'validation' => ['min_date' => '2000-01-01', 'max_date' => '2020-12-31'],
    ]);

    expect($service->validateValue($date, '2010-06-15'))->toBe([])
        ->and($service->validateValue($date, '1999-01-01'))->not->toBe([]);

    $required = ListField::factory()->for($this->list, 'list')->create(['tag' => 'REQ', 'required' => true]);

    expect($service->validateValue($required, null))->not->toBe([]);
});

it('reorders fields via drag and drop ordering (F-2.2.9-007)', function (): void {
    $a = ListField::factory()->for($this->list, 'list')->create(['tag' => 'AAA', 'sort_order' => 0]);
    $b = ListField::factory()->for($this->list, 'list')->create(['tag' => 'BBB', 'sort_order' => 1]);
    $c = ListField::factory()->for($this->list, 'list')->create(['tag' => 'CCC', 'sort_order' => 2]);

    fieldsPage()->call('reorderTable', [(string) $c->id, (string) $a->id, (string) $b->id]);

    expect($c->refresh()->sort_order)->toBe(1)
        ->and($a->refresh()->sort_order)->toBe(2)
        ->and($b->refresh()->sort_order)->toBe(3);
});

it('deletes a field after the data-loss warning, removing stored values (F-2.2.9-008)', function (): void {
    $field = ListField::factory()->for($this->list, 'list')->create(['tag' => 'DROPME']);
    $subscriber = Subscriber::factory()->for($this->list, 'list')->create();
    app(FieldValueService::class)->set($subscriber, $field, 'bye');

    expect($subscriber->fieldValues()->count())->toBe(1);

    fieldsPage()->callTableAction('delete', $field);

    expect(ListField::query()->whereKey($field->id)->exists())->toBeFalse()
        ->and($subscriber->fieldValues()->count())->toBe(0);
});

it('stores and reads typed values through the EAV store', function (): void {
    $service = app(FieldValueService::class);
    $subscriber = Subscriber::factory()->for($this->list, 'list')->create();

    $multi = ListField::factory()->for($this->list, 'list')->type(ListFieldType::Multiselect)->create(['tag' => 'TOPICS']);
    $bool = ListField::factory()->for($this->list, 'list')->type(ListFieldType::Consent)->create(['tag' => 'CONSENT']);
    $date = ListField::factory()->for($this->list, 'list')->type(ListFieldType::Date)->create(['tag' => 'DAY']);

    $service->set($subscriber, $multi, ['a', 'b']);
    $service->set($subscriber, $bool, true);
    $service->set($subscriber, $date, '2026-01-15');

    expect($service->get($subscriber, $multi))->toBe(['a', 'b'])
        ->and($service->get($subscriber, $bool))->toBeTrue()
        ->and($service->get($subscriber, $date)->format('Y-m-d'))->toBe('2026-01-15')
        ->and($service->byTag($subscriber))->toHaveKeys(['TOPICS', 'CONSENT', 'DAY']);

    // Clearing deletes the row.
    $service->set($subscriber, $multi, []);
    expect($subscriber->fieldValues()->where('list_field_id', $multi->id)->exists())->toBeFalse();
});
