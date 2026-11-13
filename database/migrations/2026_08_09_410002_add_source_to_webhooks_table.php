<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WP-41 — mark who created a webhook endpoint (spec §6.8 F-6.8-001).
 *
 * A Zapier REST-hook subscription IS an outgoing webhook: same signed POST,
 * same retry policy, same delivery log, same tenant scope. Rather than
 * building a second, weaker delivery pipeline beside {@see
 * \App\Services\Webhooks\WebhookDispatcher} (WP-32), Zapier subscribes by
 * creating a `webhooks` row and unsubscribes by deleting it.
 *
 * That needs exactly one thing the table did not have: provenance. `source`
 * separates rows a human created in the customer panel from rows an
 * integration created over the API, so:
 *
 *   - `DELETE /zapier/subscriptions/{uid}` can only ever remove a row Zapier
 *     itself created — a leaked Zapier token cannot delete a customer's
 *     hand-made webhooks;
 *   - the subscription cap counts integration rows only;
 *   - the customer panel can label where a row came from.
 *
 * `source_ref` carries the integration's own identifier (the Zap id, the
 * WordPress site URL) when the caller supplies one — diagnostics only, never
 * trusted for authorisation.
 *
 * NULL source = created by a human in the panel (every pre-existing row).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhooks', function (Blueprint $table): void {
            $table->string('source', 32)->nullable()->after('events');
            $table->string('source_ref', 191)->nullable()->after('source');

            $table->index(['customer_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::table('webhooks', function (Blueprint $table): void {
            $table->dropIndex(['customer_id', 'source']);
            $table->dropColumn(['source', 'source_ref']);
        });
    }
};
