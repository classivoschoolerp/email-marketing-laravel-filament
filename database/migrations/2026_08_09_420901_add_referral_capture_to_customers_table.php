<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referral code capture (spec §1.2 F-1.2-010).
 *
 * Two columns, and they mean different things on purpose:
 *
 *  - `referral_code` is the raw string the visitor arrived with, stored
 *    VERBATIM. A code nobody recognises is still worth keeping: it is how an
 *    operator finds out that a partner is handing out a code that was typed
 *    wrong, retired, or never issued. Dropping it would erase the evidence.
 *  - `referred_by_customer_id` is only filled when that raw code resolves to
 *    a real account, so "captured" and "attributed" never get confused.
 *
 * nullOnDelete: removing a referrer must never remove the accounts they
 * introduced, and the raw code survives on the row either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('referral_code', 64)->nullable()->after('domain_id')->index();
            $table->foreignId('referred_by_customer_id')->nullable()->after('referral_code')
                ->constrained('customers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('referred_by_customer_id');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropIndex(['referral_code']);
            $table->dropColumn('referral_code');
        });
    }
};
