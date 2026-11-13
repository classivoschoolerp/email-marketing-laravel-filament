<?php

declare(strict_types=1);

use App\Enums\ListFieldType;
use App\Enums\SubscriberStatus;
use App\Http\Controllers\Public\PrivacyController;
use App\Models\EmailList;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Services\Lists\FieldValueService;
use App\Settings\PrivacySettings;
use Illuminate\Support\Facades\URL;

/*
 * WP-22 — F-4.5-001 (GDPR portability): subscriber self-service data export
 * from the public update-profile page. Signed + rate-limited.
 */

beforeEach(function (): void {
    $this->list = EmailList::factory()->create([
        'visible' => true,
        'status' => EmailList::STATUS_ACTIVE,
        'gdpr_consent_text' => 'I agree to receive the newsletter.',
    ]);

    $this->consentField = ListField::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->list->customer_id,
        'tag' => 'GDPR',
        'type' => ListFieldType::Consent,
        'visible_on_profile' => true,
    ]);

    $this->subscriber = Subscriber::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->list->customer_id,
        'email' => 'portable@example.com',
        'status' => SubscriberStatus::Confirmed,
        'subscribe_ip' => '203.0.113.9',
        'confirm_ip' => '203.0.113.10',
    ]);

    app(FieldValueService::class)->set($this->subscriber, $this->consentField, true);
});

// F-4.5-001 — the link is offered on the subscriber's own profile page.
it('offers the download link on the public update-profile page', function (): void {
    $this->get(route('lists.public.update_profile', [
        'list_uid' => $this->list->uid,
        'subscriber_uid' => $this->subscriber->uid,
    ]))
        ->assertOk()
        ->assertSee(__('privacy.export.link'))
        ->assertSee('/privacy/lists/'.$this->list->uid.'/'.$this->subscriber->uid.'/export', escape: false);
});

it('hides the download link when portability is disabled', function (): void {
    $settings = app(PrivacySettings::class);
    $settings->data_export_enabled = false;
    $settings->save();

    $this->get(route('lists.public.update_profile', [
        'list_uid' => $this->list->uid,
        'subscriber_uid' => $this->subscriber->uid,
    ]))
        ->assertOk()
        ->assertDontSee(__('privacy.export.link'));
});

// The uid alone must not be enough — the URL is signed with a short TTL.
it('rejects an unsigned or tampered export link', function (): void {
    $this->get(route('privacy.export', [
        'list_uid' => $this->list->uid,
        'subscriber_uid' => $this->subscriber->uid,
    ]))->assertForbidden();
});

it('rejects an expired export link', function (): void {
    $url = URL::temporarySignedRoute('privacy.export', now()->subMinute(), [
        'list_uid' => $this->list->uid,
        'subscriber_uid' => $this->subscriber->uid,
    ]);

    $this->get($url)->assertForbidden();
});

// F-4.5-001 — the payload carries everything + the consent proof block.
it('downloads the full subscriber record as json', function (): void {
    $url = PrivacyController::exportUrl($this->subscriber, $this->list->uid);

    $response = $this->get($url);

    $response->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="subscriber-'.$this->subscriber->uid.'.json"');

    $payload = $response->json();

    expect($payload['email'])->toBe('portable@example.com')
        ->and($payload['uid'])->toBe($this->subscriber->uid)
        ->and($payload['list'])->toBe($this->list->name)
        ->and($payload['status'])->toBe(SubscriberStatus::Confirmed->value)
        ->and($payload['subscribe_ip'])->toBe('203.0.113.9')
        ->and($payload['confirm_ip'])->toBe('203.0.113.10')
        ->and($payload['fields']['GDPR'])->toBeTrue()
        // GDPR consent proof (F-4.5-001): text, when, from where.
        ->and($payload['consent']['text'])->toBe('I agree to receive the newsletter.')
        ->and($payload['consent']['granted_ip'])->toBe('203.0.113.10')
        ->and($payload['consent']['granted_at'])->not->toBeNull()
        // ...plus the audit trail of every later change (F-4.5-008).
        ->and($payload['consent']['changes'])->toHaveCount(1)
        ->and($payload['consent']['changes'][0]['field'])->toBe('GDPR')
        ->and($payload['consent']['changes'][0]['to'])->toBe('1');
});

it('404s the export endpoint when portability is disabled', function (): void {
    $url = PrivacyController::exportUrl($this->subscriber, $this->list->uid);

    $settings = app(PrivacySettings::class);
    $settings->data_export_enabled = false;
    $settings->save();

    $this->get($url)->assertNotFound();
});

// A signed link for one subscriber must not expose another's data.
it('never exports a subscriber that does not belong to the list', function (): void {
    $otherList = EmailList::factory()->create(['visible' => true, 'status' => EmailList::STATUS_ACTIVE]);
    $stranger = Subscriber::factory()->create([
        'list_id' => $otherList->id,
        'customer_id' => $otherList->customer_id,
    ]);

    $url = URL::temporarySignedRoute('privacy.export', now()->addMinutes(10), [
        'list_uid' => $this->list->uid,
        'subscriber_uid' => $stranger->uid,
    ]);

    $this->get($url)->assertNotFound();
});
