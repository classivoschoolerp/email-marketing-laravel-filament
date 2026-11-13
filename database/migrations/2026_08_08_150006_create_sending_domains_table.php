<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sending domains (spec §2.9.7, WP-15). customer_id NULL = global/admin row
 * (§3.13.5). The DKIM private key is encrypted at rest via the model's
 * `encrypted` cast; the public key is published in DNS anyway and stays
 * plain. Per-record verified flags feed the F-2.9.7-003 pass/fail display;
 * `status` is the F-2.9.7-006 badge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sending_domains', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();

            $table->string('name'); // the domain, e.g. mail.example.com

            // F-2.9.7-001 — DKIM keypair (selector + RSA-2048)
            $table->string('dkim_selector', 64)->default('default');
            $table->text('dkim_private_key')->nullable(); // encrypted cast
            $table->text('dkim_public_key')->nullable();  // PEM, plain

            // F-2.9.7-003 — per-record pass/fail
            $table->boolean('dkim_verified')->default(false);
            $table->boolean('spf_verified')->default(false);
            $table->boolean('dmarc_verified')->default(false);
            $table->boolean('mx_verified')->default(false); // optional record

            // F-2.9.7-006 — badge
            $table->string('status', 16)->default('pending'); // pending|verified|failed
            $table->timestamp('last_checked_at')->nullable();

            $table->timestamps();

            $table->index('name');
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sending_domains');
    }
};
