<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F-2.9.2-038 "Assign to specific lists/campaigns only" (WP-42).
 *
 * A delivery server may be narrowed to an explicit set of the owning tenant's
 * lists and/or campaigns. The convention is the one already used by
 * sub_user_list_access (§2.15.4 F-2.15.4-003): NO rows for a server means NO
 * restriction; one row or more narrows it to exactly those resources.
 *
 * `resource_type` is the same 'list' | 'campaign' discriminator, and the ids
 * are plain unsigned bigints (no FK — one column serves two tables, exactly
 * like sub_user_list_access). Tenancy is enforced in
 * App\Services\Sending\DeliveryServerAssignments::sync(), which refuses any id
 * that is not owned by the server's own customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_server_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_server_id')->constrained('delivery_servers')->cascadeOnDelete();
            // 'list' | 'campaign'
            $table->string('resource_type', 16);
            $table->unsignedBigInteger('resource_id');
            $table->timestamps();

            $table->unique(['delivery_server_id', 'resource_type', 'resource_id'], 'delivery_server_assignments_unique');
            $table->index(['delivery_server_id', 'resource_type'], 'delivery_server_assignments_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_server_assignments');
    }
};
