<?php

declare(strict_types=1);

use App\Enums\ListFieldType;
use App\Enums\SubscriberStatus;
use App\Filament\Customer\Resources\SubscriberResource;
use App\Filament\Customer\Resources\SubscriberResource\Pages\ViewSubscriber;
use App\Jobs\Subscribers\GdprEraseSubscriberJob;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Services\Lists\FieldValueService;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->for($this->customer)->create([
        'gdpr_consent_text' => 'I agree to marketing emails.',
        'gdpr_consent_logging' => true,
    ]);
    $this->subscriber = Subscriber::factory()->for($this->list, 'list')->create([
        'email' => 'profile@x.example',
        'ip' => '203.0.113.9',
        'subscribe_ip' => '203.0.113.9',
        'last_bounce_reason' => 'mailbox full',
        'bounce_type' => 'soft',
        'bounces_count' => 2,
        'complaints_count' => 1,
    ]);
});

it('renders the profile with status, consent and deliverability panels', function (): void {
    $this->get(SubscriberResource::getUrl('view', ['record' => $this->subscriber], panel: 'customer'))
        ->assertOk()
        ->assertSee('profile@x.example')
        ->assertSee('203.0.113.9')
        ->assertSee(__('subscribers.profile.consent_heading'))
        ->assertSee('I agree to marketing emails.')
        ->assertSee(__('subscribers.profile.deliverability_heading'))
        ->assertSee('mailbox full')
        ->assertSee(__('subscribers.profile.timeline_heading'))
        ->assertSee(__('subscribers.profile.timeline_placeholder'));
});

it('edits field values inline from the profile (F-2.2.5-001)', function (): void {
    $field = ListField::factory()->for($this->list, 'list')->create(['tag' => 'FNAME']);
    app(FieldValueService::class)->set($this->subscriber, $field, 'Before');

    Livewire::test(ViewSubscriber::class, ['record' => $this->subscriber->getRouteKey()])
        ->fillForm([
            'email' => 'inline@x.example',
            'field_values' => [$field->id => 'After'],
        ])
        ->call('saveFields')
        ->assertHasNoFormErrors();

    expect($this->subscriber->refresh()->email)->toBe('inline@x.example')
        ->and(app(FieldValueService::class)->get($this->subscriber, $field))->toBe('After');
});

it('hides fields flagged invisible on the profile', function (): void {
    ListField::factory()->for($this->list, 'list')->create([
        'tag' => 'HIDDENF',
        'label' => 'Internal score',
        'visible_on_profile' => false,
    ]);

    Livewire::test(ViewSubscriber::class, ['record' => $this->subscriber->getRouteKey()])
        ->assertDontSee('Internal score');
});

it('unsubscribes and blacklists from the profile header actions (F-2.2.5-010)', function (): void {
    Livewire::test(ViewSubscriber::class, ['record' => $this->subscriber->getRouteKey()])
        ->callAction('unsubscribe');

    expect($this->subscriber->refresh()->status)->toBe(SubscriberStatus::Unsubscribed);

    Livewire::test(ViewSubscriber::class, ['record' => $this->subscriber->getRouteKey()])
        ->callAction('blacklist');

    expect($this->subscriber->refresh()->status)->toBe(SubscriberStatus::Blacklisted);
});

it('exports the full subscriber record as JSON (F-2.2.5-010)', function (): void {
    $field = ListField::factory()->for($this->list, 'list')->create(['tag' => 'FNAME']);
    app(FieldValueService::class)->set($this->subscriber, $field, 'Ada');

    $page = new ViewSubscriber;
    $page->record = $this->subscriber;

    $payload = $page->exportPayload();

    expect($payload['email'])->toBe('profile@x.example')
        ->and($payload['uid'])->toBe($this->subscriber->uid)
        ->and($payload['status'])->toBe('confirmed')
        ->and($payload['fields'])->toMatchArray(['FNAME' => 'Ada']);
});

it('queues the GDPR erasure job and anonymises the subscriber (F-2.2.5-010)', function (): void {
    Queue::fake();

    $field = ListField::factory()->for($this->list, 'list')->create(['tag' => 'FNAME']);
    app(FieldValueService::class)->set($this->subscriber, $field, 'Ada');

    Livewire::test(ViewSubscriber::class, ['record' => $this->subscriber->getRouteKey()])
        ->callAction('gdpr_erase');

    Queue::assertPushed(
        GdprEraseSubscriberJob::class,
        fn (GdprEraseSubscriberJob $job): bool => $job->subscriberId === $this->subscriber->id,
    );

    (new GdprEraseSubscriberJob($this->customer->id, $this->subscriber->id, (string) Str::uuid()))->handle();

    $this->subscriber->refresh();

    expect($this->subscriber->email)->toBe('erased-'.$this->subscriber->uid.'@gdpr.invalid')
        ->and($this->subscriber->ip)->toBeNull()
        ->and($this->subscriber->subscribe_ip)->toBeNull()
        ->and($this->subscriber->status)->toBe(SubscriberStatus::Unsubscribed)
        ->and($this->subscriber->fieldValues()->count())->toBe(0);
});

it('reports the consent record from consent fields (F-2.2.5-008)', function (): void {
    $consent = ListField::factory()->for($this->list, 'list')->type(ListFieldType::Consent)->create([
        'tag' => 'GDPR',
        'label' => 'Marketing consent',
    ]);
    app(FieldValueService::class)->set($this->subscriber, $consent, true);

    $page = new ViewSubscriber;
    $page->record = $this->subscriber;

    $record = $page->consentRecord();

    expect($record['text'])->toBe('I agree to marketing emails.')
        ->and($record['logging'])->toBeTrue()
        ->and($record['granted'])->toHaveCount(1)
        ->and($record['granted'][0]['label'])->toBe('Marketing consent')
        ->and($record['granted'][0]['value'])->toBe(__('subscribers.profile.consent_granted'))
        ->and($record['ip'])->toBe('203.0.113.9');
});

it('denies the profile to other tenants', function (): void {
    $foreign = Subscriber::factory()->create();

    $this->get(SubscriberResource::getUrl('view', ['record' => $foreign], panel: 'customer'))
        ->assertNotFound();
});
