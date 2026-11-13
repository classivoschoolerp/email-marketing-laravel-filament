<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Articles / blog posts (spec §3.24 F-3.24-001, §1.11 F-1.11-001).
 *
 * Operator-authored marketing content: install-wide, `domain_id` fences it to
 * one brand exactly as it does for categories.
 *
 * Publication is a two-part decision — `status` says the author is finished,
 * `published_at` says when it may be read. A `published` row dated in the
 * future is a scheduled post and 404s until its moment arrives, so the URL of
 * a leaked draft leaks nothing (same rule as WP-34 landing pages).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();

            $table->foreignId('domain_id')->nullable()->constrained('domains')->cascadeOnDelete();
            $table->foreignId('article_category_id')->nullable()
                ->constrained('article_categories')->nullOnDelete();
            // Author is an admin user; keep the article when the account goes.
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title');
            $table->string('slug', 191);
            $table->text('excerpt')->nullable();
            $table->longText('content')->nullable();

            // Path on the `public` disk (D-26 asset convention).
            $table->string('featured_image_path', 2048)->nullable();

            // SEO (F-3.24-001)
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('meta_keywords')->nullable();
            $table->string('canonical_url', 2048)->nullable();
            $table->boolean('no_index')->default(false);

            // draft | published | archived
            $table->string('status', 16)->default('draft')->index();
            $table->timestamp('published_at')->nullable()->index();

            $table->unsignedBigInteger('views')->default(0);
            $table->boolean('featured')->default(false)->index();

            $table->timestamps();

            $table->unique(['domain_id', 'slug']);
            $table->index(['status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
