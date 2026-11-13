<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WP-15 follow-up on delivery_servers:
 *  - `priority` (F-3.13.1-004 global rotation weight & priority — weight is
 *    the existing `probability` column; lower priority value = preferred).
 *  - foreign keys for bounce_server_id / tracking_domain_id /
 *    sending_domain_id now that the referenced tables exist (the WP-14
 *    migration left them as plain columns on purpose). SQLite cannot add
 *    constraints to an existing table, so the FK block is MySQL/Postgres
 *    only — dev/test integrity is covered by application-level selects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_servers', function (Blueprint $table): void {
            $table->unsignedInteger('priority')->default(0)->after('probability');
        });

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('delivery_servers', function (Blueprint $table): void {
                $table->foreign('bounce_server_id')->references('id')->on('bounce_servers')->nullOnDelete();
                $table->foreign('tracking_domain_id')->references('id')->on('tracking_domains')->nullOnDelete();
                $table->foreign('sending_domain_id')->references('id')->on('sending_domains')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('delivery_servers', function (Blueprint $table): void {
                $table->dropForeign(['bounce_server_id']);
                $table->dropForeign(['tracking_domain_id']);
                $table->dropForeign(['sending_domain_id']);
            });
        }

        Schema::table('delivery_servers', function (Blueprint $table): void {
            $table->dropColumn('priority');
        });
    }
};
