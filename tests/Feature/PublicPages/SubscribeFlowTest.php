<?php

declare(strict_types=1);

use App\Enums\ListPageType;
use App\Enums\SubscriberStatus;
use App\Http\Controllers\Lists\PublicSubscribeController;
use App\Mail\Lists\SubscribeConfirmMail;
use App\Mail\Lists\WelcomeMail;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Services\Lists\ListPageResolver;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
});

// F-1.5-001: the hosted subscribe form page.
it('renders the subscribe form page with content, visible fields, honeypot and hosted flag', function (): void {
    $list = EmailList::factory()->for($this->customer)->create([
        'company_name' => 'Acme GmbH',
        'company_address_1' => 'Musterstrasse 1',
        'company_city' => 'Berlin',
        'company_country' => 'DE',
    ]);
    ListField::factory()->for($list, 'list')->create(['tag' => 'FNAME', 'label' => 'First name', 'sort_order' => 0]);
    ListField::factory()->for($list, 'list')->create(['tag' => 'SECRET', 'label' => 'Hidden one', 'visible_on_form' => false]);

    $response = $this->get(route('lists.public.subscribe', ['list_uid' => $list->uid]));

    $response->assertOk()
        ->assertSee('Subscribe to '.$list->name) // default content, [LIST_NAME] substituted
        ->assertSee('name="FNAME"', false)
        ->assertDontSee('name="SECRET"', false)
        ->assertSee(PublicSubscribeController::HONEYPOT_FIELD)
        ->assertSee('name="hosted"', false)
        ->assertSee('action="'.route('lists.forms.subscribe', ['list_uid' => $list->uid]).'"', false)
        // F-1.5-014: CAN-SPAM footer auto-injected from the Company tab.
        ->assertSee('Acme GmbH')
        ->assertSee('Musterstrasse 1');
});

// F-1.5-002/003/004/005: the full double opt-in journey.
it('walks the double opt-in journey: register, pending page, signed confirm link, welcome email', function (): void {
    Mail::fake();

    $list = EmailList::factory()->for($this->customer)->doubleOptIn()->create();

    // Register through the hosted form.
    $response = $this->post(route('lists.forms.subscribe', ['list_uid' => $list->uid]), [
        'email' => 'journey@example.com',
        'hosted' => '1',
    ]);

    $subscriber = Subscriber::withoutTenancy()
        ->where('list_id', $list->id)->where('email', 'journey@example.com')->firstOrFail();

    expect($subscriber->status)->toBe(SubscriberStatus::Unconfirmed);

    // → pending page (F-1.5-002), [SUBSCRIBER_EMAIL] substituted.
    $pendingUrl = route('lists.public.pending', ['list_uid' => $list->uid, 'subscriber_uid' => $subscriber->uid]);
    $response->assertRedirect($pendingUrl);
    $this->get($pendingUrl)->assertOk()->assertSee('journey@example.com');

    // The confirmation email is queued with the signed [CONFIRM_URL] (F-1.5-004).
    $confirmUrl = null;
    Mail::assertQueued(SubscribeConfirmMail::class, function (SubscribeConfirmMail $mail) use (&$confirmUrl, $list): bool {
        if (preg_match('~href="([^"]*confirm-subscription[^"]*)"~', $mail->htmlContent, $m) === 1) {
            $confirmUrl = html_entity_decode($m[1]);
        }

        return $mail->hasTo('journey@example.com')
            && str_contains((string) $mail->subjectLine, $list->name)
            && str_contains($mail->htmlContent, 'confirm-subscription');
    });

    expect($confirmUrl)->not->toBeNull()->and($confirmUrl)->toContain('signature=');

    // Tampered signature → 403, still unconfirmed (F-1.7-008 style hardening).
    $this->get($confirmUrl.'tampered')->assertForbidden();
    expect($subscriber->refresh()->status)->toBe(SubscriberStatus::Unconfirmed);

    // Genuine signed link → confirmed through the model + confirm page (F-1.5-003).
    $this->get($confirmUrl)
        ->assertOk()
        ->assertSee('Subscription confirmed');

    $subscriber->refresh();
    expect($subscriber->status)->toBe(SubscriberStatus::Confirmed)
        ->and($subscriber->confirmed_at)->not->toBeNull();

    // Welcome email fired post-confirmation (F-1.5-005).
    Mail::assertQueued(WelcomeMail::class, fn (WelcomeMail $mail): bool => $mail->hasTo('journey@example.com'));

    // Idempotent: re-visiting the link stays confirmed, no duplicate welcome.
    $this->get($confirmUrl)->assertOk();
    Mail::assertQueued(WelcomeMail::class, 1);
});

// Single opt-in: straight to the confirmed page + welcome email.
it('redirects single opt-in subscriptions to the confirmed page and queues the welcome email', function (): void {
    Mail::fake();

    $list = EmailList::factory()->for($this->customer)->create();

    $response = $this->post(route('lists.forms.subscribe', ['list_uid' => $list->uid]), [
        'email' => 'single@example.com',
        'hosted' => '1',
    ]);

    $subscriber = Subscriber::withoutTenancy()
        ->where('list_id', $list->id)->where('email', 'single@example.com')->firstOrFail();

    expect($subscriber->status)->toBe(SubscriberStatus::Confirmed);

    $response->assertRedirect(route('lists.public.subscribed', [
        'list_uid' => $list->uid, 'subscriber_uid' => $subscriber->uid,
    ]));

    Mail::assertQueued(WelcomeMail::class, fn (WelcomeMail $mail): bool => $mail->hasTo('single@example.com'));
    Mail::assertNotQueued(SubscribeConfirmMail::class);
});

// F-1.5-010: redirect URL overrides (per-page + the Redirect page type).
it('honors subscribe redirect URL overrides instead of rendering the page', function (): void {
    Mail::fake();

    $list = EmailList::factory()->for($this->customer)->create();
    $subscriber = Subscriber::factory()->for($this->customer)->for($list, 'list')->create();
    $resolver = app(ListPageResolver::class);

    // Per-page override on the confirm page.
    $resolver->save($list, ListPageType::SubscribeConfirm, null, null, 'https://external.example/done');

    $this->get(route('lists.public.subscribed', ['list_uid' => $list->uid, 'subscriber_uid' => $subscriber->uid]))
        ->assertRedirect('https://external.example/done');

    // The list-wide Redirect page type is the fallback for flow-completion pages.
    $resolver->reset($list, ListPageType::SubscribeConfirm);
    $resolver->save($list, ListPageType::Redirect, null, null, 'https://global.example/bye');

    $this->get(route('lists.public.subscribed', ['list_uid' => $list->uid, 'subscriber_uid' => $subscriber->uid]))
        ->assertRedirect('https://global.example/bye');
});

// utf8mb4 end-to-end (docs/ARCHITECTURE.md): CJK + emoji round-trip through page content.
it('round-trips CJK and emoji through customized public page content', function (): void {
    $list = EmailList::factory()->for($this->customer)->create();

    app(ListPageResolver::class)->save(
        $list,
        ListPageType::SubscribeForm,
        '<h1>ようこそ 歡迎 🎉 [LIST_NAME]</h1>',
        null,
        null,
    );

    $this->get(route('lists.public.subscribe', ['list_uid' => $list->uid]))
        ->assertOk()
        ->assertSee('ようこそ 歡迎 🎉', false);
});
