<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Services\Preferences\CustomerPreferences;
use App\Services\Verification\Credentials;
use App\Services\Verification\ProviderRegistry;
use App\Services\Verification\VerificationProvider;
use App\Services\Verification\VerificationResult;

/*
 * WP-16 §2.9.12 F-2.9.12-005 — real-time verification at form-submit: the
 * ONE RealTimeGate hook in PublicSubscribeController, config-gated and OFF
 * by default. (API-insert reuses the same gate when WP-19 lands.)
 */

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->list = EmailList::factory()->for($this->customer)->create();

    // Scripted provider: bad-* is undeliverable, risky-* risky, rest fine.
    app(ProviderRegistry::class)->register('rt-scripted', fn (): VerificationProvider => new class implements VerificationProvider
    {
        public function key(): string
        {
            return 'rt-scripted';
        }

        public function name(): string
        {
            return 'RT';
        }

        public function requiresApiKey(): bool
        {
            return false;
        }

        public function verify(string $email): VerificationResult
        {
            return match (true) {
                str_starts_with($email, 'bad-') => VerificationResult::undeliverable('nope'),
                str_starts_with($email, 'risky-') => VerificationResult::risky('accept_all'),
                default => VerificationResult::deliverable('ok'),
            };
        }
    });

    app(CustomerPreferences::class)->set($this->customer, Credentials::PREF_PROVIDER, 'rt-scripted');
});

it('is OFF by default: undeliverable addresses still subscribe', function (): void {
    $this->post(route('lists.forms.subscribe', ['list_uid' => $this->list->uid]), [
        'email' => 'bad-but-allowed@example.com',
    ])->assertRedirect();

    expect(Subscriber::withoutTenancy()->where('email', 'bad-but-allowed@example.com')->exists())->toBeTrue();
});

it('rejects undeliverable addresses at form-submit when enabled', function (): void {
    config(['emp-verification.realtime.enabled' => true]);

    $this->post(route('lists.forms.subscribe', ['list_uid' => $this->list->uid]), [
        'email' => 'bad-blocked@example.com',
    ])->assertRedirect()->assertSessionHasErrors('email');

    expect(Subscriber::withoutTenancy()->where('email', 'bad-blocked@example.com')->exists())->toBeFalse();
});

it('lets deliverable addresses through and treats risky per the block_risky toggle', function (): void {
    config(['emp-verification.realtime.enabled' => true]);

    $this->post(route('lists.forms.subscribe', ['list_uid' => $this->list->uid]), [
        'email' => 'fine@example.com',
    ])->assertRedirect()->assertSessionDoesntHaveErrors();

    expect(Subscriber::withoutTenancy()->where('email', 'fine@example.com')->exists())->toBeTrue();

    // risky passes by default…
    $this->post(route('lists.forms.subscribe', ['list_uid' => $this->list->uid]), [
        'email' => 'risky-ok@example.com',
    ])->assertRedirect();

    expect(Subscriber::withoutTenancy()->where('email', 'risky-ok@example.com')->exists())->toBeTrue();

    // …and is rejected with block_risky on.
    config(['emp-verification.realtime.block_risky' => true]);

    $this->post(route('lists.forms.subscribe', ['list_uid' => $this->list->uid]), [
        'email' => 'risky-blocked@example.com',
    ])->assertRedirect()->assertSessionHasErrors('email');

    expect(Subscriber::withoutTenancy()->where('email', 'risky-blocked@example.com')->exists())->toBeFalse();
});
