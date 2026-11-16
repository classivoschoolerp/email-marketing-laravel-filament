<?php

declare(strict_types=1);

use App\Enums\AutomationNodeType;
use App\Filament\Customer\Resources\SubscriberResource\Pages\ViewSubscriber;
use App\Jobs\Subscribers\SendSingleEmailJob;
use App\Mail\Lists\SingleSubscriberMail;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Services\Lists\FieldValueService;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\AutomationFixtures as Fx;

/**
 * F-2.2.5-010 profile actions: send single email (queued, merge-tag rendered)
 * and add to automation (WP-21 Enroller). "Add to segment" is honest about
 * D-32 — segments are condition-based, so nothing is ever silently "added".
 */
beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->for($this->customer)->create([
        'from_email' => 'news@list.example',
        'from_name' => 'The List',
        'company_name' => 'Acme Ltd',
    ]);

    $this->subscriber = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'ada@x.example']);

    $this->profile = fn (Subscriber $s = null) => Livewire::test(
        ViewSubscriber::class,
        ['record' => ($s ?? $this->subscriber)->getRouteKey()],
    );
});

/* ================================================================ *
 *  Send single email
 * ================================================================ */

it('queues a single email from the profile', function (): void {
    Queue::fake();

    ($this->profile)()
        ->callAction('send_email', data: [
            'subject' => 'Hello {FNAME|there}',
            'html' => '<p>Hi {FNAME|there}, your address is {EMAIL}.</p>',
        ])
        ->assertHasNoActionErrors();

    Queue::assertPushed(
        SendSingleEmailJob::class,
        fn (SendSingleEmailJob $job): bool => $job->subscriberId === $this->subscriber->id
            && $job->customerId === $this->customer->id
            && $job->subject === 'Hello {FNAME|there}'
            && $job->queue === 'transactional',
    );
});

it('renders merge tags and appends the list footer when the job runs', function (): void {
    Mail::fake();

    $field = ListField::factory()->for($this->list, 'list')->create(['tag' => 'FNAME', 'label' => 'First name']);
    app(FieldValueService::class)->set($this->subscriber, $field, 'Ada');

    (new SendSingleEmailJob(
        $this->customer->id,
        $this->subscriber->id,
        'Hello {FNAME|there}',
        '<p>Hi {FNAME|there}, your address is {EMAIL}.</p>',
        (string) Str::uuid(),
    ))->handle(app(App\Services\Content\MergeTags::class), app(App\Services\Lists\PublicPageRenderer::class));

    Mail::assertQueuedCount(1);
    Mail::assertQueued(SingleSubscriberMail::class, function (SingleSubscriberMail $mail): bool {
        return $mail->hasTo('ada@x.example')
            && $mail->subjectLine === 'Hello Ada'
            && str_contains($mail->htmlContent, 'Hi Ada, your address is ada@x.example.')
            && ! str_contains($mail->htmlContent, '{FNAME')
            // CAN-SPAM footer from the list's company details.
            && str_contains($mail->htmlContent, 'Acme Ltd')
            && $mail->fromEmail === 'news@list.example';
    });
});

it('falls back to the merge-tag default when the field is empty', function (): void {
    Mail::fake();

    // Field exists on the list but this subscriber has no value for it.
    ListField::factory()->for($this->list, 'list')->create(['tag' => 'FNAME', 'label' => 'First name']);

    (new SendSingleEmailJob(
        $this->customer->id,
        $this->subscriber->id,
        'Hi {FNAME|there}',
        '<p>{FNAME|there}</p>',
        (string) Str::uuid(),
    ))->handle(app(App\Services\Content\MergeTags::class), app(App\Services\Lists\PublicPageRenderer::class));

    Mail::assertQueued(SingleSubscriberMail::class, fn (SingleSubscriberMail $mail): bool => $mail->subjectLine === 'Hi there');
});

it('never sends a single email to another tenant\'s subscriber', function (): void {
    Mail::fake();

    $foreign = Subscriber::factory()->create();

    (new SendSingleEmailJob(
        $this->customer->id, // this tenant …
        $foreign->id,        // … forging another tenant's subscriber id
        'Subject',
        '<p>Body</p>',
        (string) Str::uuid(),
    ))->handle(app(App\Services\Content\MergeTags::class), app(App\Services\Lists\PublicPageRenderer::class));

    Mail::assertNothingQueued();
});

/* ================================================================ *
 *  Add to automation
 * ================================================================ */

function wp27aPublishedAutomation(Customer $customer, EmailList $list): Automation
{
    return Fx::automation($customer, [
        Fx::node('t1', AutomationNodeType::TriggerSubscribed, ['list_id' => $list->id], ['next' => 'x1']),
        Fx::node('x1', AutomationNodeType::LogicExit, [], []),
    ]);
}

it('enrols the subscriber into an automation through the Enroller', function (): void {
    $automation = wp27aPublishedAutomation($this->customer, $this->list);

    ($this->profile)()
        ->callAction('add_to_automation', data: ['automation_id' => $automation->id])
        ->assertHasNoActionErrors();

    expect(AutomationRun::withoutTenancy()->count())->toBe(1)
        ->and((int) AutomationRun::withoutTenancy()->value('subscriber_id'))->toBe($this->subscriber->id)
        ->and((int) AutomationRun::withoutTenancy()->value('automation_id'))->toBe($automation->id);

    // The F-2.4.2-043 "once" re-entry rule still applies — no second run.
    ($this->profile)()->callAction('add_to_automation', data: ['automation_id' => $automation->id]);

    expect(AutomationRun::withoutTenancy()->count())->toBe(1);
});

it('never enrols into another tenant\'s automation', function (): void {
    // One own automation so the action is visible at all.
    wp27aPublishedAutomation($this->customer, $this->list);

    $otherCustomer = Customer::factory()->create();
    $otherList = EmailList::factory()->for($otherCustomer)->create();
    $foreign = wp27aPublishedAutomation($otherCustomer, $otherList);

    ($this->profile)()->callAction('add_to_automation', data: ['automation_id' => $foreign->id]);

    expect(AutomationRun::withoutTenancy()->count())->toBe(0);
});

/* ================================================================ *
 *  Add to segment (D-32)
 * ================================================================ */

it('explains that segments are condition-based instead of faking an add', function (): void {
    $page = ($this->profile)()->assertActionVisible('add_to_segment');

    expect($page->instance()->getAction('add_to_segment')->getModalDescription())
        ->toBe(__('subscribers.actions.add_to_segment_explanation'));

    // Submitting only navigates to the list's segment manager; it creates
    // nothing and changes nothing about the subscriber.
    $page->callAction('add_to_segment')
        ->assertRedirect(
            App\Filament\Customer\Resources\EmailListResource::getUrl('segments', ['record' => $this->list]),
        );

    expect(App\Models\ListSegment::withoutTenancy()->count())->toBe(0)
        ->and($this->subscriber->refresh()->status->value)->toBe('confirmed');
});
