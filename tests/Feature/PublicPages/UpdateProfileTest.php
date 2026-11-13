<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Services\Lists\FieldValueService;

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->list = EmailList::factory()->for($this->customer)->create();
    $this->subscriber = Subscriber::factory()->for($this->customer)->for($this->list, 'list')
        ->create(['email' => 'profile@example.com']);
});

// F-1.5-006: the public update-profile form.
it('renders the update-profile form with visible_on_profile fields prefilled', function (): void {
    $shown = ListField::factory()->for($this->list, 'list')->create([
        'tag' => 'FNAME', 'label' => 'First name', 'visible_on_profile' => true,
    ]);
    ListField::factory()->for($this->list, 'list')->create([
        'tag' => 'INTERNAL', 'label' => 'Internal', 'visible_on_profile' => false,
    ]);
    app(FieldValueService::class)->set($this->subscriber, $shown, '田中 🌸');

    $this->get(route('lists.public.update_profile', [
        'list_uid' => $this->list->uid, 'subscriber_uid' => $this->subscriber->uid,
    ]))
        ->assertOk()
        ->assertSee('name="FNAME"', false)
        ->assertSee('田中 🌸')
        ->assertDontSee('name="INTERNAL"', false)
        ->assertSee('profile@example.com');
});

// F-1.5-006: saving updates email + field values through the services.
it('saves profile changes including the email address', function (): void {
    $field = ListField::factory()->for($this->list, 'list')->create([
        'tag' => 'FNAME', 'label' => 'First name', 'visible_on_profile' => true,
    ]);

    $this->post(route('lists.public.update_profile.save', [
        'list_uid' => $this->list->uid, 'subscriber_uid' => $this->subscriber->uid,
    ]), [
        'email' => 'New-Address@Example.com',
        'FNAME' => 'Renamed 🌟',
    ])->assertRedirect()->assertSessionHas('public_status', 'profile_updated');

    $this->subscriber->refresh();
    expect($this->subscriber->email)->toBe('new-address@example.com')
        ->and(app(FieldValueService::class)->get($this->subscriber, $field))->toBe('Renamed 🌟');
});

it('rejects an email already used by another subscriber of the list', function (): void {
    Subscriber::factory()->for($this->customer)->for($this->list, 'list')->create(['email' => 'taken@example.com']);

    $this->post(route('lists.public.update_profile.save', [
        'list_uid' => $this->list->uid, 'subscriber_uid' => $this->subscriber->uid,
    ]), ['email' => 'taken@example.com'])->assertSessionHasErrors('email');

    expect($this->subscriber->refresh()->email)->toBe('profile@example.com');
});

// Honeypot: bots pretend-succeed, nothing changes (F-1.5-016).
it('silently ignores bot submissions on the profile form', function (): void {
    $this->post(route('lists.public.update_profile.save', [
        'list_uid' => $this->list->uid, 'subscriber_uid' => $this->subscriber->uid,
    ]), [
        'email' => 'bot@example.com',
        \App\Http\Controllers\Lists\PublicSubscribeController::HONEYPOT_FIELD => 'http://spam.example',
    ])->assertRedirect()->assertSessionHas('public_status', 'profile_updated');

    expect($this->subscriber->refresh()->email)->toBe('profile@example.com');
});

// uid-as-secret access model: a wrong uid gets the branded 404, never data.
it('shows the branded 404 for an unknown subscriber uid', function (): void {
    $this->get(route('lists.public.update_profile', [
        'list_uid' => $this->list->uid, 'subscriber_uid' => (string) \Illuminate\Support\Str::uuid(),
    ]))
        ->assertNotFound()
        ->assertSee('List unavailable');
});
