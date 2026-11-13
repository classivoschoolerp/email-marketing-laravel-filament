<?php

declare(strict_types=1);

use App\Enums\OptInType;
use App\Enums\SubscriberStatus;
use App\Filament\Customer\Resources\SubscriberResource;
use App\Filament\Customer\Resources\SubscriberResource\Pages\ListSubscribers;
use App\Mail\Lists\SubscribeConfirmMail;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/**
 * F-2.2.4-006 — "resend confirmation" subscriber row action: unconfirmed rows
 * only, WP-09's mailable + signed confirm URL, rate limited, tenant fenced.
 */
beforeEach(function (): void {
    Mail::fake();

    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->for($this->customer)->create([
        'opt_in' => OptInType::Double,
        'from_email' => 'hello@list.example',
        'from_name' => 'The List',
        'optin_confirm_expiry_hours' => 48,
    ]);
});

it('resends the double opt-in confirmation to an unconfirmed subscriber', function (): void {
    $subscriber = Subscriber::factory()->for($this->list, 'list')->unconfirmed()->create([
        'email' => 'pending@x.example',
    ]);

    Livewire::test(ListSubscribers::class)
        ->callTableAction('resend_confirmation', $subscriber)
        ->assertHasNoTableActionErrors();

    Mail::assertQueuedCount(1);
    Mail::assertQueued(
        SubscribeConfirmMail::class,
        fn (SubscribeConfirmMail $mail): bool => $mail->hasTo('pending@x.example')
            && $mail->fromEmail === 'hello@list.example'
            && str_contains($mail->htmlContent, '/confirm-subscription/'.$subscriber->uid)
            // The link is signed and expiry-bounded by the list setting.
            && str_contains($mail->htmlContent, 'signature=')
            && str_contains($mail->htmlContent, 'expires='),
    );

    // A resend never flips the status by itself.
    expect($subscriber->refresh()->status)->toBe(SubscriberStatus::Unconfirmed);
});

it('offers the action only for unconfirmed subscribers', function (): void {
    $unconfirmed = Subscriber::factory()->for($this->list, 'list')->unconfirmed()->create();
    $confirmed = Subscriber::factory()->for($this->list, 'list')->create();
    $unsubscribed = Subscriber::factory()->for($this->list, 'list')->unsubscribed()->create();

    Livewire::test(ListSubscribers::class)
        ->assertTableActionVisible('resend_confirmation', $unconfirmed)
        ->assertTableActionHidden('resend_confirmation', $confirmed)
        ->assertTableActionHidden('resend_confirmation', $unsubscribed);

    // Even called directly the service refuses a confirmed subscriber.
    SubscriberResource::resendConfirmation($confirmed);

    Mail::assertNothingQueued();
});

it('rate limits resends per subscriber', function (): void {
    $subscriber = Subscriber::factory()->for($this->list, 'list')->unconfirmed()->create();
    $other = Subscriber::factory()->for($this->list, 'list')->unconfirmed()->create();

    RateLimiter::clear('subscriber-confirm-resend:'.$subscriber->id);

    // 4 attempts, 3 allowed (SubscriberResource::RESEND_MAX_ATTEMPTS).
    for ($i = 0; $i < 4; $i++) {
        SubscriberResource::resendConfirmation($subscriber);
    }

    Mail::assertQueuedCount(SubscriberResource::RESEND_MAX_ATTEMPTS);

    // The limiter is per subscriber, not global.
    SubscriberResource::resendConfirmation($other);

    Mail::assertQueuedCount(SubscriberResource::RESEND_MAX_ATTEMPTS + 1);
});

it('never resends for another tenant\'s subscriber', function (): void {
    $foreign = Subscriber::factory()->unconfirmed()->create(); // different customer

    Livewire::test(ListSubscribers::class)
        ->assertCanNotSeeTableRecords([$foreign])
        ->callTableAction('resend_confirmation', $foreign)
        ->assertNotified();

    Mail::assertNothingQueued();
});
