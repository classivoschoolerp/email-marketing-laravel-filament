<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-2.15.6-007 saved payment methods (WP-39).
 *
 * The platform never sees a PAN. A row is a *handle* to a card (or wallet)
 * the gateway already vaults, plus the non-sensitive display fields the
 * gateway hands back so the customer can tell two cards apart. Both handles
 * are encrypted at rest, mirroring the columns WP-26 put on `subscriptions`
 * (which this table now supersedes as the source of truth: the subscription
 * points at a payment_methods row and copies the handles onto itself so the
 * off-session charge path in SubscriptionRenewer keeps working unchanged).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // Registry key of the gateway that vaulted this method.
            $table->string('gateway_key', 64);

            // Encrypted gateway handles (Stripe: customer + payment_method).
            $table->text('customer_reference')->nullable();
            $table->text('reference');

            // Display-only, never sensitive: 'card' | 'paypal' | 'bank' ...
            $table->string('type', 32)->default('card');
            $table->string('brand', 32)->nullable();
            $table->string('last_four', 4)->nullable();
            $table->unsignedTinyInteger('exp_month')->nullable();
            $table->unsignedSmallInteger('exp_year')->nullable();
            $table->string('label')->nullable();

            $table->boolean('is_default')->default(false);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'is_default']);
            $table->index(['customer_id', 'gateway_key']);
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->foreignId('payment_method_id')->nullable()->after('gateway_key')
                ->constrained('payment_methods')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_method_id');
        });

        Schema::dropIfExists('payment_methods');
    }
};
