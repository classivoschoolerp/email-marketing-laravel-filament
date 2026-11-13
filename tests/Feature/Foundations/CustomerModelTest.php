<?php

declare(strict_types=1);

use App\Enums\CustomerStatus;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

it('generates a uid and hashes the password on create', function (): void {
    $customer = Customer::factory()->create(['password' => 'secret-pass']);

    expect($customer->uid)->toBeString()->not->toBe('')
        ->and(Hash::check('secret-pass', $customer->password))->toBeTrue();
});

it('casts status to the enum and gates login on it', function (): void {
    $customer = Customer::factory()->blocked()->create();

    expect($customer->status)->toBe(CustomerStatus::Blocked)
        ->and($customer->status->canLogin())->toBeFalse();
});

it('stores the 2FA secret encrypted at rest', function (): void {
    $customer = Customer::factory()->create(['twofa_secret' => 'PLAINSECRET']);

    $raw = DB::table('customers')->where('id', $customer->id)->value('twofa_secret');

    expect($raw)->not->toContain('PLAINSECRET')
        ->and($customer->fresh()->twofa_secret)->toBe('PLAINSECRET');
});

it('round-trips CJK and emoji content intact', function (): void {
    $customer = Customer::factory()->create([
        'first_name' => '太郎',
        'last_name' => '山田 😀',
        'company' => 'テスト株式会社',
    ]);

    $fresh = $customer->fresh();

    expect($fresh->first_name)->toBe('太郎')
        ->and($fresh->last_name)->toBe('山田 😀')
        ->and($fresh->company)->toBe('テスト株式会社');
});
