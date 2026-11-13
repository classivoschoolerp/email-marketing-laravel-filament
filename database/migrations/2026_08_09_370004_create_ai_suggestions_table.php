<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI automation suggestions (spec §2.15.8 F-2.15.8-017, WP-37).
 *
 * A suggestion is a PROPOSAL, never an automation. `payload` holds the
 * editable draft (trigger type + config + the node outline) and `evidence`
 * holds the counts the proposal was derived from — real numbers read from
 * the engagement tables, so an operator can audit why it was suggested.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->string('uid', 36)->unique();

            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // welcome | re_engagement | click_follow_up | open_no_click | birthday
            $table->string('kind', 48);

            $table->string('title', 190);
            $table->text('rationale')->nullable();

            // Canonical AutomationNodeType trigger value.
            $table->string('trigger_type', 48)->nullable();

            $table->json('payload')->nullable();
            $table->json('evidence')->nullable();

            // proposed | accepted | dismissed
            $table->string('status', 16)->default('proposed');

            $table->foreignId('automation_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['customer_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_suggestions');
    }
};
