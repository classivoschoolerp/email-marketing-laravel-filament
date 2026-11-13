<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-domain customer registration (spec §6.4 F-6.4-003).
 *
 * Records WHICH brand a customer signed up under, so their panel, their
 * system emails and their public pages keep that brand even when they later
 * reach the app on a different host. NULL = signed up on the app host
 * (every pre-existing account), which resolves to the default brand.
 *
 * Deliberately nullOnDelete: deleting a brand must never delete customers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->foreignId('domain_id')->nullable()->after('customer_group_id')
                ->constrained('domains')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('domain_id');
        });
    }
};
