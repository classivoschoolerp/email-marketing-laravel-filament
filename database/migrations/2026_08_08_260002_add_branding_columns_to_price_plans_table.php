<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.26.6 F-3.26.6-006 — per-tenant white-label branding tied to the plan
 * (WP-26).
 *
 * The plan carries the brand; a customer inherits it for as long as they
 * hold a current subscription to that plan (PlanBranding). Nothing is
 * copied onto the customer, so an operator editing the plan re-brands every
 * subscriber of it instantly and a lapsed subscription silently falls back
 * to the operator's own skin.
 *
 * - white_label:        master switch — off means "this plan grants no brand"
 * - brand_logo_path:    path on the `public` disk (customers/... asset
 *                       isolation, D-26) or an absolute URL
 * - brand_primary_color / brand_accent_color: hex colours, validated in the
 *                       admin form; rendered as CSS custom properties
 * - brand_from_name:    the display name the tenant's transactional email
 *                       is branded with
 * - brand_domain:       the vanity domain the operator has pointed at the
 *                       app for this plan's tenants; recorded + displayed
 *                       (DNS/vhost provisioning is infrastructure, not code)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_plans', function (Blueprint $table): void {
            $table->boolean('white_label')->default(false)->after('features');
            $table->string('brand_logo_path', 2048)->nullable()->after('white_label');
            $table->string('brand_primary_color', 32)->nullable()->after('brand_logo_path');
            $table->string('brand_accent_color', 32)->nullable()->after('brand_primary_color');
            $table->string('brand_from_name')->nullable()->after('brand_accent_color');
            $table->string('brand_domain')->nullable()->after('brand_from_name');
        });
    }

    public function down(): void
    {
        Schema::table('price_plans', function (Blueprint $table): void {
            $table->dropColumn([
                'white_label', 'brand_logo_path', 'brand_primary_color',
                'brand_accent_color', 'brand_from_name', 'brand_domain',
            ]);
        });
    }
};
