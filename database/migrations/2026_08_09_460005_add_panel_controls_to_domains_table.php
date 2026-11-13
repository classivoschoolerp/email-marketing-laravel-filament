<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-46 — custom domain for the customer panel (spec §4.7 F-4.7-002).
 *
 * WP-38 gave a `domains` row branding, auth switches and an observed SSL
 * status, and `ResolveBrand` repaints whatever is served on that host. What
 * it did NOT do is decide WHO the customer PANEL admits on a brand host: the
 * panel is path-based (`/customer`) and answers on every host, so an agency's
 * own domain currently lets any tenant in the installation sign in on it.
 *
 * Two columns close that:
 *
 *  - `panel_enabled` — whether this host serves the customer panel at all.
 *    A pure marketing/blog brand host should not expose a login surface.
 *  - `panel_restricted` — whether the panel on this host admits ONLY the
 *    accounts that belong to this brand (`customers.domain_id`), plus the
 *    single tenant in `domains.customer_id` when that agency-mode assignment
 *    is set. Off by default, so every existing install behaves exactly as it
 *    did before this migration.
 *
 * The app host (no `domains` row) is never affected by either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->boolean('panel_enabled')->default(true)->after('login_enabled');
            $table->boolean('panel_restricted')->default(false)->after('panel_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table): void {
            $table->dropColumn(['panel_enabled', 'panel_restricted']);
        });
    }
};
