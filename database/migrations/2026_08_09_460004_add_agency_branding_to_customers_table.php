<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-46 — per-customer branding, agency mode (spec §4.7 F-4.7-004).
 *
 * WP-38 shipped branding at two levels the CUSTOMER does not control: the
 * operator's brand DOMAIN (§6.4) and the plan's white-label block (§3.26.6,
 * D-82). This adds the third level the row asks for: an agency customer
 * branding its OWN panel — what its team members (§0.2 F-0.2-004, D-109) see
 * when they sign in, and what the system emails those members receive look
 * like.
 *
 * Precedence is deliberate and documented in BrandResolver: the operator's
 * brand domain still WINS. An operator who assigned a brand host has made a
 * decision about that host, and a tenant must not be able to repaint it.
 * Below that, the customer's own branding beats the plan's — the plan grants
 * the capability, the customer exercises it.
 *
 * `brand_enabled` exists (unlike the D-23 "empty = off" convention used for
 * the IP allowlist) because an agency needs to switch its branding off
 * without losing the assets it uploaded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->boolean('brand_enabled')->default(false);
            $table->string('brand_product_name', 191)->nullable();
            $table->string('brand_logo_path', 2048)->nullable();
            $table->string('brand_favicon_path', 2048)->nullable();
            // Literal hex only, validated at save AND at render (D-100).
            $table->string('brand_primary_color', 32)->nullable();
            $table->string('brand_secondary_color', 32)->nullable();
            $table->string('brand_from_name', 191)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn([
                'brand_enabled',
                'brand_product_name',
                'brand_logo_path',
                'brand_favicon_path',
                'brand_primary_color',
                'brand_secondary_color',
                'brand_from_name',
            ]);
        });
    }
};
