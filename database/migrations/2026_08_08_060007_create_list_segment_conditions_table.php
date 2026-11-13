<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-07 — segment condition rows (spec §2.2.10). type ∈ field|date|status|
 * campaign. Field conditions target either a custom field (list_field_id) or
 * a standard column (field_key = email). Date conditions use field_key
 * subscribed|last_activity. Campaign conditions keep their parameters in
 * `settings` (campaign_id, url, within_days) and match zero subscribers until
 * the campaigns work package lands (documented in SegmentQueryBuilder).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('list_segment_conditions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('list_segment_id')->constrained('list_segments')->cascadeOnDelete();
            $table->string('type', 16)->default('field');
            $table->foreignId('list_field_id')->nullable()->constrained('list_fields')->nullOnDelete();
            $table->string('field_key', 32)->nullable();
            $table->string('operator', 24);
            $table->text('value')->nullable();
            $table->text('value2')->nullable();
            $table->json('settings')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['list_segment_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('list_segment_conditions');
    }
};
