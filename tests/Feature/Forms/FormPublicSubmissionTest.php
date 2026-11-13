<?php

declare(strict_types=1);

use App\Enums\ListFieldType;
use App\Enums\SubscriberSource;
use App\Enums\SubscriberStatus;
use App\Http\Controllers\Lists\PublicSubscribeController;
use App\Mail\Lists\SubscribeConfirmMail;
use App\Models\ConsentEvent;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\FieldOption;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Models\SubscriberFieldValue;
use App\Services\Captcha\CaptchaManager;
use App\Services\Forms\FormRenderer;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Support\Facades\Mail;

/*
 * §2.7 forms — the public submit path.
 *
 * F-2.7-003 typed inputs validated through the shared field library
 * F-2.7-008 message / redirect / lead-magnet handling
 * F-2.7-009 GDPR consent + consent logging
 * F-2.7-010 honeypot + captcha
 * plus the cascade guarantee (subscribers created through the model) and a
 * CJK + emoji round trip.
 */

beforeEach(function (): void {
    // Deliberately NOT logged in: these are public requests, and the consent
    // audit trail classifies its source from the runtime context (WP-22).
    $this->customer = Customer::factory()->create();
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);
    $this->form = Form::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'success_message' => 'Welcome aboard',
    ]);
});

it('creates the subscriber through the model and records the submission', function (): void {
    $fname = ListField::factory()->for($this->list, 'list')->create([
        'tag' => 'FNAME', 'label' => 'First name', 'customer_id' => $this->customer->id,
    ]);

    FormField::factory()->create([
        'form_id' => $this->form->id,
        'customer_id' => $this->customer->id,
        'list_field_id' => $fname->id,
        'tag' => 'FNAME',
        'label' => 'First name',
        'required' => true,
        'sort_order' => 0,
    ]);

    $response = $this->postJson(route('forms.public.submit', ['uid' => $this->form->uid]), [
        'email' => 'Lead@Example.TEST',
        'FNAME' => 'Ada',
        FormRenderer::SOURCE_FIELD => 'https://blog.example.com/post',
        FormRenderer::PAGE_FIELD => 'https://blog.example.com/post',
    ]);

    $response->assertOk()->assertJson(['ok' => true, 'mode' => 'message', 'message' => 'Welcome aboard']);

    $subscriber = Subscriber::withoutTenancy()->where('email', 'lead@example.test')->firstOrFail();

    expect($subscriber->list_id)->toBe($this->list->id)
        // The source proves it went through the Subscriber model, which is
        // what makes the list's subscriber-action cascades fire.
        ->and($subscriber->source)->toBe(SubscriberSource::Form)
        ->and($subscriber->status)->toBe(SubscriberStatus::Confirmed);

    // Mapped answers land on the subscriber.
    expect(SubscriberFieldValue::withoutTenancy()
        ->where('subscriber_id', $subscriber->id)
        ->where('list_field_id', $fname->id)
        ->value('value'))->toBe('Ada');

    $submission = FormSubmission::withoutTenancy()->where('form_id', $this->form->id)->firstOrFail();

    expect($submission->email)->toBe('lead@example.test')
        ->and($submission->subscriber_id)->toBe($subscriber->id)
        ->and($submission->data['FNAME'])->toBe('Ada')
        // "by source" (F-2.7-012) is host-normalised, never a raw URL.
        ->and($submission->source)->toBe('blog.example.com');
});

it('round-trips CJK and emoji through a public submission (utf8mb4)', function (): void {
    $listField = ListField::factory()->for($this->list, 'list')->create([
        'tag' => 'CITY', 'label' => '都市', 'customer_id' => $this->customer->id,
    ]);

    FormField::factory()->create([
        'form_id' => $this->form->id,
        'customer_id' => $this->customer->id,
        'list_field_id' => $listField->id,
        'tag' => 'CITY',
        'label' => '都市 🏙️',
        'sort_order' => 0,
    ]);

    $value = '東京 🗼 서울 ✨';

    $this->postJson(route('forms.public.submit', ['uid' => $this->form->uid]), [
        'email' => 'cjk@example.test',
        'CITY' => $value,
    ])->assertOk();

    $subscriber = Subscriber::withoutTenancy()->where('email', 'cjk@example.test')->firstOrFail();

    expect(SubscriberFieldValue::withoutTenancy()
        ->where('subscriber_id', $subscriber->id)->value('value'))->toBe($value);

    $submission = FormSubmission::withoutTenancy()->where('form_id', $this->form->id)->firstOrFail();
    expect($submission->data['CITY'])->toBe($value);

    // …and it renders back out intact.
    $this->get(route('forms.public.show', ['uid' => $this->form->uid]))
        ->assertOk()
        ->assertSee('都市 🏙️', false);
});

it('validates typed fields through the shared field library (F-2.7-003)', function (): void {
    $field = FormField::factory()->create([
        'form_id' => $this->form->id,
        'customer_id' => $this->customer->id,
        'type' => ListFieldType::Dropdown,
        'tag' => 'PLAN',
        'label' => 'Plan',
        'required' => true,
        'sort_order' => 0,
    ]);

    FieldOption::withoutTenancy()->create([
        'customer_id' => $this->customer->id,
        'owner_type' => FormField::class,
        'owner_id' => $field->id,
        'label' => 'Pro', 'value' => 'pro', 'sort_order' => 0,
    ]);

    $this->postJson(route('forms.public.submit', ['uid' => $this->form->uid]), [
        'email' => 'bad@example.test',
        'PLAN' => 'enterprise',
    ])->assertStatus(422)->assertJsonPath('ok', false)->assertJsonStructure(['errors' => ['PLAN']]);

    expect(FormSubmission::withoutTenancy()->count())->toBe(0);

    $this->postJson(route('forms.public.submit', ['uid' => $this->form->uid]), [
        'email' => 'good@example.test',
        'PLAN' => 'pro',
    ])->assertOk();

    expect(FormSubmission::withoutTenancy()->count())->toBe(1);
});

it('swallows honeypot submissions and answers success (F-2.7-010)', function (): void {
    $this->postJson(route('forms.public.submit', ['uid' => $this->form->uid]), [
        'email' => 'bot@example.test',
        PublicSubscribeController::HONEYPOT_FIELD => 'http://spam.example',
    ])->assertOk()->assertJson(['ok' => true]);

    expect(FormSubmission::withoutTenancy()->count())->toBe(0)
        ->and(Subscriber::withoutTenancy()->count())->toBe(0);
});

it('refuses a submission when the configured captcha fails (F-2.7-010)', function (): void {
    config()->set('emp.captcha.driver', 'recaptcha-v2');
    config()->set('emp.captcha.site_key', 'site');
    config()->set('emp.captcha.secret_key', 'secret');

    expect(CaptchaManager::driver()->enabled())->toBeTrue();

    $this->postJson(route('forms.public.submit', ['uid' => $this->form->uid]), [
        'email' => 'human@example.test',
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['captcha']]);

    expect(Subscriber::withoutTenancy()->count())->toBe(0);

    // A form with captcha switched off is unaffected by the global driver.
    $this->form->forceFill(['captcha_enabled' => false])->save();

    $this->postJson(route('forms.public.submit', ['uid' => $this->form->uid]), [
        'email' => 'human@example.test',
    ])->assertOk();
});

it('requires and logs GDPR consent (F-2.7-009)', function (): void {
    $consentField = ListField::factory()->for($this->list, 'list')->create([
        'customer_id' => $this->customer->id,
        'type' => ListFieldType::Consent,
        'tag' => 'GDPR',
        'label' => 'Marketing consent',
    ]);

    $this->form->forceFill([
        'consent_enabled' => true,
        'consent_required' => true,
        'consent_text' => 'I agree to receive the newsletter — 同意します 👍',
        'consent_list_field_id' => $consentField->id,
    ])->save();

    // Refused without the tick.
    $this->postJson(route('forms.public.submit', ['uid' => $this->form->uid]), [
        'email' => 'nope@example.test',
    ])->assertStatus(422)->assertJsonStructure(['errors' => [FormRenderer::CONSENT_FIELD]]);

    $this->postJson(route('forms.public.submit', ['uid' => $this->form->uid]), [
        'email' => 'yes@example.test',
        FormRenderer::CONSENT_FIELD => '1',
    ])->assertOk();

    $submission = FormSubmission::withoutTenancy()->firstOrFail();

    expect($submission->consent_given)->toBeTrue()
        // The literal sentence the visitor agreed to is stored with the row.
        ->and($submission->consent_text)->toBe('I agree to receive the newsletter — 同意します 👍');

    // Reuses the WP-22 machinery: the tick is a consent field value, so the
    // ConsentEventObserver writes the audit row.
    $event = ConsentEvent::withoutTenancy()->where('subscriber_id', $submission->subscriber_id)->firstOrFail();

    expect($event->field)->toBe('GDPR')
        ->and($event->new_value)->toBe('1')
        ->and($event->source)->toBe(ConsentEvent::SOURCE_PUBLIC);
});

it('logs consent into consent_events even without a mapped consent field (F-2.7-009)', function (): void {
    $this->form->forceFill([
        'consent_enabled' => true,
        'consent_required' => true,
        'consent_text' => 'Plain consent',
        'consent_list_field_id' => null,
    ])->save();

    $this->postJson(route('forms.public.submit', ['uid' => $this->form->uid]), [
        'email' => 'plain@example.test',
        FormRenderer::CONSENT_FIELD => '1',
    ])->assertOk();

    $event = ConsentEvent::withoutTenancy()->firstOrFail();

    expect($event->field)->toBe('FORM_CONSENT')
        ->and($event->new_value)->toBe('1')
        ->and($event->list_field_id)->toBeNull();
});

it('honours the three success modes (F-2.7-008)', function (): void {
    // redirect
    $this->form->forceFill([
        'success_mode' => Form::SUCCESS_REDIRECT,
        'redirect_url' => 'https://example.com/thanks',
    ])->save();

    $this->postJson(route('forms.public.submit', ['uid' => $this->form->uid]), ['email' => 'r@example.test'])
        ->assertOk()->assertJson(['mode' => 'redirect', 'redirect' => 'https://example.com/thanks']);

    $this->post(route('forms.public.submit', ['uid' => $this->form->uid]), ['email' => 'r2@example.test'])
        ->assertRedirect('https://example.com/thanks');

    // lead magnet download — the link is signed and resolves to the asset
    $this->form->forceFill([
        'success_mode' => Form::SUCCESS_DOWNLOAD,
        'lead_magnet_url' => 'https://cdn.example.com/guide.pdf',
    ])->save();

    $json = $this->postJson(route('forms.public.submit', ['uid' => $this->form->uid]), ['email' => 'd@example.test'])
        ->assertOk()->json();

    expect($json['mode'])->toBe('download')
        ->and($json['download'])->toContain('/download/');

    $this->get($json['download'])->assertRedirect('https://cdn.example.com/guide.pdf');

    // An unsigned guess at the same URL is refused.
    $submission = FormSubmission::withoutTenancy()->where('email', 'd@example.test')->firstOrFail();
    $this->get(route('forms.public.download', ['uid' => $this->form->uid, 'submission' => $submission->uid]))
        ->assertForbidden();

    // message
    $this->form->forceFill(['success_mode' => Form::SUCCESS_MESSAGE])->save();

    $this->post(route('forms.public.submit', ['uid' => $this->form->uid]), ['email' => 'm@example.test'])
        ->assertOk()->assertSee('Welcome aboard');
});

it('follows the list opt-in mode and queues the confirmation email', function (): void {
    Mail::fake();

    $this->list->forceFill(['opt_in' => 'double'])->save();

    $this->postJson(route('forms.public.submit', ['uid' => $this->form->uid]), ['email' => 'double@example.test'])
        ->assertOk();

    $subscriber = Subscriber::withoutTenancy()->where('email', 'double@example.test')->firstOrFail();

    expect($subscriber->status)->toBe(SubscriberStatus::Unconfirmed);
    Mail::assertQueued(SubscribeConfirmMail::class);
});

it('never lets a form of one tenant capture into another tenant', function (): void {
    $other = Customer::factory()->create();

    $foreignForm = app(CurrentCustomer::class)->runAs($other, function () use ($other): Form {
        $list = EmailList::factory()->create(['customer_id' => $other->id]);

        return Form::factory()->create(['customer_id' => $other->id, 'list_id' => $list->id]);
    });

    $this->postJson(route('forms.public.submit', ['uid' => $foreignForm->uid]), [
        'email' => 'crossing@example.test',
    ])->assertOk();

    $subscriber = Subscriber::withoutTenancy()->where('email', 'crossing@example.test')->firstOrFail();

    // The subscriber belongs to the FORM's tenant, never to the acting one.
    expect((int) $subscriber->customer_id)->toBe((int) $other->id)
        ->and((int) $subscriber->list_id)->not->toBe($this->list->id);

    // And my panel cannot see the submission it produced.
    expect(FormSubmission::query()->count())->toBe(0)
        ->and(FormSubmission::withoutTenancy()->count())->toBe(1);
});

it('updates an existing subscriber instead of erroring on a repeat submission', function (): void {
    $field = ListField::factory()->for($this->list, 'list')->create([
        'customer_id' => $this->customer->id, 'tag' => 'CITY', 'label' => 'City',
    ]);

    FormField::factory()->create([
        'form_id' => $this->form->id,
        'customer_id' => $this->customer->id,
        'list_field_id' => $field->id,
        'tag' => 'CITY', 'label' => 'City', 'sort_order' => 0,
    ]);

    $this->postJson(route('forms.public.submit', ['uid' => $this->form->uid]), [
        'email' => 'repeat@example.test', 'CITY' => 'Berlin',
    ])->assertOk();

    $this->postJson(route('forms.public.submit', ['uid' => $this->form->uid]), [
        'email' => 'repeat@example.test', 'CITY' => 'Lisbon',
    ])->assertOk();

    expect(Subscriber::withoutTenancy()->where('email', 'repeat@example.test')->count())->toBe(1)
        ->and(FormSubmission::withoutTenancy()->count())->toBe(2);

    $subscriber = Subscriber::withoutTenancy()->where('email', 'repeat@example.test')->firstOrFail();

    expect(SubscriberFieldValue::withoutTenancy()
        ->where('subscriber_id', $subscriber->id)->value('value'))->toBe('Lisbon');
});
