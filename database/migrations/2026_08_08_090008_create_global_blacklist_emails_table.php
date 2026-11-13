<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-09 — forward-base of the §3.17 global email blacklist, created here so
 * approved block requests (§3.19) have a destination. Deliberately minimal:
 * WP-16 owns the full blacklist module (regex rules, domain-level entries,
 * import/export, monitors) and extends this table rather than replacing it.
 *
 * `source` ∈ manual|block-request|bounce|complaint|import (free string —
 * WP-16 formalizes). `customer_id` is nullable: null = truly global entry;
 * set = originated from a customer context (spec §3.17 "customer (if any)").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('global_blacklist_emails', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->string('reason')->nullable();
            $table->string('source', 32)->default('manual')->index();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('global_blacklist_emails');
    }
};
