<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI segment suggestions — "who should I send this to?"
 * (spec §2.15.8 F-2.15.8-007, WP-46).
 *
 * A suggestion is a PROPOSAL, never a segment, exactly like `ai_suggestions`
 * is a proposal and never an automation (F-2.15.8-017). Accepting one creates
 * a real ListSegment the customer owns and can edit; until then nothing exists
 * that could be targeted by a send.
 *
 * `conditions` holds ALREADY-VALIDATED condition arrays in the §2.2.10
 * vocabulary — every `type` is a SegmentConditionType, every `operator` is a
 * SegmentOperator legal for that type, and every referenced field, campaign
 * and automation was proved to belong to this tenant before the row was
 * written. Anything the model returned that failed those checks was dropped,
 * not stored (D-90's spirit: a broken condition must never reach a send).
 *
 * `matched_count` is a REAL count computed by SegmentQueryBuilder against the
 * tenant's own subscribers at analysis time — never a number the model
 * claimed. `counted_at` is what lets the UI say how fresh it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_segment_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->string('uid', 36)->unique();

            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // Suggestions are per-list: conditions reference that list's own
            // custom fields, so a suggestion cannot outlive its list.
            $table->foreignId('list_id')->constrained('lists')->cascadeOnDelete();

            $table->string('name', 190);
            $table->text('rationale')->nullable();

            // SegmentMatchType: all | any
            $table->string('match_type', 3)->default('all');

            $table->json('conditions')->nullable();

            // Computed, not claimed.
            $table->unsignedBigInteger('matched_count')->default(0);
            $table->timestamp('counted_at')->nullable();

            // proposed | accepted | dismissed
            $table->string('status', 16)->default('proposed');

            $table->foreignId('list_segment_id')->nullable()->constrained('list_segments')->nullOnDelete();

            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['customer_id', 'list_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_segment_suggestions');
    }
};
