<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracking domains (spec §2.9.8, WP-15). The domain column is `name` — the
 * WP-11 campaign form's Schema-guarded select plucks tracking_domains.name.
 * customer_id NULL = global/admin row (§3.13.6). ssl_status is the
 * F-2.9.8-003 provisioning placeholder (none|pending|active) — automatic
 * HTTPS via Let's Encrypt is P2 (F-2.9.8-005).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_domains', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();

            $table->string('name'); // the domain, e.g. track.example.com

            $table->boolean('verified')->default(false);
            $table->string('ssl_status', 16)->default('none');  // none|pending|active
            $table->string('status', 16)->default('pending');   // pending|verified|failed
            $table->timestamp('last_checked_at')->nullable();

            $table->timestamps();

            $table->index('name');
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_domains');
    }
};
