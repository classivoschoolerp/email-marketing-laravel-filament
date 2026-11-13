<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seed lists and seed addresses (spec §2.9.9 F-2.9.9-004, WP-46).
 *
 * A seed list is a set of REAL mailboxes at the major providers. The whole
 * point of the feature is reading those mailboxes back afterwards, so each
 * address may link to the §2.9.6 email box monitor that can open it —
 * `email_box_monitor_id` NULL means "we can send to this address but cannot
 * see where it landed", which the report has to state rather than assume.
 *
 * Tenant-owned (customer_id NULL = a global/admin seed list, §3.13.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seed_lists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 191);
            $table->string('description', 512)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['customer_id', 'active']);
        });

        Schema::create('seed_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seed_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('email_box_monitor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email', 320);
            $table->string('provider', 16)->default('other');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['seed_list_id', 'email']);
            $table->index(['customer_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seed_addresses');
        Schema::dropIfExists('seed_lists');
    }
};
