<?php

declare(strict_types=1);

namespace App\Services\Verification;

use App\Enums\VerificationStatus;
use App\Models\Customer;
use App\Models\EmailList;

/**
 * Real-time verification gate (§2.9.12 F-2.9.12-005): called from the ONE
 * marked hook in the public subscribe path (PublicSubscribeController,
 * WP-08/09) and — when WP-19 lands — from the API insert endpoint. Config
 * toggle emp-verification.realtime.enabled, DEFAULT OFF: when on, the list
 * owner's provider verifies the address synchronously and `undeliverable`
 * verdicts are rejected (`risky` too with block_risky). Deliberately does
 * NOT consume verification credits: a public form must never let strangers
 * drain a customer's balance. Provider failures map to Unknown and pass.
 */
class RealTimeGate
{
    public function __construct(private readonly Credentials $credentials) {}

    /** Rejection message for the subscribe form, or null to let it through. */
    public function rejects(EmailList $list, string $email): ?string
    {
        if (! (bool) config('emp-verification.realtime.enabled', false)) {
            return null;
        }

        $customer = Customer::query()->find($list->customer_id);

        if ($customer === null) {
            return null;
        }

        $result = $this->credentials->providerFor($customer)->verify($email);

        if ($result->status === VerificationStatus::Undeliverable) {
            return __('verification.realtime.undeliverable');
        }

        if ($result->status === VerificationStatus::Risky
            && (bool) config('emp-verification.realtime.block_risky', false)) {
            return __('verification.realtime.risky');
        }

        return null;
    }
}
