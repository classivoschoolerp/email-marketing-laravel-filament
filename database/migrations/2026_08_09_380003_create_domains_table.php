<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-domain / multi-brand support (spec §6.4 F-6.4-001 … 004).
 *
 * One row = one brand served from this single installation. The request HOST
 * selects the row (App\Http\Middleware\ResolveBrand), and everything a brand
 * can override hangs off it: theme, logo, favicon, colours, from-addresses,
 * system-email overrides, terms/privacy pages and whether registration and
 * login are open on that host.
 *
 * Install-wide operator data: `customer_id` is the AGENCY hook (§6.3
 * F-6.3-004 per-tenant branding) — a domain may be handed to one tenant, but
 * only an operator creates the row, so the table is deliberately not under
 * the BelongsToCustomer global scope.
 *
 * SSL columns are OBSERVED state, never a promise: nothing in this package
 * issues, renews or installs a certificate. DomainSslInspector reads what the
 * host actually presents and records it; `ssl_status` is only ever a report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();

            // Bare host, lower-cased, no scheme and no port. Unique: two
            // brands can never claim the same host, which is what makes the
            // host fence (BrandResolver) unambiguous.
            $table->string('host', 255)->unique();

            $table->string('name');
            $table->boolean('active')->default(true)->index();

            // Exactly one row may be the fallback brand for hosts that match
            // nothing (enforced in App\Models\Domain, not by a partial index:
            // MySQL 8 and SQLite disagree on filtered unique indexes).
            $table->boolean('is_default')->default(false)->index();

            // §6.3 F-6.3-004: hand a brand to a single tenant (agency mode).
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            // ---- Brand (F-6.4-002) -------------------------------------
            $table->string('theme', 64)->nullable();
            $table->string('logo_path', 2048)->nullable();
            $table->string('favicon_path', 2048)->nullable();
            $table->string('login_background_path', 2048)->nullable();
            $table->string('primary_color', 32)->nullable();
            $table->string('secondary_color', 32)->nullable();

            // ---- From-addresses (F-6.4-002) ----------------------------
            $table->string('from_name')->nullable();
            $table->string('from_email')->nullable();
            $table->string('reply_to_email')->nullable();

            // Per-domain system-email subject/body overrides, keyed by
            // SystemEmailRenderer::KEYS. NULL/absent key = the install-wide
            // template, which is itself allowed to fall back to the code
            // default (D-69 layering, one level deeper).
            $table->json('email_templates')->nullable();

            // ---- Legal pages (F-6.4-002) -------------------------------
            // Slugs into `cms_pages`; resolved on the brand's own host only.
            $table->string('terms_page_slug', 191)->nullable();
            $table->string('privacy_page_slug', 191)->nullable();

            // ---- Per-domain auth (F-6.4-003) ---------------------------
            $table->boolean('registration_enabled')->default(true);
            $table->boolean('login_enabled')->default(true);
            // Customers who sign up on this host join this group; NULL falls
            // back to CustomerGroup::default() exactly as WP-02 does.
            $table->foreignId('customer_group_id')->nullable()->constrained('customer_groups')->nullOnDelete();

            // ---- Observed TLS state (F-6.4-004) ------------------------
            // unknown | none | active | expiring | expired | mismatch | error
            $table->string('ssl_status', 16)->default('unknown')->index();
            $table->string('ssl_issuer')->nullable();
            $table->timestamp('ssl_expires_at')->nullable();
            $table->timestamp('ssl_checked_at')->nullable();
            $table->text('ssl_error')->nullable();
            // Redirect http:// requests for this host to https:// — an
            // application-level switch, not certificate management.
            $table->boolean('force_https')->default(false);

            $table->timestamps();

            $table->index(['active', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
