<?php

declare(strict_types=1);

use App\Enums\ListPageType;
use App\Enums\SendFrequency;
use App\Enums\SubscriberStatus;
use App\Http\Controllers\Lists\PublicSubscribeController;
use App\Mail\Lists\SubscribeConfirmMail;
use App\Mail\Lists\WelcomeMail;
use App\Models\ConsentEvent;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\GlobalBlacklistEmail;
use App\Models\Subscriber;
use App\Services\Lists\ListPageResolver;
use App\Services\Lists\SubscriberPreferences;
use Illuminate\Support\Facades\Mail;

/**
 * WP-42 §1.5 F-1.5-012 — public preference center: choose topics (= the
 * customer's own lists) and a send frequency instead of a full unsubscribe.
 */
beforeEach(function (): void {
    Mail::fake();

    $this->customer = Customer::factory()->create();
    $this->list = EmailList::factory()->for($this->customer)->create(['name' => 'Weekly digest']);
    $this->otherTopic = EmailList::factory()->for($this->customer)->create(['name' => 'Product news']);

    $this->subscriber = Subscriber::factory()->for($this->customer)->for($this->list, 'list')
        ->create(['email' => 'picky@example.com']);

    $this->routeParams = [
        'list_uid' => $this->list->uid,
        'subscriber_uid' => $this->subscriber->uid,
    ];
});

// F-1.5-012: the page itself — editable content, topics, frequency choices.
it('F-1.5-012 renders the preference center with the customer topics and frequency options', function (): void {
    $response = $this->get(route('lists.public.preferences', $this->routeParams));

    $response->assertOk()
        // Content comes from the editable preference-center list page, with
        // [SUBSCRIBER_EMAIL] / [LIST_NAME] substituted.
        ->assertSee('Your email preferences')
        ->assertSee('picky@example.com')
        ->assertSee('Weekly digest')
        // Both of the customer's lists are offered as topics; the one the
        // subscriber belongs to is pre-ticked, the other is not.
        ->assertSee('value="'.$this->list->uid.'"', false)
        ->assertSee('value="'.$this->otherTopic->uid.'"', false)
        ->assertSee('Product news')
        // Frequency radios (F-1.5-012 "frequency").
        ->assertSee('name="frequency"', false)
        ->assertSee(__('public.lists.frequencies.every_send'))
        ->assertSee(__('public.lists.frequencies.weekly'))
        ->assertSee(__('public.lists.frequencies.monthly'))
        ->assertSee(__('public.lists.frequencies.paused'))
        ->assertSee('name="pause_days"', false)
        ->assertSee(PublicSubscribeController::HONEYPOT_FIELD);

    // The already-subscribed topic is the checked one — and only that one.
    $html = (string) $response->getContent();

    expect($html)->toMatch('~value="'.preg_quote((string) $this->list->uid, '~').'"[^>]*\schecked~')
        ->and($html)->not->toMatch('~value="'.preg_quote((string) $this->otherTopic->uid, '~').'"[^>]*\schecked~')
        // "Every email" is the effective default while nothing was chosen.
        ->and($html)->toMatch('~value="every_send"[^>]*\schecked~');
});

// F-1.5-012: the unsubscribe page offers the preference center as the way out
// that is not a full unsubscribe.
it('F-1.5-012 links to the preference center from the unsubscribe page', function (): void {
    $this->get(route('lists.public.unsubscribe', $this->routeParams))
        ->assertOk()
        ->assertSee(__('public.lists.preferences_link'))
        ->assertSee(route('lists.public.preferences', $this->routeParams), false);
});

// F-1.5-012: topics are the customer's lists — ticking one joins it (through
// the model, honoring its opt-in mode), unticking one leaves it.
it('F-1.5-012 joins and leaves topics and stores the exact resulting membership', function (): void {
    $this->otherTopic->update(['opt_in' => 'double']);

    $extra = EmailList::factory()->for($this->customer)->create(['name' => 'Offers']);
    $extraRow = Subscriber::factory()->for($this->customer)->for($extra, 'list')
        ->create(['email' => 'picky@example.com']);

    // Keep the current list + join the double opt-in topic, drop "Offers".
    $this->post(route('lists.public.preferences.save', $this->routeParams), [
        'topics' => [$this->list->uid, $this->otherTopic->uid],
        'frequency' => SendFrequency::EverySend->value,
    ])->assertRedirect()->assertSessionHas('public_status', 'preferences_saved');

    // Joined: a brand new row on the double opt-in list → unconfirmed.
    $joined = Subscriber::withoutTenancy()
        ->where('list_id', $this->otherTopic->id)
        ->where('email', 'picky@example.com')
        ->firstOrFail();

    expect($joined->status)->toBe(SubscriberStatus::Unconfirmed)
        ->and($joined->subscribe_ip)->toBe('127.0.0.1');

    Mail::assertQueued(SubscribeConfirmMail::class, fn (SubscribeConfirmMail $m): bool => $m->hasTo('picky@example.com'));

    // Left: the unticked list is unsubscribed with the preference-center reason.
    $extraRow->refresh();
    expect($extraRow->status)->toBe(SubscriberStatus::Unsubscribed)
        ->and($extraRow->unsubscribe_reason)->toBe(SubscriberPreferences::LEAVE_REASON)
        ->and($extraRow->unsubscribed_at)->not->toBeNull();

    // Untouched: the current list stays exactly as it was.
    expect($this->subscriber->refresh()->status)->toBe(SubscriberStatus::Confirmed);

    // §4.5 F-4.5-008: both membership changes are in the consent audit trail.
    $events = ConsentEvent::withoutTenancy()->get();

    expect($events->firstWhere('field', 'list:'.$this->otherTopic->uid))->not->toBeNull()
        ->and($events->firstWhere('field', 'list:'.$this->otherTopic->uid)->new_value)->toBe('1')
        ->and($events->firstWhere('field', 'list:'.$this->otherTopic->uid)->source)->toBe(ConsentEvent::SOURCE_PUBLIC)
        ->and($events->firstWhere('field', 'list:'.$extra->uid))->not->toBeNull()
        ->and($events->firstWhere('field', 'list:'.$extra->uid)->old_value)->toBe('1')
        ->and($events->firstWhere('field', 'list:'.$extra->uid)->new_value)->toBe('0');
});

// F-1.5-012: a single opt-in topic joins straight to confirmed + welcome mail.
it('F-1.5-012 joins a single opt-in topic as confirmed and queues the welcome email', function (): void {
    $this->post(route('lists.public.preferences.save', $this->routeParams), [
        'topics' => [$this->list->uid, $this->otherTopic->uid],
        'frequency' => SendFrequency::EverySend->value,
    ])->assertRedirect();

    $joined = Subscriber::withoutTenancy()
        ->where('list_id', $this->otherTopic->id)
        ->where('email', 'picky@example.com')
        ->firstOrFail();

    expect($joined->status)->toBe(SubscriberStatus::Confirmed)
        ->and($joined->confirmed_at)->not->toBeNull();

    Mail::assertQueued(WelcomeMail::class, fn (WelcomeMail $m): bool => $m->hasTo('picky@example.com'));
    Mail::assertNotQueued(SubscribeConfirmMail::class);
});

// F-1.5-012: the frequency preference is stored and readable.
it('F-1.5-012 stores the chosen send frequency on the subscriber', function (): void {
    $this->post(route('lists.public.preferences.save', $this->routeParams), [
        'topics' => [$this->list->uid],
        'frequency' => SendFrequency::Monthly->value,
    ])->assertRedirect()->assertSessionHas('public_status', 'preferences_saved');

    $this->subscriber->refresh();

    expect($this->subscriber->send_frequency)->toBe(SendFrequency::Monthly)
        ->and($this->subscriber->frequency_paused_until)->toBeNull()
        ->and($this->subscriber->send_frequency->minimumDaysBetweenSends())->toBe(30)
        // Still a member — this is the whole point of F-1.5-012.
        ->and($this->subscriber->status)->toBe(SubscriberStatus::Confirmed);

    // …and the stored choice comes back pre-selected on the page.
    $html = (string) $this->get(route('lists.public.preferences', $this->routeParams))
        ->assertOk()
        ->getContent();

    expect($html)->toMatch('~value="monthly"[^>]*\schecked~')
        ->and($html)->not->toMatch('~value="every_send"[^>]*\schecked~');
});

// F-1.5-012: "pause for N days" stores an expiry instant, not a day count.
it('F-1.5-012 stores a pause window and rejects a pause with no day count', function (): void {
    $this->post(route('lists.public.preferences.save', $this->routeParams), [
        'topics' => [$this->list->uid],
        'frequency' => SendFrequency::Paused->value,
    ])->assertSessionHasErrors('pause_days');

    expect($this->subscriber->refresh()->send_frequency)->toBeNull();

    $this->post(route('lists.public.preferences.save', $this->routeParams), [
        'topics' => [$this->list->uid],
        'frequency' => SendFrequency::Paused->value,
        'pause_days' => 30,
    ])->assertRedirect()->assertSessionHas('public_status', 'preferences_saved');

    $this->subscriber->refresh();

    expect($this->subscriber->send_frequency)->toBe(SendFrequency::Paused)
        ->and($this->subscriber->frequency_paused_until)->not->toBeNull()
        ->and($this->subscriber->frequency_paused_until->toDateString())
        ->toBe(now()->addDays(30)->toDateString());
});

// D-05 / docs/ARCHITECTURE.md rule 3: another tenant's lists are neither shown nor
// joinable, even when their uid is posted by hand.
it('F-1.5-012 never exposes or joins another tenant list', function (): void {
    $otherCustomer = Customer::factory()->create();
    $foreign = EmailList::factory()->for($otherCustomer)->create(['name' => 'Foreign topic']);

    $this->get(route('lists.public.preferences', $this->routeParams))
        ->assertOk()
        ->assertDontSee('Foreign topic')
        ->assertDontSee($foreign->uid);

    $this->post(route('lists.public.preferences.save', $this->routeParams), [
        'topics' => [$this->list->uid, $foreign->uid],
        'frequency' => SendFrequency::EverySend->value,
    ])->assertRedirect();

    expect(Subscriber::withoutTenancy()->where('list_id', $foreign->id)->count())->toBe(0);
});

// D-74: a blacklisted address is never added to a new topic; leaving works.
it('F-1.5-012 refuses to join a topic for a blacklisted address', function (): void {
    GlobalBlacklistEmail::query()->create(['email' => 'picky@example.com']);

    $this->post(route('lists.public.preferences.save', $this->routeParams), [
        'topics' => [$this->list->uid, $this->otherTopic->uid],
        'frequency' => SendFrequency::EverySend->value,
    ])->assertRedirect();

    expect(Subscriber::withoutTenancy()->where('list_id', $this->otherTopic->id)->count())->toBe(0);

    // Leaving is always allowed — suppression must not trap anyone.
    $this->post(route('lists.public.preferences.save', $this->routeParams), [
        'topics' => [],
        'frequency' => SendFrequency::EverySend->value,
    ])->assertRedirect();

    expect($this->subscriber->refresh()->status)->toBe(SubscriberStatus::Unsubscribed);
});

// F-1.5-016: the honeypot silently no-ops on the preference form too.
it('F-1.5-012 silently ignores bot submissions on the preference form', function (): void {
    $this->post(route('lists.public.preferences.save', $this->routeParams), [
        'topics' => [],
        'frequency' => SendFrequency::Monthly->value,
        PublicSubscribeController::HONEYPOT_FIELD => 'http://spam.example',
    ])->assertRedirect()->assertSessionHas('public_status', 'preferences_saved');

    $this->subscriber->refresh();
    expect($this->subscriber->status)->toBe(SubscriberStatus::Confirmed)
        ->and($this->subscriber->send_frequency)->toBeNull();
});

// §3.16 / F-1.5-017: the page joins the editable list-pages system.
it('F-1.5-012 is an editable list page with custom CSS and a redirect override', function (): void {
    app(ListPageResolver::class)->save(
        $this->list,
        ListPageType::PreferenceCenter,
        '<h1>選んでください 🎛 [LIST_NAME]</h1>',
        null,
        null,
        '.emp{color:red}',
        'window.empPrefs=1;',
    );

    $this->get(route('lists.public.preferences', $this->routeParams))
        ->assertOk()
        ->assertSee('選んでください 🎛 Weekly digest', false)
        ->assertSee('.emp{color:red}', false)
        ->assertSee('window.empPrefs=1;', false);

    app(ListPageResolver::class)->save(
        $this->list, ListPageType::PreferenceCenter, null, null, 'https://prefs.example/elsewhere',
    );

    $this->get(route('lists.public.preferences', $this->routeParams))
        ->assertRedirect('https://prefs.example/elsewhere');
});

// uid-as-secret access model: a foreign subscriber uid gets the branded 404.
it('F-1.5-012 shows the branded 404 for a subscriber uid of another tenant', function (): void {
    $otherCustomer = Customer::factory()->create();
    $otherList = EmailList::factory()->for($otherCustomer)->create();
    $otherSubscriber = Subscriber::factory()->for($otherCustomer)->for($otherList, 'list')->create();

    $this->get(route('lists.public.preferences', [
        'list_uid' => $this->list->uid,
        'subscriber_uid' => $otherSubscriber->uid,
    ]))->assertNotFound()->assertSee('List unavailable');
});
