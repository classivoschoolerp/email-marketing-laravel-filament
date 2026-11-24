<?php

declare(strict_types=1);

use App\Enums\AutomationNodeType;
use App\Enums\SubscriberSource;
use App\Enums\SubscriberStatus;
use App\Http\Controllers\Lists\PublicSubscribeController;
use App\Models\AutomationRun;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Models\LandingPage;
use App\Models\LandingPageConversion;
use App\Models\LandingPageVariant;
use App\Models\LandingPageVisit;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Services\Landing\LandingBlocks;
use App\Services\Subscribers\SubscriberTagService;
use App\Support\Tenancy\CurrentCustomer;
use Tests\Support\AutomationFixtures as Fx;
use Tests\Support\LandingTestHelpers;

/*
 * §2.6 F-2.6-009 / §1.8 F-1.8-004 — the embedded form.
 *
 * The form markup is the §2.7 renderer's, the write path is the §2.7
 * submission service's, and the automation event is the §2.7 one — so a
 * landing-page submit subscribes to the list and fires F-2.4.2-011 exactly
 * like the hosted form. What is NEW here is the attributed conversion and
 * the after-submit handling (F-2.6-011).
 */

beforeEach(function (): void {
    config()->set('app.url', 'http://localhost');

    $this->customer = Fx::actingAsCustomer();
    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);
    $this->form = Form::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'captcha_enabled' => false,
        'heading' => 'Get the guide',
    ]);

    $this->page = LandingPage::factory()->published()->create([
        'customer_id' => $this->customer->id,
        'slug' => 'guide',
        'form_id' => $this->form->id,
        'content_html' => '<section class="lp-hero"><h1>Guide</h1></section>'
            .'<div id="lp-form" '.LandingBlocks::FORM_PLACEHOLDER_ATTRIBUTE.'="1"></div>',
        'success_message' => 'Check your inbox.',
    ]);
});

it('renders the §2.7 form in place of the builder placeholder (F-2.6-009)', function (): void {
    ListField::factory()->for($this->list, 'list')->create([
        'customer_id' => $this->customer->id, 'tag' => 'FNAME', 'label' => 'First name',
    ]);

    FormField::factory()->create([
        'form_id' => $this->form->id,
        'customer_id' => $this->customer->id,
        'tag' => 'FNAME',
        'label' => 'First name',
        'sort_order' => 0,
    ]);

    $response = $this->get(route('landing.public.show', ['slug' => 'guide']))->assertOk();
    $html = $response->getContent();

    expect($html)
        // The placeholder is gone, the real fields are there.
        ->not->toContain(LandingBlocks::FORM_PLACEHOLDER_ATTRIBUTE)
        ->toContain('name="email"')
        ->toContain('name="FNAME"')
        ->toContain('Get the guide')
        // …posting to the LANDING endpoint, which is what makes it a
        // conversion rather than an anonymous form submit.
        ->toContain(route('landing.public.submit', ['slug' => 'guide']))
        // …and carrying the shared honeypot.
        ->toContain(PublicSubscribeController::HONEYPOT_FIELD);
});

it('appends the form when the document has no placeholder', function (): void {
    $this->page->forceFill(['content_html' => '<h1>No marker here</h1>'])->save();

    $this->get(route('landing.public.show', ['slug' => 'guide']))
        ->assertOk()
        // A capture page must never render without its form.
        ->assertSee('name="email"', false);
});

it('subscribes the visitor, records the conversion and fires the automation (F-1.8-004)', function (): void {
    $automation = Fx::automation($this->customer, [
        Fx::node('t1', AutomationNodeType::TriggerFormSubmitted, [
            'list_id' => $this->list->id,
            'form_id' => $this->form->id,
        ], ['next' => 'a1']),
        Fx::node('a1', AutomationNodeType::ActionAddTag, ['tag' => 'from-landing'], ['next' => 'x1']),
        Fx::node('x1', AutomationNodeType::LogicExit, [], []),
    ]);

    // A visit first, so the conversion has something to attribute to.
    $view = $this->get(route('landing.public.show', ['slug' => 'guide']))->assertOk();

    LandingTestHelpers::replayCookies($view, $this);

    $this->post(route('landing.public.submit', ['slug' => 'guide']), [
        'email' => 'Lead@Example.TEST',
    ])->assertOk()->assertSee('Check your inbox.');

    // §2.7 write path: the subscriber goes through the model.
    $subscriber = Subscriber::withoutTenancy()->where('email', 'lead@example.test')->firstOrFail();

    expect($subscriber->list_id)->toBe($this->list->id)
        ->and($subscriber->source)->toBe(SubscriberSource::Form)
        ->and($subscriber->status)->toBe(SubscriberStatus::Confirmed)
        ->and(FormSubmission::withoutTenancy()->count())->toBe(1);

    // …the landing-side fact, attributed to the visit that produced it.
    $conversion = LandingPageConversion::withoutTenancy()->firstOrFail();
    $visit = LandingPageVisit::withoutTenancy()->firstOrFail();

    expect((int) $conversion->landing_page_id)->toBe((int) $this->page->id)
        ->and((int) $conversion->subscriber_id)->toBe((int) $subscriber->id)
        ->and((int) $conversion->landing_page_visit_id)->toBe((int) $visit->id)
        ->and($conversion->email)->toBe('lead@example.test')
        ->and($visit->refresh()->converted)->toBeTrue();

    // …and exactly one form_submitted event reached the bus (D-96 muting).
    expect(AutomationRun::withoutTenancy()->where('automation_id', $automation->id)->count())->toBe(1)
        ->and(app(SubscriberTagService::class)->has($subscriber->refresh(), 'from-landing'))->toBeTrue();
});

it('attributes a conversion to the variant the visitor was shown (F-2.6-004)', function (): void {
    $this->page->forceFill(['ab_enabled' => true])->save();

    $b = LandingPageVariant::factory()->create([
        'landing_page_id' => $this->page->id,
        'customer_id' => $this->customer->id,
        'name' => 'B',
        'weight' => 100,
        'content_html' => '<h1>ARM-B</h1><div '.LandingBlocks::FORM_PLACEHOLDER_ATTRIBUTE.'="1"></div>',
    ]);

    $view = $this->get(route('landing.public.show', ['slug' => 'guide']))->assertOk()->assertSee('ARM-B', false);

    LandingTestHelpers::replayCookies($view, $this);

    $this->post(route('landing.public.submit', ['slug' => 'guide']), ['email' => 'ab@example.test'])->assertOk();

    expect((int) LandingPageConversion::withoutTenancy()->firstOrFail()->landing_page_variant_id)
        ->toBe((int) $b->id);
});

it('honours the three after-submit modes (F-2.6-011)', function (): void {
    // redirect
    $this->page->forceFill([
        'after_submit' => LandingPage::AFTER_REDIRECT,
        'redirect_url' => 'https://example.com/thanks',
    ])->save();

    $this->post(route('landing.public.submit', ['slug' => 'guide']), ['email' => 'r@example.test'])
        ->assertRedirect('https://example.com/thanks');

    // thank-you page: POST → redirect → GET, so a refresh cannot resubmit
    $this->page->forceFill([
        'after_submit' => LandingPage::AFTER_THANK_YOU,
        'thank_you_html' => '<div class="lp-thanks"><h1>Nearly there</h1></div>',
    ])->save();

    $this->post(route('landing.public.submit', ['slug' => 'guide']), ['email' => 't@example.test'])
        ->assertRedirect(route('landing.public.thanks', ['slug' => 'guide']));

    $this->get(route('landing.public.thanks', ['slug' => 'guide']))->assertOk()->assertSee('Nearly there');

    // message
    $this->page->forceFill(['after_submit' => LandingPage::AFTER_MESSAGE])->save();

    $this->post(route('landing.public.submit', ['slug' => 'guide']), ['email' => 'm@example.test'])
        ->assertOk()->assertSee('Check your inbox.');

    expect(Subscriber::withoutTenancy()->count())->toBe(3);
});

it('re-renders the page with errors on a rejected submission', function (): void {
    $this->post(route('landing.public.submit', ['slug' => 'guide']), ['email' => 'not-an-email'])
        ->assertStatus(422)
        ->assertSee('lp-form__errors', false);

    expect(Subscriber::withoutTenancy()->count())->toBe(0)
        ->and(LandingPageConversion::withoutTenancy()->count())->toBe(0);

    $this->postJson(route('landing.public.submit', ['slug' => 'guide']), ['email' => 'not-an-email'])
        ->assertStatus(422)
        ->assertJsonPath('ok', false);
});

it('swallows honeypot submissions without creating or converting anything', function (): void {
    $this->post(route('landing.public.submit', ['slug' => 'guide']), [
        'email' => 'bot@example.test',
        PublicSubscribeController::HONEYPOT_FIELD => 'http://spam.example',
    ])->assertOk();

    expect(Subscriber::withoutTenancy()->count())->toBe(0)
        ->and(LandingPageConversion::withoutTenancy()->count())->toBe(0);
});

it('refuses a submit on a page that is not live, and on another tenant\'s domain', function (): void {
    $this->page->forceFill(['status' => 'draft'])->save();

    $this->post(route('landing.public.submit', ['slug' => 'guide']), ['email' => 'x@example.test'])
        ->assertNotFound();

    expect(Subscriber::withoutTenancy()->count())->toBe(0);
});

it('captures into the page owner\'s list, never the acting tenant\'s', function (): void {
    $other = Customer::factory()->create();

    $foreign = app(CurrentCustomer::class)->runAs($other, function () use ($other): LandingPage {
        $list = EmailList::factory()->create(['customer_id' => $other->id]);
        $form = Form::factory()->create([
            'customer_id' => $other->id,
            'list_id' => $list->id,
            'captcha_enabled' => false,
        ]);

        return LandingPage::factory()->published()->create([
            'customer_id' => $other->id,
            'slug' => 'theirs',
            'form_id' => $form->id,
        ]);
    });

    $this->post(route('landing.public.submit', ['slug' => 'theirs']), ['email' => 'crossing@example.test'])
        ->assertOk();

    $subscriber = Subscriber::withoutTenancy()->where('email', 'crossing@example.test')->firstOrFail();

    expect((int) $subscriber->customer_id)->toBe((int) $other->id)
        ->and((int) $subscriber->list_id)->not->toBe((int) $this->list->id)
        // My panel sees neither the conversion nor the submission.
        ->and(LandingPageConversion::query()->count())->toBe(0)
        ->and(LandingPageConversion::withoutTenancy()->where('landing_page_id', $foreign->id)->count())->toBe(1);
});
