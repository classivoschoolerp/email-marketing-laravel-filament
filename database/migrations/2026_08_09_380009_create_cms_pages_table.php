<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Static CMS pages (spec §3.24 F-3.24-002, §1.11 F-1.11-002).
 *
 * Terms, Privacy, Cookie Policy and any custom slug, served at /pages/{slug}
 * with a full HTML body the operator authors.
 *
 * `system_key` marks the three pages the application itself links to
 * (terms/privacy/cookie). Those rows may be edited freely but not deleted —
 * an unsubscribe footer or a registration form pointing at a 404 is worse
 * than a stale page. Enforced by CmsPagePolicy, not by the schema.
 *
 * `domain_id` is the §6.4 brand fence, identical to articles: NULL = every
 * brand, set = that brand's host only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_pages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();

            $table->foreignId('domain_id')->nullable()->constrained('domains')->cascadeOnDelete();

            $table->string('title');
            $table->string('slug', 191);
            $table->longText('content')->nullable();

            // terms | privacy | cookie — NULL for operator-created pages.
            $table->string('system_key', 32)->nullable()->index();

            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('meta_keywords')->nullable();
            $table->boolean('no_index')->default(false);

            // draft | published
            $table->string('status', 16)->default('draft')->index();

            // Public-site navigation placement (the §3.24-003 menu builder is
            // P3; these two columns are the flat footer list it would replace).
            $table->boolean('show_in_footer')->default(false);
            $table->unsignedInteger('sort_order')->default(100);

            $table->timestamps();

            $table->unique(['domain_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_pages');
    }
};
