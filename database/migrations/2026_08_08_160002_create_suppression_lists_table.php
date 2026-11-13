<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-16 — named suppression lists (spec §2.9.11). Customer-scoped; attached
 * to campaigns via the existing campaigns.suppression_list_ids json column
 * (WP-11) and subtracted from the audience inside CampaignRecipients.
 * `emails_count` is a maintained counter (F-2.9.11-005) refreshed by the
 * SuppressionListEmails service after every mutation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppression_lists', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->unsignedInteger('emails_count')->default(0);

            $table->timestamps();

            $table->index(['customer_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppression_lists');
    }
};
