<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additional target lists (spec §2.3.2 F-2.3.2-006 "target multiple lists").
 *
 * `campaigns.email_list_id` stays the PRIMARY list and is untouched — every
 * existing consumer (merge tags, the CAN-SPAM footer, list-scoped segments,
 * suppression, the report page, the API) resolves the campaign's list through
 * it, and repointing that column at a pivot would have meant rewriting the
 * whole P1 surface. This table holds the EXTRA lists; CampaignRecipients
 * unions them in and de-duplicates by email so a subscriber on two targeted
 * lists is mailed once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_target_lists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('email_list_id')->constrained('lists')->cascadeOnDelete();

            $table->unique(['campaign_id', 'email_list_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_target_lists');
    }
};
