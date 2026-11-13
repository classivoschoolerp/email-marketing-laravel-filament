<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-46 — in-app / on-site messages (spec §2.10 F-2.10-011).
 *
 * A message the customer's own website shows to a visitor: a banner, a modal,
 * a slide-in or a tooltip. Delivery is the on-site loader script, so every
 * display RULE has to be storable and answerable server-side — the browser is
 * never trusted to decide whether someone has already seen something.
 *
 * The rules split into three kinds:
 *   WHERE   `url_pattern` — a glob matched against the visitor's path
 *   WHEN    `starts_at` / `ends_at`, both UTC like every other timestamp here
 *   HOW OFTEN `frequency` (once | session | always) + `max_impressions`
 *
 * Targeting reuses list + segment, so an on-site message can be aimed at the
 * same audience an email campaign is — the segment engine is channel-agnostic
 * (SegmentQueryBuilder), and a message with neither is shown to anonymous
 * visitors too, which is the common case for a site-wide banner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('in_app_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('uid', 36)->unique();
            $table->string('name');
            $table->string('type', 16)->default('banner'); // banner|modal|slide_in|tooltip
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->string('cta_label', 120)->nullable();
            $table->string('cta_url', 2048)->nullable();
            $table->string('placement', 24)->default('bottom_right');
            $table->string('theme', 24)->default('light');
            $table->string('url_pattern', 500)->nullable();
            $table->string('selector', 200)->nullable(); // tooltip anchor
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('frequency', 16)->default('once'); // once|session|always
            $table->unsignedInteger('max_impressions')->default(0); // 0 = unlimited (D-23)
            $table->boolean('dismissible')->default(true);
            $table->unsignedInteger('delay_seconds')->default(0);
            $table->unsignedBigInteger('list_id')->nullable();
            $table->unsignedBigInteger('segment_id')->nullable();
            $table->string('status', 16)->default('draft'); // draft|active|paused|archived
            $table->unsignedInteger('priority')->default(0);
            $table->unsignedInteger('impressions_count')->default(0);
            $table->unsignedInteger('clicks_count')->default(0);
            $table->unsignedInteger('dismissals_count')->default(0);
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['customer_id', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('in_app_messages');
    }
};
