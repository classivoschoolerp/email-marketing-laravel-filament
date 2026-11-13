<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feedback-loop (FBL) servers (spec §2.9.5, WP-15): same mailbox config as
 * bounce servers (F-2.9.5-001), processed by fbl:process which parses ARF
 * reports. customer_id NULL = global/admin row (§3.13.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fbl_servers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();

            $table->string('name');

            // F-2.9.5-001 — same mailbox connection block as bounce_servers
            $table->string('hostname');
            $table->unsignedInteger('port')->default(110);
            $table->string('protocol', 8)->default('pop3');   // pop3|imap
            $table->string('username');
            $table->text('password')->nullable();             // encrypted cast
            $table->string('encryption', 8)->default('ssl');  // none|ssl|tls
            $table->boolean('validate_cert')->default(true);

            $table->boolean('delete_after_processing')->default(true);
            $table->unsignedInteger('max_per_batch')->default(200);
            $table->string('search_charset', 32)->default('UTF-8');
            $table->boolean('locked')->default(false);

            $table->string('status', 16)->default('active');
            $table->timestamp('last_run_at')->nullable();

            $table->timestamps();

            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fbl_servers');
    }
};
