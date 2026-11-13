<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-06/07 — subscribers (spec §2.2.4/§2.2.5). Composite unique (list_id,
 * email); the (customer_id, list_id, status, email) index is mandated by
 * spec §4.6. last_open_at / last_click_at are engagement placeholders filled
 * by the campaign tracking work package. Bounce columns back the
 * deliverability notes (F-2.2.5-009).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscribers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('list_id')->constrained('lists')->cascadeOnDelete();
            $table->string('email');
            // confirmed|unconfirmed|unsubscribed|blacklisted|disabled|moved|bounced
            $table->string('status', 16)->default('unconfirmed');
            // form|import|api|manual|automation
            $table->string('source', 16)->default('manual');
            $table->string('ip', 45)->nullable();
            $table->timestamp('subscribed_at')->nullable();
            $table->string('subscribe_ip', 45)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('confirm_ip', 45)->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->string('unsubscribe_ip', 45)->nullable();
            $table->string('unsubscribe_reason')->nullable();

            // Engagement placeholders (campaign WP fills these).
            $table->timestamp('last_open_at')->nullable();
            $table->timestamp('last_click_at')->nullable();

            // Deliverability notes (F-2.2.5-009).
            $table->timestamp('last_bounce_at')->nullable();
            $table->string('bounce_type', 8)->nullable(); // hard|soft
            $table->string('last_bounce_reason')->nullable();
            $table->unsignedInteger('bounces_count')->default(0);
            $table->unsignedInteger('complaints_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['list_id', 'email']);
            $table->index(['customer_id', 'list_id', 'status', 'email'], 'subscribers_tenant_list_status_email');
            $table->index(['list_id', 'status']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscribers');
    }
};
