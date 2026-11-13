<?php

declare(strict_types=1);

use App\Enums\ListPageType;
use App\Enums\SubscriberStatus;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Services\Lists\ListPageResolver;

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->list = EmailList::factory()->for($this->customer)->create();
    $this->subscriber = Subscriber::factory()->for($this->customer)->for($this->list, 'list')
        ->create(['email' => 'leaver@example.com']);

    $this->routeParams = [
        'list_uid' => $this->list->uid,
        'subscriber_uid' => $this->subscriber->uid,
    ];
});

// F-1.5-007: unsubscribe page with reason dropdown + free text.
it('renders the unsubscribe form with the reason dropdown and free text', function (): void {
    $this->get(route('lists.public.unsubscribe', $this->routeParams))
        ->assertOk()
        ->assertSee('Unsubscribe from '.$this->list->name)
        ->assertSee('name="reason"', false)
        ->assertSee('name="reason_text"', false)
        ->assertSee(__('public.lists.reasons.no_longer_interested'));
});

// F-1.5-007/008/009: the full three-page unsubscribe journey.
it('walks the unsubscribe journey: form, confirm page, processed, success page', function (): void {
    // Step 1 → confirm page (F-1.5-008) carrying the chosen reason.
    $confirm = $this->post(route('lists.public.unsubscribe.confirm', $this->routeParams), [
        'reason' => 'too_many_emails',
        'reason_text' => 'Twice a day is too much 😅',
    ]);

    $confirm->assertOk()
        ->assertSee('Confirm unsubscription')
        ->assertSee('leaver@example.com')
        ->assertSee('value="too_many_emails"', false)
        ->assertSee('Twice a day is too much 😅');

    // Still subscribed — nothing changed yet.
    expect($this->subscriber->refresh()->status)->toBe(SubscriberStatus::Confirmed);

    // Step 2 → the actual unsubscribe through the model (cascades fire).
    $this->post(route('lists.public.unsubscribe.process', $this->routeParams), [
        'reason' => 'too_many_emails',
        'reason_text' => 'Twice a day is too much 😅',
    ])->assertRedirect(route('lists.public.unsubscribe.success', $this->routeParams));

    $this->subscriber->refresh();
    expect($this->subscriber->status)->toBe(SubscriberStatus::Unsubscribed)
        ->and($this->subscriber->unsubscribed_at)->not->toBeNull()
        ->and($this->subscriber->unsubscribe_reason)->toBe('too_many_emails: Twice a day is too much 😅');

    // Step 3 → success page (F-1.5-009).
    $this->get(route('lists.public.unsubscribe.success', $this->routeParams))
        ->assertOk()
        ->assertSee('You have been unsubscribed')
        ->assertSee('leaver@example.com');
});

it('redirects an already-unsubscribed visitor straight to the success page', function (): void {
    $this->subscriber->status = SubscriberStatus::Unsubscribed;
    $this->subscriber->save();

    $this->get(route('lists.public.unsubscribe', $this->routeParams))
        ->assertRedirect(route('lists.public.unsubscribe.success', $this->routeParams));
});

// F-1.5-010: unsubscribe redirect URL override wins over the success page.
it('honors the unsubscribe success redirect URL override', function (): void {
    app(ListPageResolver::class)->save(
        $this->list, ListPageType::UnsubscribeSuccess, null, null, 'https://goodbye.example/bye',
    );

    $this->post(route('lists.public.unsubscribe.process', $this->routeParams), []);

    $this->get(route('lists.public.unsubscribe.success', $this->routeParams))
        ->assertRedirect('https://goodbye.example/bye');
});

// The subscriber-action cascade fires on the public path too (model events).
it('fires the on-unsubscribe cascade when unsubscribing publicly', function (): void {
    $target = EmailList::factory()->for($this->customer)->create();
    Subscriber::factory()->for($this->customer)->for($target, 'list')->create(['email' => 'leaver@example.com']);

    $this->list->update(['on_unsubscribe_unsubscribe_ids' => [$target->id]]);

    $this->post(route('lists.public.unsubscribe.process', $this->routeParams), []);

    expect(Subscriber::withoutTenancy()
        ->where('list_id', $target->id)
        ->where('email', 'leaver@example.com')
        ->firstOrFail()->status)->toBe(SubscriberStatus::Unsubscribed);
});
