<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign attachments (spec §2.3.4 F-2.3.4-015). Files live on the public
 * disk under customers/{uid}/campaigns/{uid}/attachments; size/count/type
 * limits are enforced at upload from CampaignSettings (§3.25.3) and the
 * group matrix (campaigns.attachments / campaigns.attachment_type.{ext}).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->string('file_name');
            $table->string('path', 2048);
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_attachments');
    }
};
