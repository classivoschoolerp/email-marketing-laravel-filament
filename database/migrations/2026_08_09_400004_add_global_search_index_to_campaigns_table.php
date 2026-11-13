<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-40 — §4.4 F-4.4-002 "scoped search with fast indexes".
 *
 * The WP-26 `add_performance_indexes` migration already covers three of the
 * four global-search paths; this closes the only real gap it left. EXPLAIN
 * QUERY PLAN on SQLite (the D-03 dev/test driver), against
 * PanelGlobalSearchProvider's actual queries:
 *
 *   subscribers  where customer_id = ? and deleted_at is null
 *                and email like 'term%' order by email limit 10
 *     → SEARCH subscribers USING INDEX subscribers_customer_email_idx
 *       (customer_id=?)                                          no temp b-tree
 *
 *   subscribers  where customer_id = ? and list_id = ? and status = ?
 *                and email like 'term%'
 *     → SEARCH subscribers USING INDEX subscribers_tenant_list_status_email
 *       (customer_id=? AND list_id=? AND status=?)               the §4.6 index
 *
 *   lists        → SEARCH lists USING INDEX lists_customer_id_name_index
 *   templates    → SEARCH email_templates USING INDEX
 *                  email_templates_customer_id_name_index
 *
 *   campaigns    where customer_id = ? and deleted_at is null
 *                and (name like ? or subject like ?) order by id desc limit 10
 *     BEFORE → SEARCH campaigns USING INDEX campaigns_customer_id_type_index
 *              (customer_id=?)
 *              USE TEMP B-TREE FOR ORDER BY          ← sorts EVERY campaign the
 *                                                       tenant owns, per keystroke
 *     AFTER  → SEARCH campaigns USING INDEX campaigns_customer_recent_idx
 *              (customer_id=?)                       ← ordering served by the index
 *
 * (customer_id, id) is the narrowest index that satisfies both the tenant
 * fence and the newest-first order. It is a plain secondary index, so the
 * MySQL monthly partitioning documented in D-03 is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->index(['customer_id', 'id'], 'campaigns_customer_recent_idx');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropIndex('campaigns_customer_recent_idx');
        });
    }
};
