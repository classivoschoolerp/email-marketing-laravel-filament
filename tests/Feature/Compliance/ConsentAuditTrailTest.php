<?php

declare(strict_types=1);

use App\Enums\ListFieldType;
use App\Enums\SubscriberStatus;
use App\Filament\Admin\Resources\ConsentEventResource as AdminConsentEvents;
use App\Filament\Customer\Resources\ConsentEventResource as CustomerConsentEvents;
use App\Models\ConsentEvent;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Lists\FieldValueService;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;

/*
 * WP-22 — F-4.5-008: audit trail of consent changes. Written by the
 * ConsentEventObserver on every consent-field write, wherever it comes from.
 */

beforeEach(function (): void {
    $this->list = EmailList::factory()->create([
        'visible' => true,
        'status' => EmailList::STATUS_ACTIVE,
    ]);

    $this->consentField = ListField::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->list->customer_id,
        'tag' => 'GDPR',
        'type' => ListFieldType::Consent,
        'visible_on_form' => true,
        'visible_on_profile' => true,
    ]);

    $this->plainField = ListField::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->list->customer_id,
        'tag' => 'FNAME',
        'type' => ListFieldType::Text,
        'visible_on_profile' => true,
    ]);

    $this->subscriber = Subscriber::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->list->customer_id,
        'email' => 'consent@example.com',
        'status' => SubscriberStatus::Confirmed,
    ]);
});

// F-4.5-008 — grant, change and withdrawal are all recorded.
it('records a grant, a withdrawal and a re-grant', function (): void {
    $values = app(FieldValueService::class);

    $values->set($this->subscriber, $this->consentField, true);
    $values->set($this->subscriber, $this->consentField, false);
    $values->set($this->subscriber, $this->consentField, null); // cleared entirely

    $events = ConsentEvent::withoutTenancy()->orderBy('id')->get();

    expect($events)->toHaveCount(3)
        ->and($events[0]->old_value)->toBeNull()
        ->and($events[0]->new_value)->toBe('1')
        ->and($events[1]->old_value)->toBe('1')
        ->and($events[1]->new_value)->toBe('0')
        // A cleared value is a withdrawal too — the mass-delete path had to be
        // routed through the model for this event to fire.
        ->and($events[2]->old_value)->toBe('0')
        ->and($events[2]->new_value)->toBeNull();

    expect($events[0]->field)->toBe('GDPR')
        ->and($events[0]->subscriber_id)->toBe($this->subscriber->id)
        ->and($events[0]->customer_id)->toBe($this->subscriber->customer_id)
        ->and($events[0]->list_field_id)->toBe($this->consentField->id)
        ->and($events[0]->created_at)->not->toBeNull();
});

it('ignores non-consent custom fields', function (): void {
    app(FieldValueService::class)->set($this->subscriber, $this->plainField, 'Anna');

    expect(ConsentEvent::withoutTenancy()->count())->toBe(0);
});

it('records nothing when the value does not actually change', function (): void {
    $values = app(FieldValueService::class);

    $values->set($this->subscriber, $this->consentField, true);
    $values->set($this->subscriber, $this->consentField, true);

    expect(ConsentEvent::withoutTenancy()->count())->toBe(1);
});

// F-4.5-008 — IP, user agent and source are captured from the request.
it('captures ip, user agent and the public source from a public form post', function (): void {
    $this->withServerVariables([
        'REMOTE_ADDR' => '203.0.113.5',
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (ConsentProbe)',
    ])->post(route('lists.forms.subscribe', ['list_uid' => $this->list->uid]), [
        'email' => 'newconsent@example.com',
        'GDPR' => '1',
        'hosted' => 1,
    ]);

    $event = ConsentEvent::withoutTenancy()->latest('id')->firstOrFail();

    expect($event->new_value)->toBe('1')
        ->and($event->ip)->toBe('203.0.113.5')
        ->and($event->user_agent)->toContain('ConsentProbe')
        ->and($event->source)->toBe(ConsentEvent::SOURCE_PUBLIC);
});

// The public profile page can withdraw consent — the trail must show it.
it('records a withdrawal made from the public update-profile page', function (): void {
    app(FieldValueService::class)->set($this->subscriber, $this->consentField, true);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.6'])
        ->post(route('lists.public.update_profile.save', [
            'list_uid' => $this->list->uid,
            'subscriber_uid' => $this->subscriber->uid,
        ]), [
            'email' => $this->subscriber->email,
            'FNAME' => 'Anna',
            // GDPR omitted = checkbox cleared
        ]);

    $event = ConsentEvent::withoutTenancy()->latest('id')->firstOrFail();

    expect($event->old_value)->toBe('1')
        ->and($event->new_value)->toBeNull()
        ->and($event->ip)->toBe('203.0.113.6')
        ->and($event->source)->toBe(ConsentEvent::SOURCE_PUBLIC);
});

// Console writes (imports, jobs, queue workers) are attributed to the system:
// outside an HTTP request there is no route, hence no actor.
it('attributes console-driven changes to the system source', function (): void {
    expect(\App\Observers\ConsentEventObserver::source())->toBe(ConsentEvent::SOURCE_SYSTEM);

    app(FieldValueService::class)->set($this->subscriber, $this->consentField, true);

    $event = ConsentEvent::withoutTenancy()->latest('id')->firstOrFail();

    expect($event->source)->toBe(ConsentEvent::SOURCE_SYSTEM)
        ->and($event->ip)->toBeNull()
        ->and($event->user_agent)->toBeNull();
});

// F-4.5-008 — customer-facing read-only view, tenant scoped.
it('shows a customer only its own consent trail', function (): void {
    $mine = $this->list->customer_id;
    $otherList = EmailList::factory()->create();

    ConsentEvent::withoutTenancy()->create([
        'customer_id' => $mine,
        'subscriber_id' => $this->subscriber->id,
        'field' => 'GDPR',
        'new_value' => '1',
        'source' => ConsentEvent::SOURCE_PUBLIC,
        'created_at' => now(),
    ]);
    ConsentEvent::withoutTenancy()->create([
        'customer_id' => $otherList->customer_id,
        'subscriber_id' => 999,
        'field' => 'MARKETING',
        'new_value' => '1',
        'source' => ConsentEvent::SOURCE_PUBLIC,
        'created_at' => now(),
    ]);

    $customer = Customer::query()->findOrFail($mine);
    $this->actingAs($customer, 'customer');
    app(CurrentCustomer::class)->set($customer);
    Filament::setCurrentPanel(Filament::getPanel('customer'));

    $this->get(CustomerConsentEvents::getUrl('index', panel: 'customer'))
        ->assertOk()
        ->assertSee('GDPR')
        ->assertDontSee('MARKETING');
});

// F-4.5-008 — the operator sees every tenant's trail.
it('shows an admin the install-wide consent trail', function (): void {
    $otherList = EmailList::factory()->create();

    ConsentEvent::withoutTenancy()->create([
        'customer_id' => $this->list->customer_id,
        'subscriber_id' => $this->subscriber->id,
        'field' => 'GDPR',
        'new_value' => '1',
        'source' => ConsentEvent::SOURCE_PUBLIC,
        'created_at' => now(),
    ]);
    ConsentEvent::withoutTenancy()->create([
        'customer_id' => $otherList->customer_id,
        'subscriber_id' => 999,
        'field' => 'MARKETING',
        'new_value' => '0',
        'source' => ConsentEvent::SOURCE_ADMIN,
        'created_at' => now(),
    ]);

    $this->actingAs(User::factory()->create(), 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->get(AdminConsentEvents::getUrl('index', panel: 'admin'))
        ->assertOk()
        ->assertSee('GDPR')
        ->assertSee('MARKETING');
});

// The trail is append-only.
it('never offers create on either consent trail resource', function (): void {
    expect(AdminConsentEvents::canCreate())->toBeFalse()
        ->and(CustomerConsentEvents::canCreate())->toBeFalse();
});
