<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bounce servers (spec §2.9.4, WP-15). customer_id NULL = global/admin row
 * (§3.13.2 — global config, assignable to customers). The mailbox password is
 * encrypted at rest via the model's `encrypted` cast.
 *
 * hard_bounce_action / soft_bounce_threshold / soft_bounce_action implement
 * F-2.9.4-007/008 per server; classification rules live in bounce_rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bounce_servers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();

            $table->string('name');

            // F-2.9.4-001 — mailbox connection
            $table->string('hostname');
            $table->unsignedInteger('port')->default(110);
            $table->string('protocol', 8)->default('pop3');   // pop3|imap
            $table->string('username');
            $table->text('password')->nullable();             // encrypted cast
            $table->string('encryption', 8)->default('ssl');  // none|ssl|tls
            $table->boolean('validate_cert')->default(true);  // SSL/TLS validation toggle

            // F-2.9.4-002/003/004
            $table->boolean('delete_after_processing')->default(true);
            $table->unsignedInteger('max_per_batch')->default(200);
            $table->string('search_charset', 32)->default('UTF-8');
            $table->boolean('locked')->default(false);

            // F-2.9.4-007/008 — subscriber actions
            $table->string('hard_bounce_action', 16)->default('mark-bounced');
            $table->unsignedInteger('soft_bounce_threshold')->default(5);
            $table->string('soft_bounce_action', 16)->default('mark-bounced');

            // F-2.9.4-009 — lifecycle + last run
            $table->string('status', 16)->default('active');
            $table->timestamp('last_run_at')->nullable();

            $table->timestamps();

            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bounce_servers');
    }
};
