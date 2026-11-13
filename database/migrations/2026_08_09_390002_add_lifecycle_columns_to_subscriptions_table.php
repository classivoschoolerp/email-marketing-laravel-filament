<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-2.15.6-004 / F-2.15.6-010 customer-side subscription lifecycle (WP-39).
 *
 * - cancel_at_period_end / cancelled_at: "cancel" from the customer panel is
 *   a NON-destructive opt-out. The plan keeps working until ends_at; only
 *   auto_renew is switched off. Immediate cancellation is the operator's
 *   job (refund + downgrade), never a self-service footgun.
 * - grace_ends_at: F-2.15.6-010 grace period. When a period lapses (expiry
 *   or exhausted dunning) the account keeps its entitlements for a
 *   configurable window so a late payment does not cost the customer data.
 * - expiry_warning_count: how many "your plan expires soon" notices this
 *   period has produced, so escalating warnings never repeat forever.
 * - changed_from_subscription_id / proration_credit: audit of a mid-period
 *   plan change (F-2.15.6-004). The credit is integer minor units of
 *   proration_currency — money is never a float (docs/ARCHITECTURE.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->boolean('cancel_at_period_end')->default(false)->after('auto_renew');
            $table->timestamp('cancelled_at')->nullable()->after('downgraded_at');
            $table->timestamp('grace_ends_at')->nullable()->after('cancelled_at');
            $table->unsignedInteger('expiry_warning_count')->default(0)->after('expiry_notified_at');

            $table->unsignedBigInteger('changed_from_subscription_id')->nullable()->after('grace_ends_at');
            $table->integer('proration_credit')->default(0)->after('changed_from_subscription_id');
            $table->string('proration_currency', 3)->nullable()->after('proration_credit');

            $table->index(['grace_ends_at'], 'subscriptions_grace_idx');
        });

        // The change-plan order carries the proration so PlanApplicator can
        // stamp it onto the subscription it opens whenever the payment
        // settles — which for offline/bank transfer is days later.
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('previous_subscription_id')->nullable()->after('subscription_id');
            $table->integer('proration_credit')->default(0)->after('previous_subscription_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['previous_subscription_id', 'proration_credit']);
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropIndex('subscriptions_grace_idx');
            $table->dropColumn([
                'cancel_at_period_end',
                'cancelled_at',
                'grace_ends_at',
                'expiry_warning_count',
                'changed_from_subscription_id',
                'proration_credit',
                'proration_currency',
            ]);
        });
    }
};
