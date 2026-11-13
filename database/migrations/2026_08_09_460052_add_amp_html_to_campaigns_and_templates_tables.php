<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMP for Email bodies (spec §2.5.4 F-2.5.4-014, WP-46).
 *
 * A SEPARATE column, never a flavour of `content_html`: an amp4email document
 * is a different document with its own required boilerplate, and a campaign
 * always keeps a plain HTML body because the AMP part is an alternative that
 * most clients will walk straight past.
 *
 * `longText` for the same reason `content_html` is: the AMP format's own limit
 * is 200 kB (AmpValidator::MAX_DOCUMENT_BYTES), which a MySQL `text` column
 * (65,535 bytes) could not hold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->longText('amp_html')->nullable()->after('content_json');
        });

        Schema::table('email_templates', function (Blueprint $table): void {
            $table->longText('amp_html')->nullable()->after('content_json');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn('amp_html');
        });

        Schema::table('email_templates', function (Blueprint $table): void {
            $table->dropColumn('amp_html');
        });
    }
};
