<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bounce classification rules (spec §2.9.4 F-2.9.4-006): regex patterns
 * matched (case-insensitively) against the DSN status/diagnostic/subject/body
 * haystack, evaluated in sort_order — first enabled match wins. Operators add
 * custom rules through the admin UI; the default set below ships with the
 * migration so a fresh install classifies real-world bounces out of the box.
 *
 * Patterns are stored WITHOUT delimiters (BounceClassifier wraps them).
 * Ordering convention: internal 10–19 (checked first so auto-replies and
 * delay warnings never count as bounces), hard 20–39, soft 40–59.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bounce_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('pattern', 1000);
            $table->string('type', 16); // hard|soft|internal
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['enabled', 'sort_order']);
        });

        $now = now();

        DB::table('bounce_rules')->insert(array_map(
            static fn (array $rule): array => $rule + ['enabled' => true, 'created_at' => $now, 'updated_at' => $now],
            [
                // ---- internal: never a real bounce -------------------------------
                [
                    'name' => 'Auto-reply / out-of-office',
                    'pattern' => 'auto[\s_-]?(?:reply|response|responder|submitted:\s*auto)|out\s+of\s+(?:the\s+)?office|vacation\s+(?:reply|response|notice)|i\s+am\s+(?:currently\s+)?(?:away|on\s+leave)',
                    'type' => 'internal',
                    'sort_order' => 10,
                ],
                [
                    'name' => 'Delivery delayed warning (not final)',
                    'pattern' => 'delivery\s+status\s+notification\s*\(delay\)|action:\s*delayed|warning:\s*message\s+.*delayed|delivery\s+temporarily\s+suspended|has\s+not\s+yet\s+been\s+delivered',
                    'type' => 'internal',
                    'sort_order' => 11,
                ],
                // ---- hard: permanent failures ------------------------------------
                [
                    'name' => 'Unknown user / bad mailbox',
                    'pattern' => 'user\s+unknown|unknown\s+user|no\s+such\s+user|user\s+not\s+found|mailbox\s+(?:unavailable|not\s+found|does\s+not\s+exist)|invalid\s+(?:mailbox|recipient|address)|address\s+(?:rejected|unknown|not\s+found)|recipient\s+(?:rejected|address\s+rejected)|unrouteable\s+address|does\s+not\s+exist|account\s+(?:disabled|discontinued|closed|deactivated)',
                    'type' => 'hard',
                    'sort_order' => 20,
                ],
                [
                    'name' => 'Permanent DSN status 5.x.x',
                    'pattern' => 'status:\s*5\.\d+\.\d+',
                    'type' => 'hard',
                    'sort_order' => 30,
                ],
                // ---- soft: transient failures ------------------------------------
                [
                    'name' => 'Mailbox full / quota',
                    'pattern' => 'mailbox\s+(?:is\s+)?full|quota\s+exceeded|over\s+quota|insufficient\s+(?:system\s+)?storage|exceeded\s+storage',
                    'type' => 'soft',
                    'sort_order' => 40,
                ],
                [
                    'name' => 'Greylisting / try again later',
                    'pattern' => 'grey.?list|gray.?list|try\s+(?:again\s+)?later|temporar(?:y|ily)\s+(?:failure|deferred|rejected|unavailable|blocked)|please\s+retry|service\s+(?:currently\s+)?unavailable|connection\s+(?:timed\s+out|refused)|resources\s+temporarily\s+unavailable',
                    'type' => 'soft',
                    'sort_order' => 41,
                ],
                [
                    'name' => 'Transient DSN status 4.x.x',
                    'pattern' => 'status:\s*4\.\d+\.\d+',
                    'type' => 'soft',
                    'sort_order' => 50,
                ],
            ],
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('bounce_rules');
    }
};
