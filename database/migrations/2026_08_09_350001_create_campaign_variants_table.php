<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A/B test variants (spec §2.3.6, WP-35).
 *
 * One row per variant (2–8, F-2.3.6-002). Every column mirrors a campaign
 * column that a test VARIABLE may override (F-2.3.6-003): subject, from name,
 * preheader, content/template and send time. A null column means "inherit the
 * campaign value" — so a subject-only test stores nothing but subjects and a
 * later variable change never silently freezes the other fields.
 *
 * `weight` is the traffic split inside the test group (F-2.3.6-012); the
 * apportionment is exact (largest remainder) and lives in AbTestSplitter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_variants', function (Blueprint $table): void {
            $table->id();
            $table->string('uid', 36)->unique();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->unsignedTinyInteger('position')->default(0);
            $table->string('label', 32);

            // Overridable content (null = inherit from the campaign).
            $table->string('subject', 500)->nullable();
            $table->string('from_name')->nullable();
            $table->string('preheader', 500)->nullable();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->longText('content_html')->nullable();
            $table->longText('content_json')->nullable();

            // Send-time variable (F-2.3.6-003): minutes to delay this variant
            // relative to the campaign's own send time. 0 = same moment.
            $table->unsignedInteger('send_delay_minutes')->default(0);

            // Traffic split inside the test group (F-2.3.6-012).
            $table->unsignedSmallInteger('weight')->default(1);

            // Snapshot of how many test-group recipients this variant claimed.
            $table->unsignedInteger('recipients')->default(0);

            $table->boolean('is_winner')->default(false);

            $table->timestamps();

            $table->unique(['campaign_id', 'label']);
            $table->index(['campaign_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_variants');
    }
};
