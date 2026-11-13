<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Default delivery server assignment per customer group (spec §3.5
 * F-3.5-016). Stored as a JSON list of delivery-server ids; the servers
 * themselves arrive with WP-14/15, so until then this is a forward-compatible
 * placeholder edited as raw ids in the group form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_groups', function (Blueprint $table): void {
            $table->json('default_delivery_servers')->nullable()->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('customer_groups', function (Blueprint $table): void {
            $table->dropColumn('default_delivery_servers');
        });
    }
};
