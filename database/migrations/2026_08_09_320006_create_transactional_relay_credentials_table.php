<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-32 — per-customer SMTP relay credentials (spec §2.11 F-2.11-002).
 *
 * ONE row per customer: host/port/username plus a generated password stored
 * both hashed (constant-time authentication) and encrypted (reveal-on-demand
 * in the panel), mirroring the ApiKey secret_hash + secret_encrypted pair.
 *
 * SCOPE NOTE, stated plainly rather than implied: this work package does NOT
 * ship an inbound SMTP listener. These credentials are the identity a relay
 * front-end authenticates against — see
 * App\Services\Transactional\RelayCredentialService::authenticate(), which is
 * the single verification entry point a future SMTP proxy (or an operator's
 * Postfix/Haraka/smtpd shim) calls before handing the message to
 * TransactionalSender. Nothing in this WP listens on port 25/587.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactional_relay_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained('customers')->cascadeOnDelete();
            $table->string('host');
            $table->unsignedSmallInteger('port')->default(587);
            $table->string('username')->unique();
            $table->string('password_hash');
            $table->text('password_encrypted');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactional_relay_credentials');
    }
};
