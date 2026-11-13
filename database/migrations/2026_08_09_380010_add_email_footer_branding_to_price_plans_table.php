<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom email-footer branding toggle per plan (spec §4.7 F-4.7-005).
 *
 * WP-26 already renders a branded footer for any customer on a white-label
 * plan (PlanBranding::emailFooterHtml, D-82). What was missing is the
 * OPERATOR'S switch: a plan that white-labels the panel does not necessarily
 * want the tenant's brand stamped on outgoing mail, and a plan that does want
 * it may want its own footer copy rather than the generated block.
 *
 * Two columns, both additive and both defaulting to the pre-existing
 * behaviour, so no plan changes meaning on upgrade:
 *  - brand_email_footer: NULL = "follow white_label" (exactly what
 *    PlanBranding did before); true/false = an explicit operator decision
 *    that overrides it in either direction.
 *  - brand_email_footer_html: operator-authored replacement markup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_plans', function (Blueprint $table): void {
            $table->boolean('brand_email_footer')->nullable()->after('brand_domain');
            $table->text('brand_email_footer_html')->nullable()->after('brand_email_footer');
        });
    }

    public function down(): void
    {
        Schema::table('price_plans', function (Blueprint $table): void {
            $table->dropColumn(['brand_email_footer', 'brand_email_footer_html']);
        });
    }
};
