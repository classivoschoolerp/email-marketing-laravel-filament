<?php

declare(strict_types=1);

namespace App\Services\Verification;

use App\Models\Customer;
use App\Models\Subscriber;
use App\Models\VerificationJob;
use App\Models\VerificationResult as VerificationResultRow;
use Illuminate\Validation\ValidationException;

/**
 * Synchronous single-address verification (§2.9.12 F-2.9.12-004 "single
 * address (real-time)"): consumes one credit, records a target=single
 * VerificationJob + result row for the history log, and tags every
 * subscriber of the customer holding that address. Never applies
 * destructive auto-actions — a one-off lookup must not delete data.
 */
class SingleAddressVerifier
{
    public function __construct(
        private readonly Credentials $credentials,
        private readonly VerificationCredits $credits,
    ) {}

    public function verify(Customer $customer, string $email, ?string $providerKey = null): VerificationResultRow
    {
        $email = mb_strtolower(trim($email));

        // WP-26: the description lands on the F-3.26.1-014 consumption ledger row.
        if (! $this->credits->consume($customer, 1, __('billing.credits.verification_single'))) {
            throw ValidationException::withMessages(['email' => __('verification.errors.no_credits')]);
        }

        $provider = $this->credentials->providerFor($customer, $providerKey);
        $result = $provider->verify($email);

        $job = VerificationJob::withoutTenancy()->create([
            'customer_id' => $customer->id,
            'target' => VerificationJob::TARGET_SINGLE,
            'single_email' => $email,
            'provider' => $provider->key(),
            'auto_action' => VerificationJob::ACTION_TAG,
            'status' => VerificationJob::STATUS_COMPLETED,
            'total' => 1,
            'processed' => 1,
            $result->status->value.'_count' => 1,
            'credits_used' => 1,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $row = VerificationResultRow::query()->create([
            'verification_job_id' => $job->id,
            'email' => $email,
            'status' => $result->status->value,
            'reason' => $result->reason,
            'provider' => $provider->key(),
        ]);

        Subscriber::withoutTenancy()
            ->where('customer_id', $customer->id)
            ->where('email', $email)
            ->update([
                'verification_status' => $result->status->value,
                'verified_at' => now(),
            ]);

        return $row;
    }
}
