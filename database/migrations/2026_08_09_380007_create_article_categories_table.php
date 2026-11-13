<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Article categories (spec §3.24 F-3.24-001, §1.11 F-1.11-001).
 *
 * Operator-authored marketing content — install-wide, never tenant-scoped.
 *
 * `domain_id` is the multi-domain fence (§6.4): NULL = shown on every brand,
 * a set value = shown ONLY on that brand's host. The slug is unique per
 * domain, not globally, so two brands may both have /blog/category/news.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_categories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();

            $table->foreignId('domain_id')->nullable()->constrained('domains')->cascadeOnDelete();

            $table->string('name');
            $table->string('slug', 191);
            $table->text('description')->nullable();

            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();

            $table->unsignedInteger('sort_order')->default(100);
            $table->boolean('active')->default(true)->index();

            $table->timestamps();

            // Per-brand uniqueness. SQLite and MySQL both treat NULLs as
            // distinct here, so app-host rows (domain_id NULL) are guarded in
            // App\Models\ArticleCategory::booted() instead.
            $table->unique(['domain_id', 'slug']);
            $table->index(['active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_categories');
    }
};
