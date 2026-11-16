<?php

declare(strict_types=1);

namespace App\Services\Automations\Executors;

use App\Models\AutomationNode;
use App\Models\GlobalBlacklistEmail;
use App\Models\SuppressionList;
use App\Services\Automations\ExecutionContext;
use App\Services\Automations\NodeResult;
use App\Services\Blacklist\BlacklistEntries;
use App\Services\Suppression\SuppressionListEmails;

/**
 * F-2.4.2-028 — add the contact to a suppression list or to the customer's
 * blacklist, via the WP-16 mutation services.
 */
class SuppressExecutor implements NodeExecutor
{
    public function __construct(
        private readonly SuppressionListEmails $suppression,
        private readonly BlacklistEntries $blacklist,
    ) {}

    public function execute(AutomationNode $node, ExecutionContext $ctx): NodeResult
    {
        $mode = (string) $node->config('mode', 'blacklist');
        $email = (string) $ctx->subscriber->email;

        if ($ctx->dryRun) {
            return NodeResult::continue(detail: ['would_suppress' => $mode]);
        }

        if ($mode === 'suppression') {
            $list = SuppressionList::withoutTenancy()
                ->where('customer_id', $ctx->automation->customer_id)
                ->find((int) $node->config('suppression_list_id', 0));

            if ($list === null) {
                return NodeResult::fail(__('automations.errors.suppression_list_missing'));
            }

            $this->suppression->addMany($list, [$email]);

            return NodeResult::continue(detail: ['suppressed' => $list->id]);
        }

        $this->blacklist->addMany(
            [$email],
            __('automations.blacklist_reason', ['automation' => $ctx->automation->name]),
            GlobalBlacklistEmail::SOURCE_AUTOMATION,
            (int) $ctx->automation->customer_id,
        );

        return NodeResult::continue(detail: ['blacklisted' => true]);
    }
}
