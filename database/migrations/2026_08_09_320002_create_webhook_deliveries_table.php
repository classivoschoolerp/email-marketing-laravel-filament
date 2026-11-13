<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-32 — outgoing webhook delivery log (spec §2.14.2 F-2.14.2-003/004).
 *
 * One row per delivery ATTEMPT SERIES (not per HTTP attempt): `attempts`
 * counts the tries, `next_attempt_at` carries the exponential-backoff
 * schedule and the request/response bodies are stored verbatim so the log can
 * answer "what exactly did you send me?" (F-2.14.2-004).
 *
 * `request_body` is the byte-exact JSON that was signed — replaying it
 * reproduces the same signature, which is what makes manual replay
 * (F-2.14.2-004) a faithful re-send rather than a re-render. customer_id is
 * denormalised from the webhook so the tenancy scope fences the log directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('webhook_id')->constrained('webhooks')->cascadeOnDelete();
            $table->string('uid', 36)->unique();
            $table->string('event', 64);
            $table->string('url', 2048);
            $table->longText('request_body');
            $table->json('request_headers')->nullable();
            $table->string('status', 16)->default('pending'); // pending|success|failed
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->json('response_headers')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedBigInteger('replay_of_id')->nullable();
            $table->timestamps();

            $table->index(['webhook_id', 'status']);
            $table->index(['customer_id', 'event']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
