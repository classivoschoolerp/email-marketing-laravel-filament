<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\EmailList;
use App\Models\VerificationJob;
use App\Services\Campaigns\CampaignPreflight;
use Illuminate\Support\Carbon;

/*
 * WP-16 §2.9.12 F-2.9.12-010 — the ONE check appended to CampaignPreflight:
 * block sending when the list has not been verified within N days.
 * Config-gated (emp-verification.preflight.max_age_days), 0 = off (D-23).
 */

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->list = EmailList::factory()->for($this->customer)->create();
    $this->campaign = \App\Models\Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
        'send_at' => now()->addDay(),
    ]);
});

function wp16_find_check(array $checks, string $key): ?array
{
    foreach ($checks as $check) {
        if ($check['key'] === $key) {
            return $check;
        }
    }

    return null;
}

it('adds no check when the feature is off (default)', function (): void {
    $checks = app(CampaignPreflight::class)->checks($this->campaign);

    expect(wp16_find_check($checks, 'list_verified'))->toBeNull();
});

it('fails the check when the list was never verified within the window', function (): void {
    config(['emp-verification.preflight.max_age_days' => 30]);

    $preflight = app(CampaignPreflight::class);
    $check = wp16_find_check($preflight->checks($this->campaign), 'list_verified');

    expect($check)->not->toBeNull()
        ->and($check['passed'])->toBeFalse()
        ->and($check['level'])->toBe(CampaignPreflight::LEVEL_ERROR)
        ->and($preflight->passes($this->campaign))->toBeFalse();

    // A stale verification (outside N days) still fails.
    VerificationJob::withoutTenancy()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'target' => VerificationJob::TARGET_LIST,
        'provider' => 'built-in',
        'status' => VerificationJob::STATUS_COMPLETED,
        'finished_at' => Carbon::now()->subDays(45),
    ]);

    expect(wp16_find_check($preflight->checks($this->campaign), 'list_verified')['passed'])->toBeFalse();
});

it('passes once the list has a completed verification within N days', function (): void {
    config(['emp-verification.preflight.max_age_days' => 30]);

    VerificationJob::withoutTenancy()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'target' => VerificationJob::TARGET_LIST,
        'provider' => 'built-in',
        'status' => VerificationJob::STATUS_COMPLETED,
        'finished_at' => Carbon::now()->subDays(3),
    ]);

    $check = wp16_find_check(app(CampaignPreflight::class)->checks($this->campaign), 'list_verified');

    expect($check['passed'])->toBeTrue();

    // A pending/failed run does not count.
    VerificationJob::withoutTenancy()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'target' => VerificationJob::TARGET_LIST,
        'provider' => 'built-in',
        'status' => VerificationJob::STATUS_FAILED,
        'finished_at' => Carbon::now(),
    ]);

    expect(wp16_find_check(app(CampaignPreflight::class)->checks($this->campaign), 'list_verified')['passed'])->toBeTrue();
});
