<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-34 — landing pages (spec §2.6 + §1.8).
 *
 * A landing page is a tenant-owned, publicly rendered document built with the
 * SAME GrapesJS machinery as §2.5.4 templates (D-11): `content_html` is the
 * rendered snapshot and `content_json` the undo-safe project state, exactly
 * as on `email_templates`, so the builder, the revision history and the
 * mobile preview are one implementation rather than three.
 *
 * `uid` is the private handle (builder endpoints, panel URLs); `slug` is the
 * PUBLIC key and is therefore globally unique — a landing page answers on the
 * shared app host as well as on an assigned custom domain, and two tenants
 * cannot both own `/p/pricing`. Cross-tenant safety on the public route comes
 * from the host check (a page only answers on ITS OWN assigned domain), not
 * from the slug.
 *
 * Custom domains reuse the §2.9.8 tracking-domain machinery
 * (`tracking_domains` + TrackingDomainVerifier + TrackingUrlBuilder) instead
 * of introducing a second domain table: the DNS record and the verification
 * are identical, and `tracking_domains.ssl_status` is surfaced as-is —
 * nothing in this module issues a certificate (F-2.9.8-005 stays P2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_pages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            $table->string('name', 191);
            // Public path segment: /p/{slug}. Globally unique — see the class docblock.
            $table->string('slug', 191)->unique();
            // draft|scheduled|published|unpublished (F-2.6-012)
            $table->string('status', 16)->default('draft');

            // ---- custom domain / subdomain (F-2.6-006) -------------------
            $table->foreignId('tracking_domain_id')->nullable()
                ->constrained('tracking_domains')->nullOnDelete();
            $table->string('subdomain', 63)->nullable();

            // ---- builder content (F-2.6-002) -----------------------------
            $table->longText('content_html')->nullable();
            $table->longText('content_json')->nullable();
            // Which gallery starter design seeded the page (F-2.6-003).
            $table->string('template_key', 64)->nullable();

            // ---- SEO (F-2.6-007) -----------------------------------------
            $table->string('seo_title', 191)->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->string('og_image_url', 2048)->nullable();
            $table->string('canonical_url', 2048)->nullable();
            $table->string('favicon_url', 2048)->nullable();
            // index,follow | noindex,follow | index,nofollow | noindex,nofollow
            $table->string('robots', 32)->default('index,follow');

            // ---- custom code + tracking scripts (F-2.6-010) --------------
            // Rendered inside <style>/<script>; closing-tag sequences are
            // stripped on the way out (F-1.5-017 contract, D-100).
            $table->text('custom_css')->nullable();
            $table->text('custom_js')->nullable();
            // Vendor ids only — never a snippet. Pattern-validated (D-100).
            $table->string('ga_measurement_id', 32)->nullable();
            $table->string('gtm_container_id', 32)->nullable();
            $table->string('fb_pixel_id', 32)->nullable();

            // ---- embedded form (F-2.6-009) -------------------------------
            $table->foreignId('form_id')->nullable()->constrained('forms')->nullOnDelete();

            // ---- after submit (F-2.6-011) --------------------------------
            // message|thank_you|redirect
            $table->string('after_submit', 16)->default('message');
            $table->text('success_message')->nullable();
            $table->string('redirect_url', 2048)->nullable();
            $table->longText('thank_you_html')->nullable();

            // ---- publishing (F-2.6-012) ----------------------------------
            $table->timestamp('published_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();

            // ---- A/B (F-2.6-004) -----------------------------------------
            $table->boolean('ab_enabled')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'status']);
            $table->index(['tracking_domain_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_pages');
    }
};
