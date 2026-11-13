<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email templates (spec §2.5, §3.14). Named email_templates to avoid colliding
 * with Blade "template" naming. customer_id NULL = admin/system row; system
 * rows with is_gallery=true are visible read-only to customers (optionally
 * restricted to customer groups via gallery_customer_groups).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('template_categories')->nullOnDelete();
            $table->string('name');
            $table->string('builder', 32)->default('grapesjs');
            $table->longText('content_html')->nullable();
            $table->longText('content_json')->nullable();
            $table->string('thumbnail_path', 2048)->nullable();
            $table->boolean('thumbnail_uploaded')->default(false);
            $table->json('google_fonts')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_gallery')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->json('gallery_customer_groups')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('missing_unsubscribe_tag')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'name']);
            $table->index(['is_gallery', 'is_featured', 'sort_order']);
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
