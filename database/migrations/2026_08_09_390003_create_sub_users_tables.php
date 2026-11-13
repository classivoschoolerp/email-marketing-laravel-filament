<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-0.2-004 / §2.15.4 team members — sub-users under a customer (WP-39).
 *
 * A sub-user is a LOGIN, not a tenant: every row hangs off `customer_id`
 * and the customer guard resolves a sub-user's session to its parent
 * Customer (see App\Auth\CustomerUserProvider), so the D-05 global scope
 * keeps doing all the tenancy work with no changes. That is deliberate —
 * a second tenancy mechanism would be a second thing to get wrong.
 *
 * - sub_users              the login + invitation state machine
 * - sub_user_permissions   granular per-user grants/denies layered over the
 *                          assigned per-tenant Role (F-2.15.4-002)
 * - sub_user_list_access   F-2.15.4-003 "restrict to specific lists /
 *                          campaigns". A sub-user with NO rows for a
 *                          resource type sees everything of that type;
 *                          one row or more narrows them to exactly those.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sub_users', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // Per-tenant role (roles.scope = customer, roles.customer_id set).
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();

            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->unique();

            // Null until the invitation is accepted and a password is set.
            $table->string('password')->nullable();

            // pending | active | suspended | revoked
            $table->string('status', 16)->default('pending')->index();

            $table->string('timezone', 64)->nullable();
            $table->string('locale', 12)->nullable();

            // F-2.15.4-004 invitation lifecycle.
            $table->string('invitation_token', 64)->nullable()->unique();
            $table->timestamp('invitation_sent_at')->nullable();
            $table->timestamp('invitation_expires_at')->nullable();
            $table->unsignedInteger('invitation_sent_count')->default(0);
            $table->timestamp('accepted_at')->nullable();

            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'status']);
        });

        Schema::create('sub_user_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sub_user_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 64);
            // true = explicit grant, false = explicit deny (wins over the role).
            $table->boolean('granted')->default(true);
            $table->timestamps();

            $table->unique(['sub_user_id', 'permission']);
        });

        Schema::create('sub_user_list_access', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sub_user_id')->constrained()->cascadeOnDelete();
            // 'list' | 'campaign'
            $table->string('resource_type', 16);
            $table->unsignedBigInteger('resource_id');
            $table->timestamps();

            $table->unique(['sub_user_id', 'resource_type', 'resource_id'], 'sub_user_access_unique');
            $table->index(['sub_user_id', 'resource_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sub_user_list_access');
        Schema::dropIfExists('sub_user_permissions');
        Schema::dropIfExists('sub_users');
    }
};
