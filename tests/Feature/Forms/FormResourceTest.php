<?php

declare(strict_types=1);

use App\Enums\FormStatus;
use App\Enums\FormType;
use App\Enums\ListFieldType;
use App\Filament\Customer\Resources\FormResource;
use App\Filament\Customer\Resources\FormResource\Pages\ListForms;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\CustomerGroupLimit;
use App\Models\CustomerGroupPermission;
use App\Models\EmailList;
use App\Models\FieldOption;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormStatDaily;
use App\Models\FormSubmission;
use App\Models\Subscriber;
use App\Policies\FormPolicy;
use App\Services\Forms\DisplayRules;
use App\Services\Forms\FormStyles;
use App\Services\Forms\FrequencyRules;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Support\Facades\Gate;
use Tests\Support\AutomationFixtures as Fx;

/*
 * §2.7 forms — panel side.
 *
 * F-2.7-001 index with submission counts + conversion rate
 * F-2.7-002 the six form types
 * F-2.7-003 builder over the list custom field types
 * F-2.7-005/006/007 the three normalised rule documents
 * plus the §3.5 quota gate and the cross-tenant fence.
 */

beforeEach(function (): void {
    $this->customer = Fx::actingAsCustomer();
    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);
});

it('offers all six form types (F-2.7-002)', function (): void {
    expect(array_keys(FormType::options()))->toBe([
        'embedded', 'popup', 'slide_in', 'floating_bar', 'full_screen', 'exit_intent',
    ]);

    foreach (FormType::cases() as $type) {
        $form = Form::factory()->create([
            'customer_id' => $this->customer->id,
            'list_id' => $this->list->id,
            'type' => $type,
        ]);

        expect($form->refresh()->type)->toBe($type)
            ->and($form->type->isOverlay())->toBe($type !== FormType::Embedded);
    }

    expect(FormType::ExitIntent->impliesExitIntent())->toBeTrue()
        ->and(FormType::Popup->impliesExitIntent())->toBeFalse();
});

it('shows submission counts and the conversion rate on the index (F-2.7-001)', function (): void {
    $form = Form::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'name' => 'Newsletter popup',
    ]);

    FormStatDaily::withoutTenancy()->create([
        'customer_id' => $this->customer->id,
        'form_id' => $form->id,
        'stat_date' => now()->toDateString(),
        'source' => 'direct',
        'views' => 200,
        'submissions' => 0,
    ]);

    foreach (['a@example.test', 'b@example.test', 'c@example.test'] as $email) {
        $subscriber = Subscriber::factory()->create([
            'customer_id' => $this->customer->id,
            'list_id' => $this->list->id,
            'email' => $email,
        ]);

        FormSubmission::withoutTenancy()->create([
            'customer_id' => $this->customer->id,
            'form_id' => $form->id,
            'subscriber_id' => $subscriber->id,
            'email' => $email,
        ]);
    }

    $row = Form::query()
        ->withCount('submissions')
        ->withSum('stats as views_sum', 'views')
        ->findOrFail($form->id);

    expect((int) $row->submissions_count)->toBe(3)
        ->and((int) $row->views_sum)->toBe(200)
        ->and(ListForms::conversionRate($row))->toBe('1.5%');

    // A form nobody has seen reports "—", never a division by zero.
    $fresh = Form::query()->withCount('submissions')->withSum('stats as views_sum', 'views')
        ->findOrFail(Form::factory()->create([
            'customer_id' => $this->customer->id,
            'list_id' => $this->list->id,
        ])->id);

    expect(ListForms::conversionRate($fresh))->toBe('—');
});

it('builds fields from the whole list custom field type library (F-2.7-003)', function (): void {
    $form = Form::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
    ]);

    foreach (ListFieldType::cases() as $index => $type) {
        FormField::factory()->create([
            'form_id' => $form->id,
            'customer_id' => $this->customer->id,
            'type' => $type,
            'tag' => 'F_'.strtoupper($type->value),
            'label' => $type->value,
            'sort_order' => $index,
        ]);
    }

    expect($form->fields()->count())->toBe(count(ListFieldType::cases()));

    // Ordering is the builder's drag & drop result; the renderer follows it.
    $tags = collect($form->orderedFields())->pluck('tag')->all();
    expect($tags[0])->toBe('F_TEXT');

    // Choice options live on the shared polymorphic table.
    $dropdown = $form->fields()->where('type', ListFieldType::Dropdown->value)->firstOrFail();
    FieldOption::withoutTenancy()->create([
        'customer_id' => $this->customer->id,
        'owner_type' => FormField::class,
        'owner_id' => $dropdown->id,
        'label' => 'Weekly',
        'value' => 'weekly',
        'sort_order' => 0,
    ]);

    expect($dropdown->refresh()->optionValues())->toBe(['weekly']);
});

it('normalises display, frequency and styling documents on save (F-2.7-005/006/007)', function (): void {
    $data = FormResource::normalizeJsonDocuments([
        'display_rules' => [
            'pages_mode' => 'nonsense',
            'pages' => ['/pricing', ['url' => '/blog'], '   '],
            'delay_seconds' => '-5',
            'scroll_percent' => '900',
            'exit_intent' => '1',
            'min_page_views' => '3',
            'devices' => ['mobile', 'toaster'],
            'visitors' => 'returning',
        ],
        'frequency_rules' => ['hide_days_after_close' => '14', 'hide_after_submit' => '0'],
        'styles' => ['background_color' => 'javascript:alert(1)', 'font' => 'comic', 'width' => '99999', 'image_url' => 'javascript:x'],
    ]);

    expect($data['display_rules'])->toBe([
        'pages_mode' => DisplayRules::PAGES_ALL,
        'pages' => ['/pricing', '/blog'],
        'delay_seconds' => 0,
        'scroll_percent' => 100,
        'exit_intent' => true,
        'min_page_views' => 3,
        'devices' => ['mobile'],
        'visitors' => DisplayRules::VISITORS_RETURNING,
    ]);

    expect($data['frequency_rules']['hide_days_after_close'])->toBe(14)
        ->and($data['frequency_rules']['hide_after_submit'])->toBeFalse();

    // Styling never becomes an injection point: bad colours/fonts/urls fall
    // back to the validated defaults.
    expect($data['styles']['background_color'])->toBe('#ffffff')
        ->and($data['styles']['font'])->toBe('system')
        ->and($data['styles']['width'])->toBe(1200)
        ->and($data['styles']['image_url'])->toBeNull();

    expect(FrequencyRules::defaults())->toHaveKeys([
        'hide_days_after_close', 'hide_after_submit', 'hide_after_submit_days', 'max_shows_per_visitor',
    ]);
});

it('emits only literal validated CSS from the styling editor (F-2.7-007)', function (): void {
    $form = Form::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'styles' => [
            'background_color' => '#101820',
            'button_color' => '</style><script>alert(1)</script>',
            'corner_radius' => 20,
            'shadow' => 'lg',
        ],
    ]);

    $css = FormStyles::css($form);

    expect($css)->toContain('#101820')
        ->and($css)->toContain('border-radius:20px')
        ->and($css)->not->toContain('<script')
        ->and($css)->not->toContain('</style')
        ->and($css)->toContain('.emp-form-'.FormStyles::scope($form));
});

it('gates creation on the forms.enabled permission and the max_forms limit (§3.5)', function (): void {
    $group = CustomerGroup::factory()->create();
    $this->customer->forceFill(['customer_group_id' => $group->id])->save();
    $this->customer->refresh();

    CustomerGroupPermission::query()->create([
        'customer_group_id' => $group->id,
        'key' => FormPolicy::CUSTOMER_PERMISSION,
        'allowed' => false,
    ]);

    expect(Gate::forUser($this->customer)->allows('viewAny', Form::class))->toBeFalse()
        ->and(Gate::forUser($this->customer)->allows('create', Form::class))->toBeFalse();

    CustomerGroupPermission::query()
        ->where('customer_group_id', $group->id)
        ->where('key', FormPolicy::CUSTOMER_PERMISSION)
        ->update(['allowed' => true]);
    CustomerGroupLimit::query()->create([
        'customer_group_id' => $group->id,
        'key' => FormPolicy::LIMIT_KEY,
        'value' => 1,
    ]);

    $this->customer->refresh();
    expect(Gate::forUser($this->customer)->allows('create', Form::class))->toBeTrue();

    Form::factory()->create(['customer_id' => $this->customer->id, 'list_id' => $this->list->id]);

    $this->customer->refresh();
    expect(Gate::forUser($this->customer)->allows('create', Form::class))->toBeFalse();
});

it('denies every cross-tenant read and write of a form', function (): void {
    $other = Customer::factory()->create();

    $foreignForm = app(CurrentCustomer::class)->runAs($other, function () use ($other): Form {
        $list = EmailList::factory()->create(['customer_id' => $other->id]);

        return Form::factory()->create(['customer_id' => $other->id, 'list_id' => $list->id]);
    });

    // Tenancy scope hides it entirely…
    expect(Form::query()->whereKey($foreignForm->id)->exists())->toBeFalse();

    // …and the policy refuses it even when the row is handed over directly.
    expect(Gate::forUser($this->customer)->allows('view', $foreignForm))->toBeFalse()
        ->and(Gate::forUser($this->customer)->allows('update', $foreignForm))->toBeFalse()
        ->and(Gate::forUser($this->customer)->allows('delete', $foreignForm))->toBeFalse();
});

it('keeps a paused or draft form off every public endpoint', function (): void {
    $form = Form::factory()->draft()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
    ]);

    expect($form->isPublic())->toBeFalse();

    $this->get(route('forms.public.show', ['uid' => $form->uid]))->assertNotFound();
    $this->get(route('forms.public.script', ['uid' => $form->uid]))->assertNotFound();
    $this->post(route('forms.public.submit', ['uid' => $form->uid]), ['email' => 'x@example.test'])
        ->assertNotFound();

    $form->forceFill(['status' => FormStatus::Active])->save();

    $this->get(route('forms.public.show', ['uid' => $form->uid]))->assertOk();
});
