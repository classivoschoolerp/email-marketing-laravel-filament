<?php

declare(strict_types=1);

use App\Enums\ListPageType;
use App\Enums\SubscriberStatus;
use App\Mail\Lists\SubscribeConfirmMail;
use App\Mail\Lists\WelcomeMail;
use App\Models\ConsentEvent;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\GlobalBlacklistEmail;
use App\Models\Subscriber;
use App\Services\Lists\ListPageResolver;
use App\Services\Lists\PublicPageRenderer;
use Illuminate\Support\Facades\Mail;

/**
 * WP-42 §1.5 F-1.5-013 — public resubscribe page reached from the person's
 * own {uid} link.
 */
beforeEach(function (): void {
    Mail::fake();

    $this->customer = Customer::factory()->create();
    $this->list = EmailList::factory()->for($this->customer)->create(['name' => 'Weekly digest']);

    $this->subscriber = Subscriber::factory()->for($this->customer)->for($this->list, 'list')
        ->unsubscribed()
        ->create([
            'email' => 'comeback@example.com',
            'subscribed_at' => now()->subYear(),
            'subscribe_ip' => '203.0.113.7',
            'unsubscribe_reason' => 'too_many_emails',
        ]);

    $this->routeParams = [
        'list_uid' => $this->list->uid,
        'subscriber_uid' => $this->subscriber->uid,
    ];
});

// F-1.5-013: the page renders for an unsubscribed person.
it('F-1.5-013 renders the resubscribe page for an unsubscribed subscriber', function (): void {
    $this->get(route('lists.public.resubscribe', $this->routeParams))
        ->assertOk()
        ->assertSee('Resubscribe to Weekly digest')
        ->assertSee('comeback@example.com')
        ->assertSee(__('public.lists.resubscribe_button'))
        ->assertSee('action="'.route('lists.public.resubscribe.process', $this->routeParams).'"', false);
});

// F-1.5-013 + F-4.5-004: single opt-in → confirmed, with a NEW subscribe IP
// and timestamp as the fresh consent proof.
it('F-1.5-013 puts a single opt-in subscriber back to confirmed with a fresh consent proof', function (): void {
    $before = now()->subMinute();

    $this->post(route('lists.public.resubscribe.process', $this->routeParams))
        ->assertRedirect(route('lists.public.subscribed', $this->routeParams));

    $this->subscriber->refresh();

    expect($this->subscriber->status)->toBe(SubscriberStatus::Confirmed)
        ->and($this->subscriber->confirmed_at)->not->toBeNull()
        // F-4.5-004: new subscribe IP + timestamp, not the year-old pair.
        ->and($this->subscriber->subscribe_ip)->toBe('127.0.0.1')
        ->and($this->subscriber->subscribed_at->greaterThan($before))->toBeTrue();

    Mail::assertQueued(WelcomeMail::class, fn (WelcomeMail $m): bool => $m->hasTo('comeback@example.com'));
    Mail::assertNotQueued(SubscribeConfirmMail::class);

    // §4.5 F-4.5-008: the regained consent is in the audit trail.
    $event = ConsentEvent::withoutTenancy()->where('field', 'list:'.$this->list->uid)->firstOrFail();
    expect($event->old_value)->toBe('0')
        ->and($event->new_value)->toBe('1')
        ->and($event->source)->toBe(ConsentEvent::SOURCE_PUBLIC)
        ->and($event->subscriber_id)->toBe($this->subscriber->id);
});

// F-1.5-013: double opt-in → unconfirmed + a fresh confirmation email.
it('F-1.5-013 puts a double opt-in subscriber back to unconfirmed and re-sends the confirmation email', function (): void {
    $this->list->update(['opt_in' => 'double']);

    $this->post(route('lists.public.resubscribe.process', $this->routeParams))
        ->assertRedirect(route('lists.public.pending', $this->routeParams));

    $this->subscriber->refresh();

    expect($this->subscriber->status)->toBe(SubscriberStatus::Unconfirmed)
        // The express-consent proof starts empty again: only the signed
        // confirm route may write it (F-4.5-004).
        ->and($this->subscriber->confirmed_at)->toBeNull()
        ->and($this->subscriber->confirm_ip)->toBeNull()
        ->and($this->subscriber->subscribe_ip)->toBe('127.0.0.1');

    Mail::assertQueued(SubscribeConfirmMail::class, function (SubscribeConfirmMail $m): bool {
        return $m->hasTo('comeback@example.com') && str_contains($m->htmlContent, 'confirm-subscription');
    });
    Mail::assertNotQueued(WelcomeMail::class);
});

// D-74: a blacklisted address is never resubscribed.
it('F-1.5-013 refuses to resubscribe a blacklisted address', function (): void {
    GlobalBlacklistEmail::query()->create(['email' => 'comeback@example.com']);

    // The page explains itself and carries no resubscribe form at all.
    $this->get(route('lists.public.resubscribe', $this->routeParams))
        ->assertOk()
        ->assertSee(__('public.lists.resubscribe_blocked'))
        ->assertDontSee('action="'.route('lists.public.resubscribe.process', $this->routeParams).'"', false);

    $this->post(route('lists.public.resubscribe.process', $this->routeParams))
        ->assertRedirect()
        ->assertSessionHasErrors('email');

    expect($this->subscriber->refresh()->status)->toBe(SubscriberStatus::Unsubscribed);
    Mail::assertNothingQueued();
});

// F-1.5-013: the endpoint takes no address — it can only ever act on the row
// the uid points at, so it can never re-add somebody else.
it('F-1.5-013 ignores any posted email and never re-adds somebody else', function (): void {
    $victim = Subscriber::factory()->for($this->customer)->for($this->list, 'list')
        ->unsubscribed()->create(['email' => 'victim@example.com']);

    $this->post(route('lists.public.resubscribe.process', $this->routeParams), [
        'email' => 'victim@example.com',
    ])->assertRedirect(route('lists.public.subscribed', $this->routeParams));

    expect($this->subscriber->refresh()->email)->toBe('comeback@example.com')
        ->and($this->subscriber->status)->toBe(SubscriberStatus::Confirmed)
        ->and($victim->refresh()->status)->toBe(SubscriberStatus::Unsubscribed);
});

// Idempotent: an active member has nothing to resubscribe.
it('F-1.5-013 redirects an already-subscribed visitor away from the resubscribe page', function (): void {
    $this->subscriber->status = SubscriberStatus::Confirmed;
    $this->subscriber->save();

    $this->get(route('lists.public.resubscribe', $this->routeParams))
        ->assertRedirect(route('lists.public.subscribed', $this->routeParams));

    $this->post(route('lists.public.resubscribe.process', $this->routeParams))
        ->assertRedirect(route('lists.public.subscribed', $this->routeParams));

    Mail::assertNothingQueued();
});

// F-1.5-013 wiring: [RESUBSCRIBE_URL] now points at this page whenever the
// subscriber is known — the unsubscribe-success default carries the link.
it('F-1.5-013 resolves [RESUBSCRIBE_URL] to the resubscribe page on the unsubscribe success page', function (): void {
    $this->get(route('lists.public.unsubscribe.success', $this->routeParams))
        ->assertOk()
        ->assertSee(route('lists.public.resubscribe', $this->routeParams), false);

    // Without a subscriber (list-level content) it stays the subscribe form.
    expect(app(PublicPageRenderer::class)->tagValues($this->list)['[RESUBSCRIBE_URL]'])
        ->toBe(e(route('lists.public.subscribe', ['list_uid' => $this->list->uid])));
});

// §3.16 / F-1.5-017: the page joins the editable list-pages system.
it('F-1.5-013 is an editable list page with custom CSS and a redirect override', function (): void {
    app(ListPageResolver::class)->save(
        $this->list,
        ListPageType::Resubscribe,
        '<h1>おかえり 🎉 [LIST_NAME]</h1>',
        null,
        null,
        '.emp-re{color:blue}',
        'window.empRe=1;',
    );

    $this->get(route('lists.public.resubscribe', $this->routeParams))
        ->assertOk()
        ->assertSee('おかえり 🎉 Weekly digest', false)
        ->assertSee('.emp-re{color:blue}', false)
        ->assertSee('window.empRe=1;', false);

    app(ListPageResolver::class)->save(
        $this->list, ListPageType::Resubscribe, null, null, 'https://back.example/again',
    );

    $this->get(route('lists.public.resubscribe', $this->routeParams))
        ->assertRedirect('https://back.example/again');
});

// uid-as-secret access model: a foreign subscriber uid gets the branded 404.
it('F-1.5-013 shows the branded 404 for a subscriber uid of another tenant', function (): void {
    $otherCustomer = Customer::factory()->create();
    $otherList = EmailList::factory()->for($otherCustomer)->create();
    $otherSubscriber = Subscriber::factory()->for($otherCustomer)->for($otherList, 'list')
        ->unsubscribed()->create();

    $this->get(route('lists.public.resubscribe', [
        'list_uid' => $this->list->uid,
        'subscriber_uid' => $otherSubscriber->uid,
    ]))->assertNotFound()->assertSee('List unavailable');

    $this->post(route('lists.public.resubscribe.process', [
        'list_uid' => $this->list->uid,
        'subscriber_uid' => $otherSubscriber->uid,
    ]))->assertNotFound();

    expect($otherSubscriber->refresh()->status)->toBe(SubscriberStatus::Unsubscribed);
});
