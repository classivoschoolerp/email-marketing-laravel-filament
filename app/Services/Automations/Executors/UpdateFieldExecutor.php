<?php

declare(strict_types=1);

namespace App\Services\Automations\Executors;

use App\Models\AutomationNode;
use App\Models\EmailList;
use App\Models\ListField;
use App\Services\Automations\ExecutionContext;
use App\Services\Automations\NodeResult;
use App\Services\Content\MergeTags;
use App\Services\Lists\FieldValueService;

/**
 * F-2.4.2-026 — update custom field value through FieldValueService (which
 * fires the SubscriberFieldValue model events feeding field-change
 * triggers). The value is merge-tag capable. Fields are per-list: if the
 * configured field does not belong to the subscriber's list, a field with
 * the same merge tag on their list is used when present, otherwise the node
 * is skipped (logged).
 */
class UpdateFieldExecutor implements NodeExecutor
{
    public function __construct(
        private readonly FieldValueService $fieldValues,
        private readonly MergeTags $mergeTags,
    ) {}

    public function execute(AutomationNode $node, ExecutionContext $ctx): NodeResult
    {
        $field = ListField::withoutTenancy()->find((int) $node->config('list_field_id', 0));

        if ($field !== null && (int) $field->list_id !== (int) $ctx->subscriber->list_id) {
            $field = ListField::withoutTenancy()
                ->where('list_id', $ctx->subscriber->list_id)
                ->where('tag', $field->tag)
                ->first();
        }

        if ($field === null) {
            return NodeResult::continue(detail: ['skipped' => 'field_not_on_list']);
        }

        $list = EmailList::withoutTenancy()->find($ctx->subscriber->list_id);
        $value = $this->mergeTags->renderFor((string) $node->config('value', ''), null, $list, $ctx->subscriber);

        if ($ctx->dryRun) {
            return NodeResult::continue(detail: ['would_set' => [$field->tag => $value]]);
        }

        $this->fieldValues->set($ctx->subscriber, $field, $value);

        return NodeResult::continue(detail: ['set' => [$field->tag => $value]]);
    }
}
