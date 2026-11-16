<?php

declare(strict_types=1);

namespace App\Services\Automations;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Subscriber;
use Illuminate\Support\Carbon;

/**
 * Everything a node executor may touch (WP-21). `dryRun` = test/simulate
 * mode (F-2.4.2-003): conditions evaluate for real, side effects (emails,
 * tags, webhooks, list ops, enrollment) are skipped and reported as
 * would-do steps.
 */
final class ExecutionContext
{
    private ?string $timezone = null;

    public function __construct(
        public Automation $automation,
        public Graph $graph,
        public Subscriber $subscriber,
        public AutomationRun $run,
        public bool $dryRun = false,
        public ?Carbon $now = null,
    ) {
        $this->now ??= Carbon::now();
    }

    public function now(): Carbon
    {
        return $this->now->copy();
    }

    /** Timezone for time-of-day / quiet-hour arithmetic. */
    public function timezone(): string
    {
        if ($this->timezone === null) {
            $timezone = (string) ($this->automation->customer()->withoutGlobalScopes()->value('timezone') ?? '');
            $this->timezone = $timezone !== '' ? $timezone : 'UTC';
        }

        return $this->timezone;
    }
}
