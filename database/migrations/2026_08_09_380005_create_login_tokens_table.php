<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time login tokens (spec §6.7 F-6.7-001).
 *
 * An external app (a reseller portal, a partner dashboard) asks the API for a
 * token and redirects the browser at it; redeeming it opens an authenticated
 * session for exactly one account, once.
 *
 * Storage rules, all load-bearing:
 *  - only the SHA-256 of the token is stored. A database leak must not hand
 *    an attacker working login links, so the plaintext exists solely in the
 *    API response that created it.
 *  - `used_at` is set inside a conditional UPDATE, so two concurrent
 *    redemptions cannot both win (see OneTimeLoginTokenService::consume).
 *  - consumed rows are RETAINED, not deleted: a replay attempt against a
 *    burnt token has to be visible in an audit, not silently a 404.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_tokens', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();

            // 'admin' | 'customer' — the guard the session will be opened on.
            $table->string('guard', 32)->index();

            // Polymorphic subject (App\Models\User | App\Models\Customer).
            $table->nullableMorphs('model');

            $table->string('token_hash', 64)->unique();

            // Where to land after redemption. Validated as a same-origin path
            // at redemption time; an off-site value is ignored, never
            // followed (open-redirect guard).
            $table->string('redirect_to', 2048)->nullable();

            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable()->index();

            // Audit trail: who minted it and from where, who burnt it.
            $table->foreignId('api_key_id')->nullable()->constrained('api_keys')->nullOnDelete();
            $table->string('created_ip', 45)->nullable();
            $table->string('used_ip', 45)->nullable();
            $table->string('used_user_agent')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_tokens');
    }
};
