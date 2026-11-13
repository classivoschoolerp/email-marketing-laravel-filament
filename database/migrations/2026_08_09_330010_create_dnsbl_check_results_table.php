<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IP / domain blacklist monitoring results (spec §2.9.9 F-2.9.9-003, WP-33).
 * One row per (target, zone) holding the latest DNSBL answer so the UI can
 * show a matrix and the alert service can detect a NEW listing rather than
 * re-alerting on a known one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dnsbl_check_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();

            $table->string('target', 255);        // 1.2.3.4 or mail.example.com
            $table->string('target_type', 8);     // ip | domain
            $table->string('zone', 128);          // zen.spamhaus.org, b.barracudacentral.org, ...
            $table->boolean('listed')->default(false);
            $table->string('response', 64)->nullable();  // returned A record, e.g. 127.0.0.2
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('listed_since')->nullable();

            $table->timestamps();

            $table->unique(['target', 'zone']);
            $table->index(['listed', 'target_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dnsbl_check_results');
    }
};
