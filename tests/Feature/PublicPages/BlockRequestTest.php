<?php

declare(strict_types=1);

use App\Enums\BlockRequestStatus;
use App\Filament\Admin\Resources\EmailBlockRequestResource\Pages\ListEmailBlockRequests;
use App\Mail\BlockRequestConfirmMail;
use App\Models\EmailBlockRequest;
use App\Models\GlobalBlacklistEmail;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

// F-1.10-001: the public "never email me again" form + request creation.
it('creates a pending block request and emails the signed confirmation link', function (): void {
    Mail::fake();

    $this->get(route('block_request.form'))
        ->assertOk()
        ->assertSee(__('public.block_requests.title'));

    $this->post(route('block_request.submit'), ['email' => 'Block-Me@Example.com'])
        ->assertRedirect()
        ->assertSessionHas('public_status', 'block_request_sent');

    $request = EmailBlockRequest::query()->where('email', 'block-me@example.com')->firstOrFail();
    expect($request->status)->toBe(BlockRequestStatus::Pending)
        ->and($request->uid)->not->toBeNull();

    // F-1.10-002: confirmation email with the signed link.
    $confirmUrl = null;
    Mail::assertQueued(BlockRequestConfirmMail::class, function (BlockRequestConfirmMail $mail) use (&$confirmUrl): bool {
        $confirmUrl = $mail->confirmUrl;

        return $mail->hasTo('block-me@example.com');
    });

    expect($confirmUrl)->toContain('signature=');

    // Re-submitting reuses the open request instead of duplicating it.
    $this->post(route('block_request.submit'), ['email' => 'block-me@example.com']);
    expect(EmailBlockRequest::query()->where('email', 'block-me@example.com')->count())->toBe(1);

    // Unsigned confirm URL → 403; the signed one confirms.
    $this->get(route('block_request.confirm', ['uid' => $request->uid]))->assertForbidden();

    $this->get($confirmUrl)
        ->assertOk()
        ->assertSee(__('public.block_requests.confirmed_title'));

    expect($request->refresh()->status)->toBe(BlockRequestStatus::Confirmed)
        ->and($request->confirmed_at)->not->toBeNull();

    // No auto-approve by default: nothing in the blacklist yet.
    expect(GlobalBlacklistEmail::query()->count())->toBe(0);
});

it('silently drops bot submissions and rate-limits the block request endpoint', function (): void {
    Mail::fake();

    $this->post(route('block_request.submit'), [
        'email' => 'bot@example.com',
        \App\Http\Controllers\Lists\PublicSubscribeController::HONEYPOT_FIELD => 'http://spam.example',
    ])->assertRedirect()->assertSessionHas('public_status', 'block_request_sent');

    expect(EmailBlockRequest::query()->count())->toBe(0);
    Mail::assertNothingQueued();

    // Rate limit (public endpoints are throttled): 10/min on the POST.
    foreach (range(1, 9) as $i) {
        $this->post(route('block_request.submit'), ['email' => "rl{$i}@example.com"])->assertRedirect();
    }

    $this->post(route('block_request.submit'), ['email' => 'rl11@example.com'])->assertStatus(429);
});

// F-3.19-003: auto-approve inserts into the global blacklist on confirm.
it('auto-approves confirmed requests when the setting is enabled', function (): void {
    Mail::fake();
    config()->set('emp-public.block_requests.auto_approve', true);

    $request = EmailBlockRequest::create(['email' => 'auto@example.com']);

    $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
        'block_request.confirm', now()->addHours(48), ['uid' => $request->uid],
    );

    $this->get($url)->assertOk();

    expect($request->refresh()->status)->toBe(BlockRequestStatus::Approved);

    $entry = GlobalBlacklistEmail::query()->where('email', 'auto@example.com')->firstOrFail();
    expect($entry->source)->toBe(GlobalBlacklistEmail::SOURCE_BLOCK_REQUEST);
});

// F-3.19-001/002: the admin queue with approve / reject / bulk approve.
it('lists, approves, rejects and bulk-approves block requests in the admin', function (): void {
    $this->actingAs(User::factory()->create(), 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $confirmed = EmailBlockRequest::create(['email' => 'confirmed@example.com', 'status' => BlockRequestStatus::Confirmed]);
    $pending = EmailBlockRequest::create(['email' => 'pending@example.com']);
    $bulkA = EmailBlockRequest::create(['email' => 'bulk-a@example.com', 'status' => BlockRequestStatus::Confirmed]);
    $bulkB = EmailBlockRequest::create(['email' => 'bulk-b@example.com', 'status' => BlockRequestStatus::Confirmed]);

    $page = Livewire::test(ListEmailBlockRequests::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$confirmed, $pending, $bulkA, $bulkB]);

    // Approve → global blacklist row with source=block-request (F-3.19-002).
    $page->callTableAction('approve', $confirmed);

    expect($confirmed->refresh()->status)->toBe(BlockRequestStatus::Approved);

    $entry = GlobalBlacklistEmail::query()->where('email', 'confirmed@example.com')->firstOrFail();
    expect($entry->source)->toBe(GlobalBlacklistEmail::SOURCE_BLOCK_REQUEST)
        ->and($entry->customer_id)->toBeNull();

    // Reject.
    $page->callTableAction('reject', $pending);
    expect($pending->refresh()->status)->toBe(BlockRequestStatus::Rejected)
        ->and(GlobalBlacklistEmail::query()->where('email', 'pending@example.com')->exists())->toBeFalse();

    // Bulk approve (already-approved rows are skipped by the approver).
    $page->callTableBulkAction('bulk_approve', [$bulkA, $bulkB, $confirmed]);

    expect($bulkA->refresh()->status)->toBe(BlockRequestStatus::Approved)
        ->and($bulkB->refresh()->status)->toBe(BlockRequestStatus::Approved)
        ->and(GlobalBlacklistEmail::query()->count())->toBe(3);
});
