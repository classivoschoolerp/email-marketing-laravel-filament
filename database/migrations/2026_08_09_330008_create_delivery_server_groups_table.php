<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery server groups / pools (spec §3.13.1 F-3.13.1-008, WP-33).
 *
 * A group bundles delivery servers so traffic can be confined to a subset of
 * the fleet: ServerPicker restricts its candidate list to the members of the
 * pool it is given, and — when a customer has exactly one enabled default
 * pool — to that pool automatically.
 *
 * `type` separates a plain sending pool from an IP pool (F-3.13.1-009): the
 * latter also owns delivery_server_ips rows describing the dedicated
 * addresses the member servers send from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_server_groups', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type', 16)->default('pool');  // pool | ip_pool
            $table->boolean('enabled')->default(true);
            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->index(['customer_id', 'enabled']);
        });

        Schema::create('delivery_server_group_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_server_group_id')->constrained('delivery_server_groups')->cascadeOnDelete();
            $table->foreignId('delivery_server_id')->constrained('delivery_servers')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['delivery_server_group_id', 'delivery_server_id'], 'dsg_members_unique');
            $table->index('delivery_server_id');
        });

        Schema::create('delivery_server_ips', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_server_group_id')->nullable()
                ->constrained('delivery_server_groups')->cascadeOnDelete();
            $table->foreignId('delivery_server_id')->nullable()
                ->constrained('delivery_servers')->nullOnDelete();

            $table->string('ip_address', 45);
            $table->string('ptr_record')->nullable();
            $table->boolean('dedicated')->default(true);
            $table->boolean('enabled')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('ip_address');
            $table->index('delivery_server_group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_server_ips');
        Schema::dropIfExists('delivery_server_group_members');
        Schema::dropIfExists('delivery_server_groups');
    }
};
