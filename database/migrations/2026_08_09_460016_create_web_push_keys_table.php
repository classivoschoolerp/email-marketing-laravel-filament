<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-46 — VAPID application-server keypairs (spec §2.10 F-2.10-010).
 *
 * PER CUSTOMER, not per install. Three reasons, and the first is the one that
 * matters:
 *
 *  1. The public key is baked into every subscription a browser creates
 *     (`applicationServerKey`). A push service REJECTS a message signed with a
 *     different key than the subscription was created with. So an install-wide
 *     key would mean one tenant's key rotation silently killed every other
 *     tenant's entire subscriber base.
 *  2. The RFC 8292 `sub` claim is a contact address for the sender. On a
 *     white-label platform (§6.4) that is the tenant's address, not ours.
 *  3. Tenancy is a global scope on customer_id everywhere else (D-05); an
 *     install-wide secret would be the only cross-tenant row in the module.
 *
 * The private key is encrypted at rest (D-48). The public key is not — it is
 * published to every visitor's browser by definition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_push_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('public_key', 200);   // base64url, 65 raw octets
            $table->text('private_key');         // encrypted, base64url of 32 raw octets
            $table->string('subject', 191);      // mailto: or https: contact (RFC 8292 `sub`)
            $table->timestamps();

            $table->unique('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_push_keys');
    }
};
