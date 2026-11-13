<?php

declare(strict_types=1);

use App\Enums\SubscriberStatus;
use App\Filament\Admin\Resources\ErasureRequestResource;
use App\Filament\Admin\Resources\ErasureRequestResource\Pages\ListErasureRequests;
use App\Mail\ErasureRequestConfirmMail;
use App\Models\EmailList;
use App\Models\ErasureRequest;
use App\Models\Subscriber;
use App\Models\User;
use App\Settings\PrivacySettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/*
 * WP-22 — F-3.25.16-003 / F-4.5-001: public right-to-erasure workflow.
 * Request → signed email confirmation → admin queue → the existing
 * GdprEraseSubscriberJob (D-31 anonymise in place).
 */

function enableErasure(): void
{
    $settings = app(PrivacySettings::class);
    $settings->erasure_workflow_enabled = true;
    $settings->save();
}

// F-3.25.16-003 — the toggle actually hides the workflow.
it('404s every erasure endpoint while the toggle is off', function (): void {
    $this->get(route('privacy.erasure.form'))->assertNotFound();
    $this->post(route('privacy.erasure.submit'), ['email' => 'a@example.com'])->assertNotFound();

    $request = ErasureRequest::create(['email' => 'a@example.com']);
    $this->get(URL::signedRoute('privacy.erasure.confirm', ['uid' => $request->uid]))->assertNotFound();
});

it('publishes the public request form when the toggle is on', function (): void {
    enableErasure();

    $this->get(route('privacy.erasure.form'))
        ->assertOk()
        ->assertSee(__('privacy.erasure.title'))
        ->assertSee(__('privacy.erasure.intro'));
});

// F-4.5-001 — request → email confirm
it('creates a pending request and queues the signed confirmation email', function (): void {
    enableErasure();
    Mail::fake();

    $this->from(route('privacy.erasure.form'))
        ->post(route('privacy.erasure.submit'), ['email' => 'Erase.Me@Example.com'])
        ->assertRedirect(route('privacy.erasure.form'))
        ->assertSessionHas('public_status', 'erasure_requested');

    $request = ErasureRequest::query()->firstOrFail();

    expect($request->email)->toBe('erase.me@example.com')
        ->and($request->status)->toBe(ErasureRequest::STATUS_PENDING)
        ->and($request->confirmed_at)->toBeNull();

    Mail::assertQueued(ErasureRequestConfirmMail::class, function (ErasureRequestConfirmMail $mail) use ($request): bool {
        return str_contains($mail->confirmUrl, $request->uid)
            && str_contains($mail->confirmUrl, 'signature=');
    });
});

it('reuses an open request instead of piling duplicates into the queue', function (): void {
    enableErasure();
    Mail::fake();

    $this->post(route('privacy.erasure.submit'), ['email' => 'dup@example.com']);
    $this->post(route('privacy.erasure.submit'), ['email' => 'dup@example.com']);

    expect(ErasureRequest::query()->count())->toBe(1);
    Mail::assertQueued(ErasureRequestConfirmMail::class, 2);
});

it('ignores honeypot submissions', function (): void {
    enableErasure();
    Mail::fake();

    $this->post(route('privacy.erasure.submit'), [
        'email' => 'bot@example.com',
        \App\Http\Controllers\Lists\PublicSubscribeController::HONEYPOT_FIELD => 'gotcha',
    ]);

    expect(ErasureRequest::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('rejects a tampered confirmation link', function (): void {
    enableErasure();

    $request = ErasureRequest::create(['email' => 'a@example.com']);

    $this->get(route('privacy.erasure.confirm', ['uid' => $request->uid]))->assertForbidden();
});

it('confirms the request from the signed link', function (): void {
    enableErasure();

    $request = ErasureRequest::create(['email' => 'a@example.com']);

    $this->get(URL::signedRoute('privacy.erasure.confirm', ['uid' => $request->uid]))
        ->assertOk()
        ->assertSee(__('privacy.erasure.confirmed_title'));

    $request = $request->fresh();

    expect($request->status)->toBe(ErasureRequest::STATUS_CONFIRMED)
        ->and($request->confirmed_at)->not->toBeNull()
        ->and($request->isProcessable())->toBeTrue();
});

// F-4.5-001 — admin review → queued anonymisation across every tenant.
it('erases every matching subscriber when an admin processes the request', function (): void {
    enableErasure();

    $listA = EmailList::factory()->create();
    $listB = EmailList::factory()->create();

    $target = Subscriber::factory()->create([
        'list_id' => $listA->id,
        'customer_id' => $listA->customer_id,
        'email' => 'erase.me@example.com',
        'subscribe_ip' => '203.0.113.9',
    ]);
    $alsoTarget = Subscriber::factory()->create([
        'list_id' => $listB->id,
        'customer_id' => $listB->customer_id,
        'email' => 'erase.me@example.com',
    ]);
    $bystander = Subscriber::factory()->create([
        'list_id' => $listA->id,
        'customer_id' => $listA->customer_id,
        'email' => 'keep.me@example.com',
    ]);

    $request = ErasureRequest::create([
        'email' => 'erase.me@example.com',
        'status' => ErasureRequest::STATUS_CONFIRMED,
        'confirmed_at' => now(),
    ]);

    $this->actingAs(User::factory()->create(), 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(ListErasureRequests::class)
        ->callTableAction('process', $request);

    $request = $request->fresh();

    expect($request->status)->toBe(ErasureRequest::STATUS_COMPLETED)
        ->and($request->erased_count)->toBe(2)
        ->and($request->processed_at)->not->toBeNull();

    // D-31: anonymised in place, not deleted.
    expect(Subscriber::withoutTenancy()->find($target->id)->email)
        ->toBe('erased-'.$target->uid.'@gdpr.invalid')
        ->and(Subscriber::withoutTenancy()->find($target->id)->subscribe_ip)->toBeNull()
        ->and(Subscriber::withoutTenancy()->find($target->id)->status)->toBe(SubscriberStatus::Unsubscribed)
        ->and(Subscriber::withoutTenancy()->find($alsoTarget->id)->email)
        ->toBe('erased-'.$alsoTarget->uid.'@gdpr.invalid')
        ->and(Subscriber::withoutTenancy()->find($bystander->id)->email)->toBe('keep.me@example.com');
});

it('lets an admin reject a request without erasing anything', function (): void {
    enableErasure();

    $list = EmailList::factory()->create();
    $subscriber = Subscriber::factory()->create([
        'list_id' => $list->id,
        'customer_id' => $list->customer_id,
        'email' => 'erase.me@example.com',
    ]);

    $request = ErasureRequest::create([
        'email' => 'erase.me@example.com',
        'status' => ErasureRequest::STATUS_CONFIRMED,
        'confirmed_at' => now(),
    ]);

    $this->actingAs(User::factory()->create(), 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(ListErasureRequests::class)->callTableAction('reject', $request);

    expect($request->fresh()->status)->toBe(ErasureRequest::STATUS_REJECTED)
        ->and(Subscriber::withoutTenancy()->find($subscriber->id)->email)->toBe('erase.me@example.com');
});

it('surfaces the workflow toggle state on the admin queue', function (): void {
    $this->actingAs(User::factory()->create(), 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->get(ErasureRequestResource::getUrl('index', panel: 'admin'))
        ->assertOk()
        ->assertSee(__('privacy.erasure.admin.workflow_off'));

    enableErasure();

    $this->get(ErasureRequestResource::getUrl('index', panel: 'admin'))
        ->assertSee(__('privacy.erasure.admin.workflow_on'));
});
