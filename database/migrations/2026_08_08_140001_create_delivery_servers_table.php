<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery servers (spec §2.9.2, WP-14). customer_id NULL = admin/system row
 * (same pattern as email_templates); system rows may be assigned to customer
 * groups via the customer_groups json column (F-2.9.2-037).
 *
 * bounce_server_id / tracking_domain_id / sending_domain_id / warmup_plan_id
 * are plain nullable columns WITHOUT foreign keys — the referenced tables are
 * built by WP-15 (§2.9.4/§2.9.7/§2.9.8) and WP-15 adds the constraints.
 *
 * password, api_key and dkim_private_key are encrypted at rest via the
 * model's `encrypted` casts (twofa_secret convention).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_servers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();

            // F-2.9.2-025 — identity + connection
            $table->string('type', 32);
            $table->string('name');
            $table->string('hostname')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('protocol', 8)->default('none'); // none|tcp|ssl|tls
            $table->string('username')->nullable();
            $table->text('password')->nullable();           // encrypted cast
            $table->text('api_key')->nullable();            // encrypted cast
            $table->string('region', 64)->nullable();

            // F-2.9.2-026/027 — envelope identity
            $table->string('from_email')->nullable();
            $table->string('from_name')->nullable();
            $table->string('reply_to_email')->nullable();
            $table->boolean('force_from')->default(false);
            $table->boolean('force_reply_to')->default(false);

            // F-2.9.2-028/029/030 — rotation weight + quota windows (0 = unlimited, D-23)
            $table->unsignedInteger('probability')->default(100);
            $table->unsignedInteger('hourly_quota')->default(0);
            $table->unsignedInteger('daily_quota')->default(0);
            $table->unsignedInteger('monthly_quota')->default(0);
            $table->string('quota_exceeded_action', 16)->default('pause'); // pause|next

            // F-2.9.2-031 — connection tuning (0 = unlimited/default)
            $table->unsignedInteger('max_connection_messages')->default(0);
            $table->unsignedInteger('connection_timeout')->default(30);
            $table->boolean('keep_alive')->default(true);

            // F-2.9.2-032 — extra headers stamped on every message
            $table->json('custom_headers')->nullable();

            // F-2.9.2-033/034/035 — associations; FKs land with WP-15's tables
            $table->unsignedBigInteger('bounce_server_id')->nullable();
            $table->unsignedBigInteger('tracking_domain_id')->nullable();
            $table->unsignedBigInteger('sending_domain_id')->nullable();

            // F-2.9.2-035/036 — DKIM signing
            $table->boolean('dkim_signing_enabled')->default(false);
            $table->text('dkim_private_key')->nullable();   // encrypted cast
            $table->string('dkim_selector', 64)->nullable();

            // F-2.9.2-037 — admin assignment of system rows to customer groups
            $table->json('customer_groups')->nullable();

            // F-2.9.2-039/040 — warmup + admin lock
            $table->unsignedBigInteger('warmup_plan_id')->nullable();
            $table->boolean('locked')->default(false);

            // F-2.9.2-041/042 — lifecycle + validation
            $table->string('status', 32)->default('inactive');
            $table->string('validation_token', 64)->nullable();
            $table->timestamp('validated_at')->nullable();

            // Use-for routing placeholder (§2.9.1 admin-side; P2 scoping F-2.9.2-038)
            $table->string('use_for', 32)->default('all');

            // Per-driver extra configuration (binary paths, pickup dir, OAuth
            // client id, VMTA name, API domain, ...). Secrets never live here —
            // they go to the encrypted password/api_key columns.
            $table->json('config')->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'name']);
            $table->index(['customer_id', 'status']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_servers');
    }
};
