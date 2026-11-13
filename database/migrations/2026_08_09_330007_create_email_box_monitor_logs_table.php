<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monitor processing log (spec §2.9.6 F-2.9.6-005): one row per message the
 * monitor examined, matched or not, with the condition that fired, the action
 * taken and the subscriber it touched. `matched` = false rows are what make
 * "why did nothing happen?" answerable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_box_monitor_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_box_monitor_id')->constrained('email_box_monitors')->cascadeOnDelete();
            $table->foreignId('email_box_monitor_condition_id')->nullable()
                ->constrained('email_box_monitor_conditions')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('subscriber_id')->nullable()->constrained('subscribers')->nullOnDelete();

            $table->string('from_email')->nullable();
            $table->string('subject', 512)->nullable();
            $table->boolean('matched')->default(false);
            $table->string('action', 32)->nullable();
            $table->text('result')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['email_box_monitor_id', 'created_at']);
            $table->index('matched');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_box_monitor_logs');
    }
};
