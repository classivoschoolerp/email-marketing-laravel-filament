<?php

declare(strict_types=1);

use App\Enums\ListFieldType;
use App\Enums\OptInType;
use App\Filament\Customer\Resources\EmailListResource\Pages\ManageListFields;
use App\Filament\Customer\Resources\EmailListResource\Pages\ManageListForm;
use App\Filament\Customer\Resources\SubscriberResource\Pages\CreateSubscriber;
use App\Filament\Customer\Resources\SubscriberResource\Pages\EditSubscriber;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ListField;
use App\Models\ListForm;
use App\Models\Subscriber;
use App\Services\Lists\FieldValueService;
use App\Services\Lists\FormHtmlGenerator;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);
});

/*
|--------------------------------------------------------------------------
| F-2.2.9-005 — Unique-value enforcement
|--------------------------------------------------------------------------
*/

it('F-2.2.9-005 rejects a duplicate unique value on the public subscribe form', function (): void {
    $list = EmailList::factory()->for($this->customer)->create(['opt_in' => OptInType::Single]);
    $unique = ListField::factory()->for($list, 'list')->create([
        'tag' => 'VATID', 'label' => 'VAT id', 'is_unique' => true,
    ]);
    $shared = ListField::factory()->for($list, 'list')->create([
        'tag' => 'CITYNAME', 'label' => 'City', 'is_unique' => false,
    ]);

    $first = Subscriber::factory()->for($list, 'list')->create(['email' => 'first@example.com']);
    app(FieldValueService::class)->set($first, $unique, 'IT-0001');
    app(FieldValueService::class)->set($first, $shared, 'Rome');

    // Same unique value → rejected, nothing created.
    $this->post(route('lists.forms.subscribe', ['list_uid' => $list->uid]), [
        'email' => 'second@example.com',
        'VATID' => 'IT-0001',
        'CITYNAME' => 'Rome',
    ])->assertSessionHasErrors('VATID');

    expect(Subscriber::query()->where('list_id', $list->id)->count())->toBe(1);

    // Different unique value, same non-unique value → accepted.
    $this->post(route('lists.forms.subscribe', ['list_uid' => $list->uid]), [
        'email' => 'second@example.com',
        'VATID' => 'IT-0002',
        'CITYNAME' => 'Rome',
    ])->assertSessionHasNoErrors();

    $second = Subscriber::query()->where('list_id', $list->id)->where('email', 'second@example.com')->firstOrFail();

    expect(Subscriber::query()->where('list_id', $list->id)->count())->toBe(2)
        ->and(app(FieldValueService::class)->get($second, $unique))->toBe('IT-0002')
        ->and(app(FieldValueService::class)->get($second, $shared))->toBe('Rome');
});

it('F-2.2.9-005 lets the update-profile page keep its own unique value but not take another', function (): void {
    $list = EmailList::factory()->for($this->customer)->create();
    $unique = ListField::factory()->for($list, 'list')->create([
        'tag' => 'VATID', 'label' => 'VAT id', 'is_unique' => true, 'visible_on_profile' => true,
    ]);

    $owner = Subscriber::factory()->for($list, 'list')->create(['email' => 'owner@example.com']);
    app(FieldValueService::class)->set($owner, $unique, 'IT-0001');

    $other = Subscriber::factory()->for($list, 'list')->create(['email' => 'other@example.com']);
    app(FieldValueService::class)->set($other, $unique, 'IT-0002');

    $url = route('lists.public.update_profile.save', [
        'list_uid' => $list->uid,
        'subscriber_uid' => $other->uid,
    ]);

    // Taking the owner's value → rejected, value unchanged.
    $this->post($url, ['email' => 'other@example.com', 'VATID' => 'IT-0001'])
        ->assertSessionHasErrors('VATID');

    expect(app(FieldValueService::class)->get($other->refresh(), $unique))->toBe('IT-0002');

    // Re-saving its own value → allowed (a row never collides with itself).
    $this->post($url, ['email' => 'other@example.com', 'VATID' => 'IT-0002'])
        ->assertSessionHasNoErrors();

    expect(app(FieldValueService::class)->get($other->refresh(), $unique))->toBe('IT-0002');
});

it('F-2.2.9-005 enforces unique values on the panel create and edit forms', function (): void {
    $list = EmailList::factory()->for($this->customer)->create();
    $unique = ListField::factory()->for($list, 'list')->create([
        'tag' => 'VATID', 'label' => 'VAT id', 'is_unique' => true,
    ]);

    $taken = Subscriber::factory()->for($list, 'list')->create(['email' => 'taken@example.com']);
    app(FieldValueService::class)->set($taken, $unique, 'IT-0001');

    Livewire::test(CreateSubscriber::class)
        ->fillForm([
            'list_id' => $list->id,
            'email' => 'new@example.com',
            'field_values' => [$unique->id => 'IT-0001'],
        ])
        ->call('create')
        ->assertHasFormErrors(['field_values.'.$unique->id]);

    expect(Subscriber::query()->where('email', 'new@example.com')->exists())->toBeFalse();

    // The owner may re-save its own value on edit.
    Livewire::test(EditSubscriber::class, ['record' => $taken->getRouteKey()])
        ->fillForm(['field_values' => [$unique->id => 'IT-0001']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(FieldValueService::class)->get($taken->refresh(), $unique))->toBe('IT-0001');
});

it('F-2.2.9-005 exposes the unique flag on the field manager', function (): void {
    $list = EmailList::factory()->for($this->customer)->create();

    Livewire::test(ManageListFields::class, ['record' => $list->getRouteKey()])
        ->callTableAction('create', data: [
            'type' => ListFieldType::Text->value,
            'label' => 'VAT id',
            'tag' => 'VATID',
            'is_unique' => true,
        ])
        ->assertHasNoTableActionErrors();

    expect(ListField::query()->where('list_id', $list->id)->where('tag', 'VATID')->firstOrFail()->is_unique)
        ->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| F-2.2.11-006 — AJAX submit option
|--------------------------------------------------------------------------
*/

it('F-2.2.11-006 emits the AJAX submitter only when the option is on', function (): void {
    $list = EmailList::factory()->for($this->customer)->create();
    $form = ListForm::forList($list);

    expect(app(FormHtmlGenerator::class)->html($list, $form))
        ->not->toContain('data-emp-ajax')
        ->not->toContain('<div class="emp-message"')
        ->not->toContain('fetch(form.action');

    $form->update(['ajax' => true]);
    $html = app(FormHtmlGenerator::class)->html($list, $form->refresh());

    expect($html)->toContain('data-emp-ajax="1"')
        ->toContain('<div class="emp-message"')
        ->toContain("fetch(form.action")
        ->toContain("'Accept': 'application/json'")
        ->toContain('event.preventDefault();');
});

it('F-2.2.11-006 answers the subscribe endpoint with JSON when asked', function (): void {
    $list = EmailList::factory()->for($this->customer)->create(['opt_in' => OptInType::Single]);
    ListForm::forList($list)->update(['ajax' => true, 'success_url' => 'https://example.com/thanks']);

    // Success → 200 JSON, and the F-2.2.11-005 redirect travels back.
    $this->postJson(route('lists.forms.subscribe', ['list_uid' => $list->uid]), [
        'email' => 'ajax@example.com',
    ])
        ->assertOk()
        ->assertExactJson([
            'status' => 'success',
            'message' => __('lists.embed.ajax_success'),
            'redirect' => 'https://example.com/thanks',
        ]);

    expect(Subscriber::query()->where('list_id', $list->id)->where('email', 'ajax@example.com')->exists())
        ->toBeTrue();

    // Failure → 422 JSON with the field errors, and NO redirect happened.
    $response = $this->postJson(route('lists.forms.subscribe', ['list_uid' => $list->uid]), [
        'email' => 'not-an-email',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('status', 'error')
        ->assertJsonStructure(['status', 'message', 'errors' => ['email'], 'redirect']);

    expect(Subscriber::query()->where('list_id', $list->id)->count())->toBe(1);
});

it('F-2.2.11-006 saves the AJAX option from the embed form builder', function (): void {
    $list = EmailList::factory()->for($this->customer)->create();

    Livewire::test(ManageListForm::class, ['record' => $list->getRouteKey()])
        ->assertSuccessful()
        ->fillForm(['field_rows' => [], 'styled' => true, 'ajax' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(ListForm::forList($list->refresh())->ajax)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| F-2.2.9-001 — Field types: the P2 additions (file upload, geo-city)
|--------------------------------------------------------------------------
*/

it('F-2.2.9-001 offers every spec field type including file upload and city', function (): void {
    $options = ListFieldType::options();

    expect(array_keys($options))->toBe([
        'text', 'textarea', 'dropdown', 'multiselect', 'checkbox', 'checkbox_list',
        'radio', 'date', 'datetime', 'country', 'zone', 'phone', 'number', 'url',
        'email', 'consent', 'rating', 'hidden', 'file', 'city',
    ])
        ->and($options['file'])->toBe(__('lists.field_type.file'))
        ->and($options['city'])->toBe(__('lists.field_type.city'));
});

it('F-2.2.9-001 stores a file-upload field on the tenant asset path and renders a city field', function (): void {
    Storage::fake('public');

    $list = EmailList::factory()->for($this->customer)->create(['opt_in' => OptInType::Single]);
    $file = ListField::factory()->for($list, 'list')->create([
        'tag' => 'CV', 'label' => 'CV', 'type' => ListFieldType::File,
    ]);
    $city = ListField::factory()->for($list, 'list')->create([
        'tag' => 'CITYNAME', 'label' => 'City', 'type' => ListFieldType::City,
    ]);

    // The generated snippet renders both and switches to multipart (D-26 upload).
    $html = app(FormHtmlGenerator::class)->html($list, ListForm::forList($list));

    expect($html)->toContain('enctype="multipart/form-data"')
        ->toContain('<input type="file" id="emp-cv" name="CV"')
        ->toContain('name="CITYNAME"');

    $this->post(route('lists.forms.subscribe', ['list_uid' => $list->uid]), [
        'email' => 'upload@example.com',
        'CV' => UploadedFile::fake()->create('cv.pdf', 12, 'application/pdf'),
        'CITYNAME' => 'Bologna',
    ])->assertSessionHasNoErrors();

    $subscriber = Subscriber::query()->where('list_id', $list->id)->where('email', 'upload@example.com')->firstOrFail();
    $storedPath = (string) app(FieldValueService::class)->get($subscriber, $file);

    expect($storedPath)->toStartWith('customers/'.$this->customer->uid.'/list-fields/'.$list->uid.'/')
        ->and($storedPath)->toEndWith('.pdf')
        ->and(Storage::disk('public')->exists($storedPath))->toBeTrue()
        ->and(app(FieldValueService::class)->get($subscriber, $city))->toBe('Bologna');
});

/*
 * WP-42 coordinator — the LISTTOOLS handoff for F-2.2.9-005. Unique-value
 * enforcement has to hold on the IMPORT path too, or the import becomes the
 * one way to plant a duplicate that the subscribe form, the update-profile
 * page and the panel all refuse.
 */
it('F-2.2.9-005 enforces unique field values on the import path', function (): void {
    $list = EmailList::factory()->for($this->customer)->create(['opt_in' => OptInType::Single]);
    $field = ListField::factory()->for($list, 'list')->create([
        'tag' => 'VATID', 'label' => 'VAT id', 'is_unique' => true,
    ]);

    $importer = app(\App\Services\Imports\SubscriberImporter::class);
    $import = fn (string $email, string $vat): array => $importer->importRow(
        $list,
        $email,
        ['VATID' => $vat],
        \App\Enums\SubscriberStatus::Confirmed,
        'update',
    );

    expect($import('a@x.test', 'IT-0001')['result'])
        ->toBe(\App\Services\Imports\SubscriberImporter::RESULT_IMPORTED);

    // A different address may not reuse the value.
    $collision = $import('b@x.test', 'IT-0001');

    expect($collision['result'])->toBe(\App\Services\Imports\SubscriberImporter::RESULT_FAILED)
        ->and($collision['errors'])->toBe([__('lists.fields.not_unique', ['label' => $field->label])])
        ->and(Subscriber::query()->where('list_id', $list->id)->count())->toBe(1);

    // A free value still imports.
    expect($import('b@x.test', 'IT-0002')['result'])
        ->toBe(\App\Services\Imports\SubscriberImporter::RESULT_IMPORTED);

    // Re-importing the SAME row with its OWN value is not a collision.
    expect($import('a@x.test', 'IT-0001')['result'])
        ->toBe(\App\Services\Imports\SubscriberImporter::RESULT_UPDATED);
});
