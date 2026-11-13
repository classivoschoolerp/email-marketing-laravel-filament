<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-45 — sync bookkeeping (spec §2.12 F-2.12-002).
 *
 * One row per (connection, resource) sync wave. It exists so a customer can
 * see WHY a catalogue looks stale: the row carries the page count, the record
 * count, the cursor it advanced to and the vendor's error text if one came
 * back. Without it "sync failed" would be an unexplainable toast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('store_connection_id')->constrained('store_connections')->cascadeOnDelete();

            $table->string('resource', 16);              // customers|products|orders|carts
            $table->string('status', 16)->default('running'); // running|completed|failed
            $table->unsignedInteger('pages')->default(0);
            $table->unsignedInteger('records')->default(0);
            $table->unsignedInteger('created_records')->default(0);
            $table->unsignedInteger('updated_records')->default(0);
            $table->string('cursor', 191)->nullable();
            $table->string('error', 500)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['store_connection_id', 'resource', 'created_at'], 'store_sync_runs_resource_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_sync_runs');
    }
};
