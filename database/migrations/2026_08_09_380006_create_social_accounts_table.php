<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Social login links (spec §6.7 F-6.7-003).
 *
 * One row per (provider, provider account) pair bound to a local customer.
 * The provider's immutable subject id — NOT the email — is the join key:
 * emails at Google and Microsoft can be reassigned, and matching on a
 * changeable attribute is how account-takeover bugs happen.
 *
 * No access or refresh token is stored. This is identity only: the app never
 * calls the provider again on the user's behalf, so keeping a long-lived
 * credential would be a liability with no purpose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table): void {
            $table->id();

            $table->string('provider', 32)->index();
            $table->string('provider_user_id', 191);

            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            // Snapshot of what the provider asserted at link time — shown in
            // the UI so a customer can tell two Google accounts apart.
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->string('avatar_url', 2048)->nullable();

            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            $table->index(['customer_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
