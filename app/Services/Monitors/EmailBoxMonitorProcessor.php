<?php

declare(strict_types=1);

namespace App\Services\Monitors;

use App\Models\EmailBoxMonitor;
use App\Models\EmailBoxMonitorCondition;
use App\Models\EmailBoxMonitorLog;
use App\Services\Mailboxes\MailboxClientFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Polls one email box monitor and applies its rules (spec §2.9.6, WP-33).
 *
 * Per message: parse (MonitorMessageReader) → walk the monitor's conditions
 * in priority order (F-2.9.6-004) → the FIRST enabled condition that matches
 * applies its action (F-2.9.6-003) and evaluation stops → append an
 * email_box_monitor_logs row either way (F-2.9.6-005) → optionally delete the
 * mailbox message → stamp last_run_at.
 *
 * Structurally identical to BounceProcessor (WP-15), including the "one
 * broken message must not stall the mailbox" guarantee. Runs from the
 * mailbox-monitors:process command or the queued single-monitor job — never
 * synchronously in a UI request.
 */
class EmailBoxMonitorProcessor
{
    public function __construct(
        private readonly MailboxClientFactory $clients,
        private readonly MonitorMessageReader $reader,
        private readonly MonitorConditionMatcher $matcher,
        private readonly MonitorActionApplier $actions,
    ) {}

    /** @return int processed message count */
    public function process(EmailBoxMonitor $monitor): int
    {
        $client = $this->clients->make($monitor->protocol);
        $client->connect($monitor->connection());

        $processed = 0;

        try {
            $batch = $monitor->max_per_batch > 0
                ? (int) $monitor->max_per_batch
                : (int) config('emp-infra.bounces.default_batch', 200);

            /** @var list<EmailBoxMonitorCondition> $conditions */
            $conditions = $monitor->conditions()->where('enabled', true)->get()->all();

            foreach ($client->listMessages($batch) as $id) {
                try {
                    $this->handleMessage($monitor, $conditions, $client->fetch($id));
                    $processed++;

                    if ($monitor->delete_after_processing) {
                        $client->delete($id);
                    }
                } catch (Throwable $exception) {
                    Log::warning('Email box monitor message processing failed.', [
                        'email_box_monitor_id' => $monitor->id,
                        'message_ref' => $id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        } finally {
            $client->disconnect();
            $monitor->forceFill(['last_run_at' => now()])->saveQuietly();
        }

        return $processed;
    }

    /** @param  list<EmailBoxMonitorCondition>  $conditions */
    private function handleMessage(EmailBoxMonitor $monitor, array $conditions, string $raw): void
    {
        $message = $this->reader->read($raw);

        foreach ($conditions as $condition) {
            if (! $this->matcher->matches($condition, $message)) {
                continue;
            }

            [$result, $subscriber] = $this->actions->apply($monitor, $condition, $message);

            $this->log($monitor, $message, $condition, true, $result, $subscriber?->id);

            return; // highest-priority match wins (F-2.9.6-004)
        }

        $this->log($monitor, $message, null, false, __('infra.monitors.results.no_match'), null);
    }

    private function log(
        EmailBoxMonitor $monitor,
        MonitorMessage $message,
        ?EmailBoxMonitorCondition $condition,
        bool $matched,
        string $result,
        ?int $subscriberId,
    ): void {
        EmailBoxMonitorLog::query()->create([
            'email_box_monitor_id' => $monitor->id,
            'email_box_monitor_condition_id' => $condition?->id,
            'customer_id' => $monitor->customer_id,
            'subscriber_id' => $subscriberId,
            'from_email' => $message->fromEmail ?? mb_substr($message->from, 0, 255),
            'subject' => mb_substr($message->subject, 0, 512),
            'matched' => $matched,
            'action' => $condition?->action,
            'result' => $result,
        ]);
    }
}
