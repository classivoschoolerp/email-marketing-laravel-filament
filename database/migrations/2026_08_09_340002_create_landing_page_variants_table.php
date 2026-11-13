<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-34 — landing-page A/B variants (spec §2.6 F-2.6-004, §1.8 F-1.8-002).
 *
 * A variant carries its own builder content; the parent page's content is the
 * implicit control when no variant is marked as such. `weight` is a raw
 * traffic share (not a percentage that must sum to 100): the assigner picks
 * from the enabled variants proportionally to their weights, so disabling one
 * mid-test does not force the operator to re-balance the others.
 *
 * A winner (F-2.6-004) is recorded on the row rather than by deleting the
 * losers, so the comparison stays auditable after the test ends.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_page_variants', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('landing_page_id')->constrained('landing_pages')->cascadeOnDelete();

            $table->string('name', 191);
            $table->unsignedInteger('weight')->default(50);

            $table->longText('content_html')->nullable();
            $table->longText('content_json')->nullable();

            $table->boolean('is_control')->default(false);
            $table->boolean('is_winner')->default(false);
            $table->boolean('enabled')->default(true);

            $table->timestamps();

            $table->index(['landing_page_id', 'enabled']);
            $table->index(['customer_id', 'landing_page_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_page_variants');
    }
};
