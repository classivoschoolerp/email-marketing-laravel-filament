<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email box monitors (spec §2.9.6, WP-33): a POP3/IMAP mailbox polled by
 * mailbox-monitors:process which applies condition → action rules to every
 * message it finds. Column set mirrors bounce_servers/fbl_servers so the
 * WP-15 mailbox machinery (IsMailboxServer, MailboxClientFactory,
 * MailboxConnectionTester) is reused verbatim.
 *
 * customer_id NULL = global row processed for every tenant (§3.13.2
 * convention). `password` is encrypted at rest via the model cast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_box_monitors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();

            $table->string('name');

            // F-2.9.6-001 — mailbox connection
            $table->string('hostname');
            $table->unsignedSmallInteger('port')->default(995);
            $table->string('protocol', 8)->default('pop3');     // pop3 | imap
            $table->string('username');
            $table->text('password')->nullable();               // encrypted cast
            $table->string('encryption', 8)->default('ssl');    // none | ssl | tls
            $table->boolean('validate_cert')->default(true);

            // Processing
            $table->boolean('delete_after_processing')->default(true);
            $table->unsignedInteger('max_per_batch')->default(200);
            $table->string('search_charset', 32)->default('UTF-8');

            $table->boolean('locked')->default(false);
            $table->string('status', 16)->default('active');    // active | inactive
            $table->timestamp('last_run_at')->nullable();

            $table->timestamps();

            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_box_monitors');
    }
};
