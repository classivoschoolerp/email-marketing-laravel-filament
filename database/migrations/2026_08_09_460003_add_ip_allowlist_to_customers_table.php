<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-46 — per-account IP allowlist (spec §2.15.2 F-2.15.2-004).
 *
 * This is the CUSTOMER's own control over their account, and is deliberately
 * separate from `SecuritySettings::admin_allowed_ips` (§3.25.15 F-3.25.15-005),
 * which is the operator restricting the ADMIN panel. Different owner,
 * different subject, different lock-out blast radius.
 *
 * An empty list means unrestricted — the D-23 "0 / empty = disabled"
 * convention — so every existing account is unaffected. There is no separate
 * `enabled` flag that could disagree with the list.
 *
 * Entries are single IPs or CIDR ranges, IPv4 and IPv6, matched by
 * {@see \App\Support\Api\IpMatcher} (the same matcher the API gate uses).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->json('ip_allowlist')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('ip_allowlist');
        });
    }
};
