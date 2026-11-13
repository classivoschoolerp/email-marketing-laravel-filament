<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-46 — WhatsApp Business gateways (spec §2.10 F-2.10-009).
 *
 * Deliberately a separate table from `sms_gateways` rather than a `channel`
 * column on it: a WhatsApp connection is addressed by a phone-number id and a
 * WhatsApp Business Account id, neither of which an SMS gateway has, and the
 * template-approval flow hangs off the WABA id. Sharing the table would mean
 * half the columns were null for every row on both sides.
 *
 * Secrets live in the encrypted `api_key` / `api_secret` columns per D-48;
 * everything driver-specific lives in the `config` json.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_gateways', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('uid', 36)->unique();
            $table->string('name');
            $table->string('driver', 32); // meta_cloud | twilio_whatsapp | …
            $table->text('api_key')->nullable();    // encrypted (access token / account sid)
            $table->text('api_secret')->nullable(); // encrypted (app secret / auth token)
            $table->string('phone_number', 32)->nullable(); // the display number, E.164
            $table->json('config')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['customer_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_gateways');
    }
};
