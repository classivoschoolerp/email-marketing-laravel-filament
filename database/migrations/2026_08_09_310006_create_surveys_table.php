<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-31 — surveys (spec §2.8) and their public pages (§1.9).
 *
 * `display_name` is what the public page shows; `name` is the internal label
 * (F-2.8-002). `start_at`/`end_at`/`max_responders` are the two independent
 * close conditions of F-1.9-004 — whichever trips first closes the survey,
 * and `status` records a manual close.
 *
 * `list_id` is optional: an anonymous-only survey needs no list, while an
 * identified survey uses it to resolve `?s={subscriber_uid}` and to write
 * mapped answers back onto the subscriber (which is what makes F-2.8-007
 * "segment from answers" work through the existing SegmentQueryBuilder).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surveys', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('list_id')->nullable()->constrained('lists')->nullOnDelete();

            $table->string('name', 191);
            $table->string('display_name', 191)->nullable();
            $table->text('description')->nullable();

            // draft|active|paused|closed (F-2.8-002)
            $table->string('status', 16)->default('draft');
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            // 0 / NULL = unlimited (D-23)
            $table->unsignedInteger('max_responders')->nullable();

            // ---- public page customisation (F-2.8-004 / §1.9) ------------
            $table->text('start_page_content')->nullable();
            $table->text('thank_you_content')->nullable();
            $table->text('closed_message')->nullable();
            $table->string('meta_title', 191)->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->string('redirect_url', 2048)->nullable();
            $table->boolean('show_progress_bar')->default(true);
            $table->unsignedSmallInteger('fields_per_step')->default(0); // 0 = single page

            // ---- response model (F-2.8-009) ------------------------------
            $table->boolean('allow_anonymous')->default(true);
            $table->boolean('one_response_per_subscriber')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surveys');
    }
};
