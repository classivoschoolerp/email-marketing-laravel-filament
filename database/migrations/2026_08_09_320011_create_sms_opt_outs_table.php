<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-32 — SMS consent register (spec §2.10 F-2.10-005).
 *
 * One row per (customer, E.164 number) recording the CURRENT state plus how
 * it got there. STOP-family keywords arriving on the inbound gateway webhook
 * write `opted_out`; START/UNSTOP write `opted_in` again. The register is the
 * single authority consulted before every send — campaign, automation and
 * transactional alike — so an opt-out can never be bypassed by choosing a
 * different send path.
 *
 * Numbers are stored normalised (digits with a leading +) so "+1 555 0100",
 * "+15550100" and "0015550100" collapse to one row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_opt_outs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('phone', 32);
            $table->string('status', 16)->default('opted_out'); // opted_in|opted_out
            $table->string('source', 32)->default('inbound'); // inbound|manual|api|import
            $table->string('keyword', 32)->nullable();
            $table->unsignedBigInteger('subscriber_id')->nullable();
            $table->timestamp('opted_in_at')->nullable();
            $table->timestamp('opted_out_at')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'phone']);
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_opt_outs');
    }
};
