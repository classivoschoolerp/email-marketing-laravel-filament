<?php

declare(strict_types=1);

use App\Enums\OptInType;
use App\Enums\SubscriberSource;
use App\Enums\SubscriberStatus;
use App\Filament\Customer\Resources\EmailListResource\Pages\ManageListForm;
use App\Http\Controllers\Lists\PublicSubscribeController;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ListField;
use App\Models\ListForm;
use App\Models\Subscriber;
use App\Services\Lists\FieldValueService;
use App\Services\Lists\FormHtmlGenerator;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);
});

// F-2.2.11-001/002/003/004: generated HTML, field order, variants, styling.
it('generates the subscribe form HTML with ordered fields, honeypot and embed variants', function (): void {
    $list = EmailList::factory()->for($this->customer)->create();
    $a = ListField::factory()->for($list, 'list')->create(['tag' => 'AFIELD', 'label' => 'Alpha', 'sort_order' => 0]);
    $b = ListField::factory()->for($list, 'list')->create(['tag' => 'BFIELD', 'label' => 'Beta', 'sort_order' => 1]);
    ListField::factory()->for($list, 'list')->create(['tag' => 'HIDDENF', 'label' => 'Never', 'visible_on_form' => false]);

    $form = ListForm::forList($list);

    // Defaults: every visible_on_form field, in sort order.
    expect($form->field_ids)->toBe([$a->id, $b->id]);

    $generator = app(FormHtmlGenerator::class);
    $html = $generator->html($list, $form);

    expect($html)->toContain('action="'.route('lists.forms.subscribe', ['list_uid' => $list->uid]).'"')
        ->and($html)->toContain('name="email"')
        ->and($html)->toContain('name="AFIELD"')
        ->and($html)->toContain('name="BFIELD"')
        ->and($html)->not->toContain('name="HIDDENF"')
        ->and($html)->toContain(PublicSubscribeController::HONEYPOT_FIELD)
        ->and($html)->toContain('<style>') // styled by default (F-2.2.11-004)
        ->and(strpos($html, 'name="AFIELD"'))->toBeLessThan(strpos($html, 'name="BFIELD"'));

    // Custom order + raw markup.
    $form->update(['field_ids' => [$b->id, $a->id], 'styled' => false]);
    $raw = $generator->html($list, $form->refresh());

    expect($raw)->not->toContain('<style>')
        ->and(strpos($raw, 'name="BFIELD"'))->toBeLessThan(strpos($raw, 'name="AFIELD"'));

    // Iframe + JS embed variants (F-2.2.11-003).
    expect($generator->iframe($list))->toContain('/lists/'.$list->uid.'/subscribe')
        ->and($generator->javascript($list))->toContain('/lists/'.$list->uid.'/subscribe')
        ->and($generator->javascript($list))->toStartWith('<script>');
});

// F-2.2.11-002/004/005: the builder page persists the configuration.
it('saves the embed form configuration from the builder page', function (): void {
    $list = EmailList::factory()->for($this->customer)->create();
    $field = ListField::factory()->for($list, 'list')->create(['tag' => 'FN', 'label' => 'First name']);

    Livewire::test(ManageListForm::class, ['record' => $list->getRouteKey()])
        ->assertSuccessful()
        ->fillForm([
            'field_rows' => [['field_id' => $field->id]],
            'styled' => false,
            'success_url' => 'https://example.com/thanks',
            'error_url' => 'https://example.com/oops',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $form = ListForm::forList($list->refresh());
    expect($form->field_ids)->toBe([$field->id])
        ->and($form->styled)->toBeFalse()
        ->and($form->success_url)->toBe('https://example.com/thanks')
        ->and($form->error_url)->toBe('https://example.com/oops');
});

// The public endpoint: double opt-in → unconfirmed (spec §2.2.11 + §1.5).
it('creates an unconfirmed subscriber for a double opt-in list and fires cascades', function (): void {
    $cascadeTarget = EmailList::factory()->for($this->customer)->create();
    $list = EmailList::factory()->for($this->customer)->create([
        'opt_in' => OptInType::Double,
        'on_subscribe_subscribe_ids' => [$cascadeTarget->id],
    ]);
    ListField::factory()->for($list, 'list')->create(['tag' => 'FNAME', 'label' => 'First name']);

    $response = $this->post(route('lists.forms.subscribe', ['list_uid' => $list->uid]), [
        'email' => 'Join@Example.com',
        'FNAME' => '田中 🌸',
    ]);

    $response->assertRedirect();

    $subscriber = Subscriber::query()->where('list_id', $list->id)->where('email', 'join@example.com')->firstOrFail();
    $field = ListField::query()->where('list_id', $list->id)->where('tag', 'FNAME')->firstOrFail();

    expect($subscriber->status)->toBe(SubscriberStatus::Unconfirmed)
        ->and($subscriber->source)->toBe(SubscriberSource::Form)
        ->and(app(FieldValueService::class)->get($subscriber, $field))->toBe('田中 🌸');

    // Subscriber-action cascade fired on all code paths (F-2.2.2-015).
    expect(Subscriber::query()->where('list_id', $cascadeTarget->id)->where('email', 'join@example.com')->exists())
        ->toBeTrue();
});

// Single opt-in → confirmed immediately.
it('creates a confirmed subscriber for a single opt-in list and honors the success redirect', function (): void {
    $list = EmailList::factory()->for($this->customer)->create(['opt_in' => OptInType::Single]);
    ListForm::forList($list)->update(['success_url' => 'https://example.com/welcome']);

    $this->post(route('lists.forms.subscribe', ['list_uid' => $list->uid]), ['email' => 'single@example.com'])
        ->assertRedirect('https://example.com/welcome');

    expect(Subscriber::query()->where('email', 'single@example.com')->firstOrFail()->status)
        ->toBe(SubscriberStatus::Confirmed);
});

// Honeypot rejection: a filled hidden field creates nothing.
it('silently drops bot submissions that fill the honeypot', function (): void {
    $list = EmailList::factory()->for($this->customer)->create();

    $this->post(route('lists.forms.subscribe', ['list_uid' => $list->uid]), [
        'email' => 'bot@example.com',
        PublicSubscribeController::HONEYPOT_FIELD => 'http://spam.example',
    ])->assertRedirect();

    expect(Subscriber::withoutTenancy()->where('email', 'bot@example.com')->exists())->toBeFalse();
});

// Validation + duplicate + hidden-list handling.
it('validates input, rejects duplicates and 404s hidden or unknown lists', function (): void {
    $list = EmailList::factory()->for($this->customer)->create();
    ListForm::forList($list)->update(['error_url' => 'https://example.com/error']);

    // Invalid email → error redirect, nothing stored.
    $this->post(route('lists.forms.subscribe', ['list_uid' => $list->uid]), ['email' => 'nope'])
        ->assertRedirect('https://example.com/error');
    expect(Subscriber::withoutTenancy()->where('list_id', $list->id)->count())->toBe(0);

    // Already subscribed (active) → error redirect, still one row.
    Subscriber::factory()->for($list, 'list')->create(['email' => 'dup@example.com']);
    $this->post(route('lists.forms.subscribe', ['list_uid' => $list->uid]), ['email' => 'dup@example.com'])
        ->assertRedirect('https://example.com/error');
    expect(Subscriber::withoutTenancy()->where('list_id', $list->id)->count())->toBe(1);

    // Hidden list → 404.
    $hidden = EmailList::factory()->for($this->customer)->create(['visible' => false]);
    $this->post(route('lists.forms.subscribe', ['list_uid' => $hidden->uid]), ['email' => 'x@example.com'])
        ->assertNotFound();

    // Unknown uid → 404.
    $this->post(route('lists.forms.subscribe', ['list_uid' => 'no-such-list']), ['email' => 'x@example.com'])
        ->assertNotFound();
});

// The endpoint is rate-limited (spec §2.2.11 route hardening).
it('rate-limits the public subscribe endpoint', function (): void {
    $list = EmailList::factory()->for($this->customer)->create();

    foreach (range(1, 15) as $i) {
        $this->post(route('lists.forms.subscribe', ['list_uid' => $list->uid]), [
            'email' => "rl{$i}@example.com",
        ])->assertRedirect();
    }

    $this->post(route('lists.forms.subscribe', ['list_uid' => $list->uid]), ['email' => 'rl16@example.com'])
        ->assertStatus(429);
});
