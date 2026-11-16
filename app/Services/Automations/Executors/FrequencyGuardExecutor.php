<?php

declare(strict_types=1);

namespace App\Services\Automations\Executors;

use App\Models\AutomationEmailSend;
use App\Models\AutomationNode;
use App\Models\Subscriber;
use App\Services\Automations\ExecutionContext;
use App\Services\Automations\NodeResult;
use Illuminate\Support\Carbon;

/**
 * F-2.4.2-042 — frequency cap / quiet hours guard node. When the subscriber
 * is inside quiet hours (customer timezone, overnight windows supported) or
 * has hit the automation-email frequency cap (sent rows across all of the
 * customer's automations within the rolling window), the run sleeps until
 * the earliest allowed moment, then passes through.
 */
class FrequencyGuardExecutor implements NodeExecutor
{
    public function execute(AutomationNode $node, ExecutionContext $ctx): NodeResult
    {
        if ($ctx->dryRun) {
            return NodeResult::continue(detail: ['guard' => 'skipped']);
        }

        $now = $ctx->now();
        $due = $now->copy();

        if ((bool) $node->config('quiet_enabled', false)) {
            $due = max($due, $this->quietHoursEnd($node, $now, $ctx->timezone()));
        }

        if ((bool) $node->config('cap_enabled', false)) {
            $due = max($due, $this->capClearsAt($node, $ctx, $now));
        }

        if ($due->greaterThan($now)) {
            return NodeResult::sleep($due, detail: ['guard_until' => $due->toIso8601String()]);
        }

        return NodeResult::continue(detail: ['guard' => 'clear']);
    }

    /** End of the quiet window if $now is inside it, else $now. */
    private function quietHoursEnd(AutomationNode $node, Carbon $now, string $timezone): Carbon
    {
        $start = (string) $node->config('quiet_start', '22:00');
        $end = (string) $node->config('quiet_end', '08:00');

        $local = $now->copy()->setTimezone($timezone);
        $startAt = $local->copy()->setTimeFromTimeString($start);
        $endAt = $local->copy()->setTimeFromTimeString($end);

        if ($startAt->lessThanOrEqualTo($endAt)) {
            // Same-day window, e.g. 12:00–14:00.
            return $local->between($startAt, $endAt) ? $endAt->utc() : $now->copy();
        }

        // Overnight window, e.g. 22:00–08:00.
        if ($local->greaterThanOrEqualTo($startAt)) {
            return $endAt->addDay()->utc();
        }

        if ($local->lessThan($endAt)) {
            return $endAt->utc();
        }

        return $now->copy();
    }

    private function capClearsAt(AutomationNode $node, ExecutionContext $ctx, Carbon $now): Carbon
    {
        $max = max(1, (int) $node->config('cap_max', 3));
        $hours = max(1, (int) $node->config('cap_hours', 24));
        $windowStart = $now->copy()->subHours($hours);

        $subscriberIds = Subscriber::withoutTenancy()
            ->where('customer_id', $ctx->automation->customer_id)
            ->where('email', $ctx->subscriber->email)
            ->pluck('id');

        $recent = AutomationEmailSend::withoutTenancy()
            ->whereIn('subscriber_id', $subscriberIds)
            ->where('status', AutomationEmailSend::STATUS_SENT)
            ->where('created_at', '>=', $windowStart)
            ->orderBy('created_at')
            ->get(['created_at']);

        if ($recent->count() < $max) {
            return $now->copy();
        }

        // Oldest send in the window ages out of it at created_at + window.
        $oldest = Carbon::parse($recent[$recent->count() - $max]['created_at']);

        return $oldest->addHours($hours);
    }
}
