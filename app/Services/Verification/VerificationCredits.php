<?php

declare(strict_types=1);

namespace App\Services\Verification;

use App\Enums\CreditType;
use App\Models\Customer;
use App\Models\CustomerLimit;
use App\Services\Billing\BillingCredits;
use App\Services\Billing\CreditAlerts;
use App\Services\Quota\QuotaService;
use Throwable;

/**
 * Verification credit counter (§2.9.12 F-2.9.12-008/011, D-42 style).
 *
 * The balance is the customer's own customer_limits row under
 * `verification_credits` (dotless like the sibling sms_credits key — dotted
 * limit keys would break the §3.5 matrix form state): its value is the
 * REMAINING credits and is decremented as verifications run. When the
 * customer has no row, the group/config allotment from QuotaService is the
 * starting balance and a customer row is materialised on first consumption.
 * null / -1 = unlimited (D-42). Selling top-ups (billable add-on, SaaS mode)
 * is the WP-23 billing integration — it will simply increment this row.
 */
class VerificationCredits
{
    public const LIMIT_KEY = 'verification_credits';

    public function __construct(private readonly QuotaService $quota) {}

    /** Remaining credits; null = unlimited. */
    public function remaining(Customer $customer): ?int
    {
        $own = CustomerLimit::query()
            ->where('customer_id', $customer->id)
            ->where('key', self::LIMIT_KEY)
            ->first();

        $value = $own !== null ? $own->value : $this->quota->limitFor($customer, self::LIMIT_KEY);

        if ($value === null || $value < 0) {
            return null;
        }

        return $value;
    }

    public function hasCredits(Customer $customer, int $needed = 1): bool
    {
        $remaining = $this->remaining($customer);

        return $remaining === null || $remaining >= $needed;
    }

    /**
     * Consume credits; false (and no decrement) when the balance is short.
     * Unlimited customers never materialise a row.
     *
     * WP-26 (F-3.26.1-014): every successful consumption appends a NEGATIVE
     * credit_transactions row through BillingCredits::record() and runs the
     * low-balance check (alert + optional auto top-up). Both are wrapped in
     * a catch-all: the ledger and the alerting are observability features
     * and must never be able to break a verification run — a failure is
     * swallowed and the credit still counts as consumed. Unlimited
     * customers write no ledger row (there is no balance to move).
     */
    public function consume(Customer $customer, int $amount = 1, ?string $description = null): bool
    {
        $remaining = $this->remaining($customer);

        if ($remaining === null) {
            return true;
        }

        if ($remaining < $amount) {
            return false;
        }

        $balanceAfter = $remaining - $amount;

        CustomerLimit::query()->updateOrCreate(
            ['customer_id' => $customer->id, 'key' => self::LIMIT_KEY],
            ['value' => $balanceAfter],
        );

        $this->recordLedger($customer, $amount, $balanceAfter, $description);

        return true;
    }

    private function recordLedger(Customer $customer, int $amount, int $balanceAfter, ?string $description): void
    {
        if (! class_exists(BillingCredits::class)) {
            return;
        }

        try {
            app(BillingCredits::class)->record(
                $customer,
                CreditType::Verification,
                -$amount,
                $description ?? __('billing.credits.consumption'),
                null,
                $balanceAfter,
            );

            app(CreditAlerts::class)->check($customer, CreditType::Verification);
        } catch (Throwable) {
            // Ledger/alerting is best-effort — never fail a verification run.
        }
    }
}
