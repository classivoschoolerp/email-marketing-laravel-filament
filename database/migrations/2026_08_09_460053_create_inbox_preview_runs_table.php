<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbox rendering preview runs (spec §2.3.5 F-2.3.5-006, WP-46).
 *
 * One row per submission to a rendering farm. Tenant-owned so a customer's
 * campaign HTML — and the screenshots of it — can never be read across
 * tenants (BelongsToCustomer + InboxPreviewRunPolicy + a cross-tenant test).
 *
 * `results` holds the per-client rows the vendor returned: client key, label,
 * OS, state and the vendor's own image URLs. The images are NOT copied into
 * local storage: they live on the vendor's CDN behind their own expiry, and
 * mirroring them would mean this build silently storing (and re-serving)
 * third-party assets whose licence and lifetime it does not control. A run
 * whose links have expired says so instead of showing a broken grid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_preview_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();

            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('email_template_id')->nullable()->constrained()->cascadeOnDelete();

            // Registry key of the adapter that ran it (litmus, email_on_acid…).
            $table->string('provider', 32);
            // The vendor's own test id, for polling.
            $table->string('remote_id', 191)->nullable();

            // queued | running | completed | failed
            $table->string('status', 16)->default('queued');

            $table->string('subject', 500)->nullable();
            $table->json('clients')->nullable();
            $table->json('results')->nullable();
            $table->text('error')->nullable();

            $table->unsignedSmallInteger('polls')->default(0);
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
            $table->index(['campaign_id', 'created_at']);
            $table->index(['status', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_preview_runs');
    }
};
