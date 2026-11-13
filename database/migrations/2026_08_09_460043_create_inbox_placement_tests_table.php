<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbox placement runs and their per-seed results (spec §2.9.9 F-2.9.9-004,
 * WP-46).
 *
 * `token` is the unique run marker stamped into an X-EMP-Placement-Token
 * header on every seed message (D-65's X-EMP-* precedent). Matching on that
 * token — not on subject or sender — is what makes a run identifiable when
 * two tests are in flight against the same mailbox.
 *
 * `auth_results` stores SPF/DKIM/DMARC AS THE RECEIVING SERVER REPORTED THEM
 * (parsed from the delivered message's Authentication-Results header). That is
 * a genuinely different fact from the platform's own DNS checks: it is the
 * receiver's verdict on real mail, not our verdict on a DNS record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_placement_tests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('seed_list_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();

            $table->string('uid', 40)->unique();
            $table->string('token', 64)->unique();

            $table->string('name', 191);
            $table->string('subject', 512);
            $table->string('from_email', 320)->nullable();
            $table->string('from_name', 191)->nullable();
            $table->longText('content_html')->nullable();

            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('seeded_count')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('timeout_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index('status');
        });

        Schema::create('inbox_placement_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inbox_placement_test_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seed_address_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('email', 320);
            $table->string('provider', 16)->default('other');
            $table->string('placement', 16)->default('pending');
            $table->string('folder', 191)->nullable();
            $table->timestamp('received_at')->nullable();

            // Parsed Authentication-Results (spf/dkim/dmarc verdicts + raw).
            $table->json('auth_results')->nullable();
            $table->longText('headers')->nullable();
            $table->string('error', 512)->nullable();

            $table->timestamps();

            $table->unique(['inbox_placement_test_id', 'email']);
            $table->index(['customer_id', 'placement']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_placement_results');
        Schema::dropIfExists('inbox_placement_tests');
    }
};
