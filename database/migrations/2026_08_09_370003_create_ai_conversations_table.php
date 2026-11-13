<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI assistant chatbox history (spec §2.15.8 F-2.15.8-016, WP-37).
 *
 * The spec says the chatbox is available on BOTH the admin and the tenant
 * side, so a conversation is owned by exactly one of them:
 *   customer_id set → a tenant conversation (global scope applies);
 *   user_id set     → an operator conversation on the admin panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table): void {
            $table->id();
            $table->string('uid', 36)->unique();

            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('title', 190)->nullable();
            $table->string('engine', 64)->nullable();

            $table->timestamps();

            $table->index(['customer_id', 'updated_at']);
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('ai_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_conversation_id')->constrained()->cascadeOnDelete();

            // user | assistant — the two roles the transcript renders.
            $table->string('role', 16);
            $table->longText('content');

            $table->unsignedInteger('total_tokens')->default(0);
            $table->timestamps();

            $table->index(['ai_conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
    }
};
