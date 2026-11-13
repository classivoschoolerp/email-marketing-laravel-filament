<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mass emails to customers (spec §3.11, WP-18): the composed message plus a
 * per-recipient row per targeted customer. Recipient rows double as the open
 * tracking store — the signed pixel route stamps opened_at/opens_count.
 *
 * Audience selection: all | group (customer_group_id) | status
 * (customer_status). Per the CustomerResource precedent, plan filtering
 * arrives with billing (WP-23) — the customer group carries plans until then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mass_emails', function (Blueprint $table): void {
            $table->id();
            $table->string('uid', 36)->unique();
            $table->string('subject', 500);
            $table->longText('body');
            $table->string('audience', 16)->default('all'); // all | group | status
            $table->foreignId('customer_group_id')->nullable()->constrained('customer_groups')->nullOnDelete();
            $table->string('customer_status', 32)->nullable();
            $table->string('status', 16)->default('queued'); // scheduled | queued | sending | sent
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->unsignedInteger('recipients_total')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('mass_email_recipients', function (Blueprint $table): void {
            $table->id();
            $table->string('uid', 36)->unique();
            $table->foreignId('mass_email_id')->constrained('mass_emails')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('email');
            $table->string('status', 16)->default('pending'); // pending | sent | failed
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('opened_at')->nullable();
            $table->unsignedInteger('opens_count')->default(0);
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->unique(['mass_email_id', 'customer_id']);
            $table->index(['mass_email_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mass_email_recipients');
        Schema::dropIfExists('mass_emails');
    }
};
