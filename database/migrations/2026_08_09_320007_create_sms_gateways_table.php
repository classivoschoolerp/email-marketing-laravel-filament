<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-32 — SMS gateway configuration (spec §2.10 F-2.10-002).
 *
 * Mirrors delivery_servers: `driver` is the stable registry key
 * (SmsGatewayRegistry / config emp-sms.gateways), credentials live in the
 * encrypted api_key / api_secret columns and every driver-specific extra
 * goes in `config` json (D-48 convention). `uid` is the public identifier
 * used in the inbound STOP-keyword and delivery-report webhook URLs, so
 * those endpoints resolve a tenant without any ambient auth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_gateways', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('uid', 36)->unique();
            $table->string('name');
            $table->string('driver', 32);
            $table->string('sender_id')->nullable();
            $table->text('api_key')->nullable();
            $table->text('api_secret')->nullable();
            $table->json('config')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['customer_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_gateways');
    }
};
