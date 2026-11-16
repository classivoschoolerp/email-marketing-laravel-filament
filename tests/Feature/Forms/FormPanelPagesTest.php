<?php

declare(strict_types=1);

use App\Enums\FormType;
use App\Enums\ListFieldType;
use App\Filament\Customer\Resources\FormResource;
use App\Filament\Customer\Resources\FormResource\Pages\CreateForm;
use App\Filament\Customer\Resources\FormResource\Pages\FormAnalyticsPage;
use App\Filament\Customer\Resources\FormResource\Pages\FormEmbedCode;
use App\Filament\Customer\Resources\FormResource\Pages\ListForms;
use App\Filament\Customer\Resources\FormResource\Pages\ManageFormFields;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Form;
use App\Models\FormField;
use App\Services\Forms\DisplayRules;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Tests\Support\AutomationFixtures as Fx;

/*
 * §2.7 panel pages render and save — the builder (F-2.7-003), the embed page
 * (F-2.7-011) and the analytics page (F-2.7-012), plus the cross-tenant
 * fence on every record page.
 */

beforeEach(function (): void {
    $this->customer = Fx::actingAsCustomer();
    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);
});

it('renders the forms index (F-2.7-001)', function (): void {
    Form::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'name' => 'Newsletter bar',
    ]);

    Livewire::test(ListForms::class)
        ->assertOk()
        ->assertSee('Newsletter bar');
});

it('creates a form with normalised rule documents through the panel', function (): void {
    Livewire::test(CreateForm::class)
        ->fillForm([
            'name' => 'Exit popup',
            'list_id' => $this->list->id,
            'type' => FormType::ExitIntent->value,
            'status' => 'active',
            'display_rules' => ['delay_seconds' => 3, 'visitors' => DisplayRules::VISITORS_NEW],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $form = Form::query()->where('name', 'Exit popup')->firstOrFail();

    expect($form->type)->toBe(FormType::ExitIntent)
        ->and($form->display_rules['delay_seconds'])->toBe(3)
        ->and($form->display_rules['visitors'])->toBe(DisplayRules::VISITORS_NEW)
        // Every key of the schema is present after normalisation.
        ->and(array_keys($form->display_rules))->toBe(array_keys(DisplayRules::defaults()))
        ->and($form->styles)->not->toBeNull()
        ->and($form->uid)->not->toBeEmpty();
});

it('adds a field through the builder page (F-2.7-003)', function (): void {
    $form = Form::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
    ]);

    Livewire::test(ManageFormFields::class, ['record' => $form->getKey()])
        ->assertOk()
        ->callTableAction('create', data: [
            'type' => ListFieldType::Phone->value,
            'label' => 'Phone number',
            'tag' => 'phone',
            'required' => true,
        ])
        ->assertHasNoTableActionErrors();

    $field = FormField::query()->where('form_id', $form->id)->firstOrFail();

    expect($field->tag)->toBe('PHONE')      // uppercased like every merge tag
        ->and($field->type)->toBe(ListFieldType::Phone)
        ->and($field->required)->toBeTrue()
        ->and((int) $field->customer_id)->toBe((int) $this->customer->id);
});

it('rejects a duplicate or reserved field tag in the builder', function (): void {
    $form = Form::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
    ]);

    FormField::factory()->create([
        'form_id' => $form->id,
        'customer_id' => $this->customer->id,
        'tag' => 'FNAME',
        'label' => 'First name',
    ]);

    Livewire::test(ManageFormFields::class, ['record' => $form->getKey()])
        ->callTableAction('create', data: ['type' => 'text', 'label' => 'Again', 'tag' => 'fname'])
        ->assertHasTableActionErrors(['tag']);

    Livewire::test(ManageFormFields::class, ['record' => $form->getKey()])
        ->callTableAction('create', data: ['type' => 'text', 'label' => 'Email again', 'tag' => 'EMAIL'])
        ->assertHasTableActionErrors(['tag']);

    expect(FormField::query()->where('form_id', $form->id)->count())->toBe(1);
});

it('renders the embed page with all three snippets (F-2.7-011)', function (): void {
    $form = Form::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
    ]);

    Livewire::test(FormEmbedCode::class, ['record' => $form->getKey()])
        ->assertOk()
        ->assertSee(route('forms.public.script', ['uid' => $form->uid]))
        ->assertSee(route('forms.public.show', ['uid' => $form->uid]))
        ->assertSee('&lt;form', false);
});

it('renders the analytics page (F-2.7-012)', function (): void {
    $form = Form::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
    ]);

    Livewire::test(FormAnalyticsPage::class, ['record' => $form->getKey()])
        ->assertOk()
        ->assertSee(__('forms.analytics.views'))
        ->assertSee(__('forms.analytics.conversion_rate'));
});

it('refuses every record page for another tenant\'s form', function (): void {
    $other = Customer::factory()->create();

    $foreign = app(CurrentCustomer::class)->runAs($other, function () use ($other): Form {
        $list = EmailList::factory()->create(['customer_id' => $other->id]);

        return Form::factory()->create(['customer_id' => $other->id, 'list_id' => $list->id]);
    });

    foreach ([FormEmbedCode::class, FormAnalyticsPage::class] as $page) {
        expect(fn () => Livewire::test($page, ['record' => $foreign->getKey()]))
            ->toThrow(ModelNotFoundException::class);
    }

    expect(fn () => FormResource::getUrl('edit', ['record' => $foreign]))->not->toThrow(Throwable::class);
    expect(Form::query()->whereKey($foreign->id)->exists())->toBeFalse();
});
