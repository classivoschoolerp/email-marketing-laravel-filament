<?php

declare(strict_types=1);

namespace App\Jobs\Automations;

use App\Enums\SubscriberStatus;
use App\Jobs\Concerns\IdempotentJob;
use App\Models\Automation;
use App\Models\AutomationEmailSend;
use App\Models\AutomationNode;
use App\Models\AutomationRun;
use App\Models\AutomationRunLog;
use App\Models\EmailList;
use App\Models\EmailTemplate;
use App\Models\Subscriber;
use App\Sending\DriverRegistry;
use App\Sending\Message;
use App\Sending\RetryPolicy;
use App\Sending\ServerPicker;
use App\Sending\ServerUsage;
use App\Services\Automations\TrackSigner;
use App\Services\Blacklist\BlacklistSendGuard;
use App\Services\Content\MergeTags;
use App\Services\Content\PlainTextGenerator;
use App\Services\Content\Spintax;
use App\Services\Lists\PublicPageRenderer;
use App\Support\Queue\Queues;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;

/**
 * F-2.4.2-020 — single automation email send. Modeled on the WP-13
 * SendToSubscriberJob but campaign-free: reuses MergeTags→Spintax (D-55/62
 * order), the CAN-SPAM footer, ServerPicker/DriverRegistry/RetryPolicy/
 * ServerUsage and the blacklist send guard. Idempotency: job key
 * `automation-send:{run}:{node}` + the unique (run_id, node_id) claim row
 * in automation_email_sends (second arbiter). Deferrals (server pause)
 * release the key + claim so the retry wave re-delivers (D-61).
 *
 * Tracking (§2.4.3): WP-21's own signed open-pixel / click-redirect
 * endpoints are injected here because campaign tracking is campaign-bound.
 */
class AutomationSendJob implements ShouldQueue
{
    use Dispatchable;
    use IdempotentJob;
    use InteractsWithQueue;
    use Queueable;

    private int $retryUntilTimestamp;

    public function __construct(
        public readonly int $runId,
        public readonly int $nodeId,
    ) {
        $this->onQueue(Queues::AUTOMATION);
        $this->retryUntilTimestamp = Carbon::now()->addDay()->getTimestamp();
    }

    public function retryUntil(): CarbonInterface
    {
        return Carbon::createFromTimestamp($this->retryUntilTimestamp);
    }

    public function idempotencyKey(): string
    {
        return 'automation-send:'.$this->runId.':'.$this->nodeId;
    }

    public function handle(
        ServerPicker $picker,
        DriverRegistry $registry,
        ServerUsage $usage,
        MergeTags $mergeTags,
        Spintax $spintax,
        PlainTextGenerator $plainText,
        PublicPageRenderer $pages,
        TrackSigner $signer,
    ): void {
        $run = AutomationRun::withoutTenancy()->find($this->runId);
        $node = AutomationNode::query()->find($this->nodeId);

        if ($run === null || $node === null) {
            return;
        }

        /** @var Automation|null $automation */
        $automation = Automation::withoutTenancy()->find($run->automation_id);

        if ($automation === null) {
            return;
        }

        $subscriber = Subscriber::withoutTenancy()->find($run->subscriber_id);

        if ($subscriber === null) {
            return;
        }

        $send = $this->claim($run, $node, $automation, $subscriber);

        if ($send === null) {
            return; // already handled
        }

        if ($subscriber->status !== SubscriberStatus::Confirmed) {
            $this->finalize($send, AutomationEmailSend::STATUS_SKIPPED, response: __('sending.log.subscriber_ineligible'));

            return;
        }

        if (app(BlacklistSendGuard::class)->blocked($subscriber)) {
            $this->finalize($send, AutomationEmailSend::STATUS_SKIPPED, response: __('automations.errors.blacklisted'));

            return;
        }

        $customer = $automation->customer()->withoutGlobalScopes()->first();

        if ($customer === null) {
            $this->finalize($send, AutomationEmailSend::STATUS_FAILED, response: __('sending.log.no_server'));

            return;
        }

        $list = EmailList::withoutTenancy()->find($subscriber->list_id);
        $seed = $subscriber->uid.':'.$automation->uid; // D-55 send-seed shape

        // Merge tags BEFORE spintax, everywhere (D-62).
        $subject = $spintax->render(
            $mergeTags->renderFor((string) $node->config('subject', ''), null, $list, $subscriber, $seed),
            $seed,
        );

        $html = $this->contentHtml($node);

        if ($html === null) {
            $this->finalize($send, AutomationEmailSend::STATUS_FAILED, response: __('automations.errors.no_content'));

            return;
        }

        $html = $spintax->render($mergeTags->renderFor($html, null, $list, $subscriber, $seed), $seed);

        if ($list !== null) {
            $footer = $pages->footer($list);

            if ($footer !== '') {
                $html = str_contains($html, '</body>')
                    ? str_replace('</body>', $footer.'</body>', $html)
                    : $html.$footer;
            }
        }

        if ((bool) $node->config('track', true)) {
            $html = $this->instrument($html, (string) $send->uid, $signer);
        }

        $message = new Message(
            fromEmail: (string) ($node->config('from_email') ?: ($list?->from_email ?: $customer->email)),
            fromName: ($node->config('from_name') ?: ($list?->from_name ?: trim($customer->first_name.' '.$customer->last_name))) ?: null,
            to: [$subscriber->email => null],
            subject: $subject,
            html: $html,
            text: $plainText->generate($html),
            headers: [
                'X-EMP-Automation-Uid' => (string) $automation->uid,
                'X-EMP-Subscriber-Uid' => (string) $subscriber->uid,
            ],
            replyTo: $node->config('reply_to') !== null && $node->config('reply_to') !== '' ? (string) $node->config('reply_to') : null,
        );

        $pick = $picker->pickFor($customer, 1, null);

        if ($pick->shouldPause) {
            // D-50/D-61: quota pause defers without consuming idempotency.
            $send->delete();
            $this->releaseIdempotencyKey();
            $this->release(max(60, (int) ($pick->retryAfterSeconds ?? 300)));

            return;
        }

        $server = $pick->server;

        if ($server === null) {
            $this->finalize($send, AutomationEmailSend::STATUS_FAILED, response: __('sending.log.no_server'));
            $this->logRun($run, $node, AutomationRunLog::ACTION_ERROR, ['error' => __('sending.log.no_server')]);

            return;
        }

        $policy = RetryPolicy::fromSettings();
        $attempt = (int) $send->retries + 1;

        $result = $registry->forServer($server)->send($message, $server);

        if ($result->ok) {
            $usage->recordSend($server);
            $this->finalize($send, AutomationEmailSend::STATUS_SENT, $server->id, $result->messageId, null, $attempt - 1, $subject);
            $this->logRun($run, $node, AutomationRunLog::ACTION_EMAIL_SENT, ['send_id' => $send->id, 'subject' => $subject]);

            return;
        }

        $usage->recordFailure($server);

        if ($result->transient && $policy->shouldRetry($result, $attempt)) {
            $send->forceFill([
                'status' => AutomationEmailSend::STATUS_RETRYING,
                'retries' => $attempt,
                'response' => $result->error,
            ])->save();

            $this->release($policy->delayForAttempt($attempt));

            return;
        }

        $this->finalize($send, AutomationEmailSend::STATUS_FAILED, $server->id, null, $result->error, $attempt);
        $this->logRun($run, $node, AutomationRunLog::ACTION_ERROR, ['error' => (string) $result->error]);
    }

    public function failed(?\Throwable $exception): void
    {
        AutomationEmailSend::withoutTenancy()
            ->where('run_id', $this->runId)
            ->where('node_id', $this->nodeId)
            ->where('status', AutomationEmailSend::STATUS_RETRYING)
            ->update([
                'status' => AutomationEmailSend::STATUS_FAILED,
                'response' => $exception?->getMessage(),
            ]);
    }

    private function claim(AutomationRun $run, AutomationNode $node, Automation $automation, Subscriber $subscriber): ?AutomationEmailSend
    {
        try {
            return AutomationEmailSend::withoutTenancy()->create([
                'automation_id' => $automation->id,
                'run_id' => $run->id,
                'node_id' => $node->id,
                'customer_id' => $automation->customer_id,
                'subscriber_id' => $subscriber->id,
                'email' => $subscriber->email,
                'status' => AutomationEmailSend::STATUS_RETRYING,
            ]);
        } catch (UniqueConstraintViolationException) {
            $existing = AutomationEmailSend::withoutTenancy()
                ->where('run_id', $run->id)
                ->where('node_id', $node->id)
                ->first();

            return $existing !== null && $existing->status === AutomationEmailSend::STATUS_RETRYING
                ? $existing
                : null;
        }
    }

    private function contentHtml(AutomationNode $node): ?string
    {
        if ((string) $node->config('mode', 'inline') === 'template') {
            $template = EmailTemplate::withoutTenancy()->find((int) $node->config('template_id', 0));

            $html = $template?->content_html;

            return $html !== null && $html !== '' ? (string) $html : null;
        }

        $html = (string) $node->config('content_html', '');

        return $html !== '' ? $html : null;
    }

    /** Inject the open pixel + rewrite links through the click redirect. */
    private function instrument(string $html, string $sendUid, TrackSigner $signer): string
    {
        $html = preg_replace_callback(
            '/href=(["\'])(https?:\/\/[^"\']+)\1/i',
            function (array $match) use ($sendUid, $signer): string {
                $url = html_entity_decode($match[2]);

                $tracked = route('automations.track.click', [
                    'uid' => $sendUid,
                    'sig' => $signer->signClick($sendUid, $url),
                ]).'?u='.rawurlencode($url);

                return 'href='.$match[1].$tracked.$match[1];
            },
            $html,
        ) ?? $html;

        $pixel = '<img src="'.e(route('automations.track.open', [
            'uid' => $sendUid,
            'sig' => $signer->signOpen($sendUid),
        ])).'" width="1" height="1" alt="" style="display:none" />';

        return str_contains($html, '</body>')
            ? str_replace('</body>', $pixel.'</body>', $html)
            : $html.$pixel;
    }

    private function finalize(
        AutomationEmailSend $send,
        string $status,
        ?int $serverId = null,
        ?string $messageId = null,
        ?string $response = null,
        int $retries = 0,
        ?string $subject = null,
    ): void {
        $send->forceFill([
            'status' => $status,
            'message_id' => $messageId,
            'response' => $response,
            'retries' => $retries,
            'subject' => $subject,
            'sent_at' => $status === AutomationEmailSend::STATUS_SENT ? Carbon::now() : null,
        ])->save();
    }

    /** @param array<string, mixed> $detail */
    private function logRun(AutomationRun $run, AutomationNode $node, string $action, array $detail): void
    {
        AutomationRunLog::query()->create([
            'automation_id' => $run->automation_id,
            'run_id' => $run->id,
            'node_id' => $node->id,
            'node_uid' => $node->uid,
            'node_type' => $node->type->value,
            'action' => $action,
            'detail' => $detail,
            'created_at' => Carbon::now(),
        ]);
    }
}
