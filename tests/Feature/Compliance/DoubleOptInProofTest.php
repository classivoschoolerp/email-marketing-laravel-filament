<?php

declare(strict_types=1);

use App\Enums\ListFieldType;
use App\Enums\OptInType;
use App\Enums\SubscriberStatus;
use App\Models\EmailList;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Services\Lists\FieldValueService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/*
 * WP-22 — F-4.5-004: double opt-in with IP + timestamp records. The WP-09
 * flow already implements it; this is the proving test that a complete,
 * auditable consent record exists after a real double opt-in journey.
 */

beforeEach(function (): void {
    $this->list = EmailList::factory()->create([
        'visible' => true,
        'status' => EmailList::STATUS_ACTIVE,
        'opt_in' => OptInType::Double,
        'gdpr_consent_text' => 'I agree to receive the newsletter.',
        'gdpr_consent_logging' => true,
    ]);

    $this->consentField = ListField::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->list->customer_id,
        'tag' => 'GDPR',
        'type' => ListFieldType::Consent,
        'visible_on_form' => true,
        'required' => true,
    ]);
});

// F-4.5-004
it('records the subscribe IP and timestamp on a double opt-in signup', function (): void {
    Carbon::setTestNow('2026-08-08 09:00:00');

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.21'])
        ->post(route('lists.forms.subscribe', ['list_uid' => $this->list->uid]), [
            'email' => 'proof@example.com',
            'GDPR' => '1',
            'hosted' => 1,
        ]);

    $subscriber = Subscriber::withoutTenancy()->where('email', 'proof@example.com')->firstOrFail();

    expect($subscriber->status)->toBe(SubscriberStatus::Unconfirmed)
        ->and($subscriber->subscribe_ip)->toBe('203.0.113.21')
        ->and($subscriber->ip)->toBe('203.0.113.21')
        ->and($subscriber->subscribed_at?->toDateTimeString())->toBe('2026-08-08 09:00:00')
        // Confirmation has not happened yet — no confirm proof.
        ->and($subscriber->confirm_ip)->toBeNull()
        ->and($subscriber->confirmed_at)->toBeNull();

    Carbon::setTestNow();
});

// F-4.5-004 — the second leg: the confirmation click.
it('records the confirm IP and timestamp when the opt-in link is clicked', function (): void {
    $subscriber = Subscriber::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->list->customer_id,
        'email' => 'proof@example.com',
        'status' => SubscriberStatus::Unconfirmed,
        'subscribe_ip' => '203.0.113.21',
    ]);

    Carbon::setTestNow('2026-08-08 09:05:00');

    $url = URL::signedRoute('lists.public.confirm', [
        'list_uid' => $this->list->uid,
        'subscriber_uid' => $subscriber->uid,
    ]);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.44'])->get($url)->assertOk();

    $subscriber = $subscriber->fresh();

    expect($subscriber->status)->toBe(SubscriberStatus::Confirmed)
        ->and($subscriber->confirm_ip)->toBe('198.51.100.44')
        ->and($subscriber->confirmed_at?->toDateTimeString())->toBe('2026-08-08 09:05:00')
        // The original subscribe proof survives the confirmation.
        ->and($subscriber->subscribe_ip)->toBe('203.0.113.21');

    Carbon::setTestNow();
});

// A tampered/unsigned confirm link must never produce a consent record.
it('refuses to confirm from an unsigned link', function (): void {
    $subscriber = Subscriber::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->list->customer_id,
        'status' => SubscriberStatus::Unconfirmed,
    ]);

    $this->get(route('lists.public.confirm', [
        'list_uid' => $this->list->uid,
        'subscriber_uid' => $subscriber->uid,
    ]))->assertForbidden();

    expect($subscriber->fresh()->status)->toBe(SubscriberStatus::Unconfirmed)
        ->and($subscriber->fresh()->confirm_ip)->toBeNull();
});

// F-4.5-001 — the consent itself is captured and provable.
it('captures the consent-field grant alongside the IP/timestamp proof', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.21'])
        ->post(route('lists.forms.subscribe', ['list_uid' => $this->list->uid]), [
            'email' => 'proof@example.com',
            'GDPR' => '1',
            'hosted' => 1,
        ]);

    $subscriber = Subscriber::withoutTenancy()->where('email', 'proof@example.com')->firstOrFail();

    expect(app(FieldValueService::class)->get($subscriber, $this->consentField))->toBeTrue();
});

// A required consent field must actually be granted (GDPR).
it('rejects a signup that does not grant a required consent field', function (): void {
    $this->post(route('lists.forms.subscribe', ['list_uid' => $this->list->uid]), [
        'email' => 'noconsent@example.com',
        'hosted' => 1,
    ]);

    expect(Subscriber::withoutTenancy()->where('email', 'noconsent@example.com')->exists())->toBeFalse();
});
