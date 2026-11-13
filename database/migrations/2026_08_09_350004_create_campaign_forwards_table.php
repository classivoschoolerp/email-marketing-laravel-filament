<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forward-to-a-friend tracking (spec §1.6 F-1.6-002 + §1.7 F-1.7-004, WP-35).
 *
 * One row per (forwarder, recipient) pair created by the public forward form
 * at /campaigns/{campaign_uid}/track-forward/{subscriber_uid}. The shape
 * follows the campaign_track_* convention exactly: campaign_uid +
 * subscriber_uid are the real keys (uid = access secret, no tenancy scope),
 * `campaign_id` is a plain nullable indexed column, and the row is
 * insert-only apart from the delivery outcome.
 *
 * `ip` is written through App\Support\Privacy\IpAnonymizer like every other
 * §1.7 tracking row, so the privacy toggle covers this endpoint too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_forwards', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('campaign_id')->nullable()->index();
            $table->string('campaign_uid', 36)->index();
            $table->string('subscriber_uid', 36)->nullable()->index();
            $table->string('recipient_email', 191);

            // pending | sent | failed
            $table->string('status', 16)->default('pending');

            $table->string('personal_note', 500)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['campaign_uid', 'subscriber_uid']);
            $table->index(['campaign_uid', 'recipient_email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_forwards');
    }
};
