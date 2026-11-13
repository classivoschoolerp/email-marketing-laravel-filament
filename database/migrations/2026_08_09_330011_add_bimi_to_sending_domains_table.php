<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BIMI state for the §2.9.9 domain-authentication summary (F-2.9.9-002).
 *
 * WP-15's SendingDomainVerifier owns SPF/DKIM/DMARC and gates the domain's
 * verified badge on config emp-infra.domains.required_records; BIMI is an
 * optional, purely informational record, so it is kept OUT of that gate and
 * refreshed by the deliverability centre's DomainAuthSummary instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sending_domains', function (Blueprint $table): void {
            $table->boolean('bimi_verified')->default(false)->after('mx_verified');
            $table->timestamp('bimi_checked_at')->nullable()->after('bimi_verified');
        });
    }

    public function down(): void
    {
        Schema::table('sending_domains', function (Blueprint $table): void {
            $table->dropColumn(['bimi_verified', 'bimi_checked_at']);
        });
    }
};
