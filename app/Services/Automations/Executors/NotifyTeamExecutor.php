<?php

declare(strict_types=1);

namespace App\Services\Automations\Executors;

use App\Mail\AutomationNotificationMail;
use App\Models\AutomationNode;
use App\Models\EmailList;
use App\Services\Automations\ExecutionContext;
use App\Services\Automations\NodeResult;
use App\Services\Content\MergeTags;
use Illuminate\Support\Facades\Mail;

/**
 * F-2.4.2-024 — internal notification email to the team. Not a marketing
 * send: goes through the app mailer on Queues::TRANSACTIONAL (WP-20
 * precedent), content pre-rendered (merge tags with subscriber context) so
 * the worker needs no tenant context.
 */
class NotifyTeamExecutor implements NodeExecutor
{
    public function __construct(private readonly MergeTags $mergeTags) {}

    public function execute(AutomationNode $node, ExecutionContext $ctx): NodeResult
    {
        $recipients = $this->recipients((string) $node->config('recipients', ''));

        if ($recipients === []) {
            return NodeResult::fail(__('automations.errors.no_recipients'));
        }

        if ($ctx->dryRun) {
            return NodeResult::continue(detail: ['would_notify' => $recipients]);
        }

        $list = EmailList::withoutTenancy()->find($ctx->subscriber->list_id);
        $subject = $this->mergeTags->renderFor((string) $node->config('subject', ''), null, $list, $ctx->subscriber);
        $body = $this->mergeTags->renderFor(nl2br(e((string) $node->config('body', ''))), null, $list, $ctx->subscriber);

        foreach ($recipients as $recipient) {
            Mail::to($recipient)->queue(new AutomationNotificationMail($subject, $body));
        }

        return NodeResult::continue(detail: ['notified' => $recipients]);
    }

    /** @return list<string> */
    private function recipients(string $raw): array
    {
        $emails = [];

        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $candidate) {
            $candidate = mb_strtolower(trim($candidate));

            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                $emails[] = $candidate;
            }
        }

        return array_values(array_unique($emails));
    }
}
