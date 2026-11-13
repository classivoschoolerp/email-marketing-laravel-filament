<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free-form campaign tags (spec §2.3.2 F-2.3.2-004 / §2.3.10 F-2.3.10-002).
 *
 * A real tenant-scoped table rather than a JSON column (the templates
 * precedent) because §2.3.10-002 asks for AUTOCOMPLETE and §2.3.1-004 for a
 * tag FILTER — both need "every tag this customer has used" as an indexed
 * query, which a JSON column cannot answer without a full scan.
 *
 * `slug` is the case-folded uniqueness key so "Newsletter" and "newsletter"
 * are one tag per tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('slug', 64);
            $table->timestamps();

            $table->unique(['customer_id', 'slug']);
        });

        Schema::create('campaign_campaign_tag', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('campaign_tag_id')->constrained('campaign_tags')->cascadeOnDelete();

            $table->unique(['campaign_id', 'campaign_tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_campaign_tag');
        Schema::dropIfExists('campaign_tags');
    }
};
