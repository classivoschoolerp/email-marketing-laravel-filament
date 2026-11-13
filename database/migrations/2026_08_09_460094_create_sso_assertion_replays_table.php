<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-46 — one-time use of a SAML assertion / OIDC nonce
 * (spec §6.7 F-6.7-002).
 *
 * A SAML assertion is a bearer credential. Anything that captures one — a
 * proxy log, a referrer header, browser history, an operator debugging a POST
 * — can sign in as its subject for as long as its validity window lasts. The
 * spec's answer is that an assertion may be consumed exactly ONCE, and the
 * unique index on (kind, assertion_id) is what makes that true: the second
 * attempt loses the insert and is refused.
 *
 * A DATABASE table rather than the cache, deliberately. The cache is
 * flushable, may be an array driver, and on a multi-node install may not be
 * shared at all — and "the replay guard quietly stopped working" is not a
 * failure anybody would notice. A unique index cannot half-work.
 *
 * Rows are pruned once `expires_at` passes: past that point the assertion is
 * refused by its own validity window, so remembering it adds nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_assertion_replays', function (Blueprint $table): void {
            $table->id();

            // 'saml' | 'oidc' — one table, because both protocols are asking
            // the same question and neither generates enough rows to justify
            // its own.
            $table->string('kind', 16);

            // The assertion ID / id_token jti. 255 covers xs:ID comfortably.
            $table->string('assertion_id', 255);

            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at')->nullable();

            // THE control. Not a lookup index — a uniqueness constraint.
            $table->unique(['kind', 'assertion_id'], 'sso_assertion_replays_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_assertion_replays');
    }
};
