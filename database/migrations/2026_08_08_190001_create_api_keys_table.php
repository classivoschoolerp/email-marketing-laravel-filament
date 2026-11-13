<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-19 — customer API key pairs (spec §2.14.1).
 *
 * The secret is stored twice, deliberately:
 *   - `secret_hash`      SHA-256 digest used for constant-time pair/Bearer checks;
 *   - `secret_encrypted` Laravel-encrypted copy, required to verify HMAC request
 *                        signatures (the server must recompute the HMAC — a pure
 *                        hash cannot support §3.25.7 signature verification).
 * The plaintext is shown exactly once in the UI at creation/regeneration.
 * `scopes` is stored for the P2 scope-selection row but not yet surfaced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('name');
            $table->string('public_key', 64)->unique();
            $table->string('secret_hash', 64);
            $table->text('secret_encrypted');
            $table->json('ip_whitelist')->nullable();
            $table->json('scopes')->nullable();
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();

            $table->index(['customer_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
