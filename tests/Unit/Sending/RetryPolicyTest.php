<?php

declare(strict_types=1);

use App\Sending\RetryPolicy;
use App\Sending\SendResult;

// F-2.9.2-023 — transient failures retry while attempts remain
it('retries transient failures until max attempts', function (): void {
    $policy = new RetryPolicy(maxAttempts: 3, backoffSeconds: 60);
    $transient = SendResult::transientFailure('421 try later');

    expect($policy->shouldRetry($transient, 1))->toBeTrue()
        ->and($policy->shouldRetry($transient, 2))->toBeTrue()
        ->and($policy->shouldRetry($transient, 3))->toBeFalse();
});

// F-2.9.2-023 — permanent failures and successes never retry
it('never retries permanent failures or successes', function (): void {
    $policy = new RetryPolicy(maxAttempts: 5, backoffSeconds: 60);

    expect($policy->shouldRetry(SendResult::permanentFailure('550 rejected'), 1))->toBeFalse()
        ->and($policy->shouldRetry(SendResult::success('id'), 1))->toBeFalse();
});

// F-2.9.2-023 — exponential backoff from the configured base
it('backs off exponentially per attempt', function (): void {
    $policy = new RetryPolicy(maxAttempts: 5, backoffSeconds: 300);

    expect($policy->delayForAttempt(1))->toBe(300)
        ->and($policy->delayForAttempt(2))->toBe(600)
        ->and($policy->delayForAttempt(3))->toBe(1200)
        ->and($policy->delayForAttempt(4))->toBe(2400);
});

it('caps the backoff at one day', function (): void {
    $policy = new RetryPolicy(maxAttempts: 30, backoffSeconds: 3600);

    expect($policy->delayForAttempt(20))->toBe(RetryPolicy::MAX_BACKOFF_SECONDS);
});

// Settings-unavailable fallback (config values) — the CampaignSettings-driven
// path is covered in the feature suite where the DB exists.
it('falls back to config values when settings are unavailable', function (): void {
    config(['emp-sending.retry.max_attempts' => 7, 'emp-sending.retry.backoff_seconds' => 120]);

    $policy = RetryPolicy::fromSettings();

    expect($policy->maxAttempts)->toBe(7)
        ->and($policy->backoffSeconds)->toBe(120);
});

it('enforces sane minimums', function (): void {
    $policy = new RetryPolicy(maxAttempts: 1, backoffSeconds: 0);

    expect($policy->shouldRetry(SendResult::transientFailure('x'), 1))->toBeFalse()
        ->and($policy->delayForAttempt(1))->toBe(0);
});
