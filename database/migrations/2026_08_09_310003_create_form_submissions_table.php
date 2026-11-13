<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-31 — one row per completed form submission (spec §2.7).
 *
 * The subscriber is always created/updated through the Subscriber MODEL so
 * the list's subscriber-action cascades (F-2.2.2-015..018) fire; this table
 * is the form-side audit record of the same event: the exact values posted,
 * where the visitor came from (F-2.7-012 "by source") and the literal GDPR
 * consent sentence they agreed to (F-2.7-009).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_submissions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('form_id')->constrained('forms')->cascadeOnDelete();
            $table->foreignId('subscriber_id')->nullable()
                ->constrained('subscribers')->nullOnDelete();

            $table->string('email', 191);
            // Posted values keyed by merge tag (utf8mb4: CJK + emoji safe).
            $table->json('data')->nullable();

            $table->string('source', 64)->default('direct');
            $table->string('page_url', 2048)->nullable();
            $table->string('referrer', 2048)->nullable();
            $table->string('device', 16)->nullable();

            $table->boolean('consent_given')->default(false);
            $table->text('consent_text')->nullable();

            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamps();

            $table->index(['form_id', 'created_at']);
            $table->index(['customer_id', 'created_at']);
            $table->index(['form_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
    }
};
