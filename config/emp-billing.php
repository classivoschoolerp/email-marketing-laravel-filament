<?php

declare(strict_types=1);
use App\Services\Verification\VerificationCredits;

/*
 * WP-23 billing configuration (spec §3.26).
 *
 * NOTE: §3.25.14 "Monetization settings" is a P2 settings group. Until it
 * lands, these values are the operator knobs; the P2 MonetizationSettings
 * class supersedes this file (each key below maps 1:1 onto a future
 * setting). Money is ALWAYS integer minor units (docs/ARCHITECTURE.md).
 */
return [

    // Fallback ISO code used before the currencies table is seeded and as
    // the denomination of fixed-amount promo values / minimum order amounts.
    'default_currency' => env('EMP_BILLING_DEFAULT_CURRENCY', 'USD'),

    'invoice' => [
        // Order/invoice numbers: prefix + zero-padded sequential id.
        'prefix' => env('EMP_BILLING_INVOICE_PREFIX', 'INV-'),
        'pad' => 6,
    ],

    // 'exclusive' — tax is added on top of the (discounted) plan price.
    // 'inclusive' — the plan price already contains tax; tax lines are
    // extracted for display (§3.26.6 tax-inclusive vs exclusive).
    'tax_mode' => env('EMP_BILLING_TAX_MODE', 'exclusive'),

    'credits' => [
        // customer_limits key for purchasable sending credits (WP-23-defined;
        // the send path integrates with it in a later WP — see PlanApplicator).
        'sending_limit_key' => 'sending_credits',
        // D-73: verification credit packs top up this existing key.
        'verification_limit_key' => VerificationCredits::LIMIT_KEY,
        // Ledger balance below which a low-balance warning may be surfaced.
        'low_balance_threshold' => 100,
    ],

    'offline' => [
        // Accepted proof-of-payment upload types + max size (KB).
        'proof_accepted_types' => ['application/pdf', 'image/png', 'image/jpeg'],
        'proof_max_kb' => 5_120,
    ],

    // Days before subscription end at which billing:check-expiring warns.
    'expiry_warning_days' => 7,

    /*
     * F-2.15.6-010 (WP-39) plan expiry warnings & grace-period behaviour.
     * When a period lapses without a renewal (auto-renew off, or the
     * customer cancelled) the account keeps working for `grace_period_days`
     * so a late payment never costs anybody data; `billing:process-expiries`
     * opens the window and closes it. 0 = no grace, downgrade immediately
     * (D-23). The dunning path is NOT affected: exhausted retries already
     * gave the customer the retry schedule as its own grace.
     */
    'grace_period_days' => 3,

    /*
     * F-3.26.6-005 automatic recurring billing (WP-26) — the operator-defined
     * schedule the renewal engine runs on. `billing:process-renewals` is the
     * command; the cron cadence is a routes/console.php one-liner (frozen
     * file, see the WP-26 report):
     *     Schedule::command('billing:process-renewals')->hourly();
     */
    'renewals' => [
        // Master switch: false makes the command a no-op (manual renewals only).
        'enabled' => env('EMP_BILLING_AUTO_RENEW', true),

        // How many charge attempts a period gets before the plan is dropped.
        // 1 = charge once, no retries.
        'max_attempts' => 3,

        // Days to wait before dunning attempt N+1. The last entry repeats if
        // max_attempts exceeds the list length.
        'backoff_days' => [3, 5],

        // Renew this many days BEFORE ends_at as well, so a payment that needs
        // a retry still has runway before the period lapses. 0 = on/after
        // ends_at only (D-23: zero means disabled).
        'lead_days' => 0,

        // Safety valve for one command run.
        'batch_limit' => 200,
    ],

    /*
     * F-3.26.1-014 credit balance alerts + auto top-up (WP-26). Per-customer
     * overrides live in customer_preferences under the keys below.
     */
    'credit_alerts' => [
        'enabled' => true,
        // Default low-balance threshold when the customer set none.
        'default_threshold' => 100,
        // Dedupe window for the alert email (mirrors QuotaNotifier's
        // once-per-period cache marker).
        'dedupe_days' => 7,
        // customer_preferences keys (per credit type, suffixed .sending/.verification)
        'threshold_key' => 'billing.credits.low_threshold',
        'auto_topup_key' => 'billing.credits.auto_topup',
        'auto_topup_plan_key' => 'billing.credits.auto_topup_plan',
        'auto_topup_gateway_key' => 'billing.credits.auto_topup_gateway',
    ],
];
