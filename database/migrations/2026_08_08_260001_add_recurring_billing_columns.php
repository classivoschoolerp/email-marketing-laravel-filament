<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-3.26.6-005 automatic recurring billing & charging (WP-26).
 *
 * Subscriptions gain everything the renewal engine needs:
 * - auto_renew            operator/customer opt-out of automatic charging
 * - gateway_key           the gateway the current period was paid with; the
 *                         renewal charge is attempted on the same one
 * - gateway_customer_ref  off-session charging handles (Stripe customer +
 *   payment_method_ref    payment_method). Encrypted at rest — they are
 *                         bearer-ish references to a stored card.
 * - dunning_attempts      failed charge attempts in the CURRENT period
 * - next_retry_at         when the dunning retry schedule allows the next
 *                         charge (config emp-billing.renewals.backoff_days)
 * - last_renewal_error    last gateway message (admin diagnostics)
 * - renewed_at/renewals   audit of the automatic extension
 * - downgraded_at         set when final dunning failure removed the plan
 *
 * Orders gain `subscription_id` + `is_renewal`: a renewal order EXTENDS the
 * named subscription instead of opening a new one (PlanApplicator), which
 * also makes the offline/bank-transfer renewal path work — the operator
 * approves the pending renewal order days later and the same subscription
 * period is extended.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->boolean('auto_renew')->default(true)->after('status');
            $table->string('gateway_key', 64)->nullable()->after('auto_renew');
            $table->text('gateway_customer_reference')->nullable()->after('gateway_key');
            $table->text('payment_method_reference')->nullable()->after('gateway_customer_reference');
            $table->unsignedInteger('dunning_attempts')->default(0)->after('expiry_notified_at');
            $table->timestamp('next_retry_at')->nullable()->after('dunning_attempts');
            $table->string('last_renewal_error')->nullable()->after('next_retry_at');
            $table->timestamp('renewed_at')->nullable()->after('last_renewal_error');
            $table->unsignedInteger('renewal_count')->default(0)->after('renewed_at');
            $table->timestamp('downgraded_at')->nullable()->after('renewal_count');

            $table->index(['status', 'next_retry_at'], 'subscriptions_status_next_retry_idx');
            $table->index(['auto_renew', 'status', 'ends_at'], 'subscriptions_renewal_due_idx');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('subscription_id')->nullable()->after('price_plan_id')
                ->constrained()->nullOnDelete();
            $table->boolean('is_renewal')->default(false)->after('subscription_id');

            $table->index(['subscription_id', 'status'], 'orders_subscription_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_subscription_status_idx');
            $table->dropConstrainedForeignId('subscription_id');
            $table->dropColumn('is_renewal');
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropIndex('subscriptions_status_next_retry_idx');
            $table->dropIndex('subscriptions_renewal_due_idx');
            $table->dropColumn([
                'auto_renew', 'gateway_key', 'gateway_customer_reference', 'payment_method_reference',
                'dunning_attempts', 'next_retry_at', 'last_renewal_error', 'renewed_at',
                'renewal_count', 'downgraded_at',
            ]);
        });
    }
};
