<?php

declare(strict_types=1);

namespace App\Services\Automations\Executors;

use App\Models\AutomationNode;
use App\Models\ListField;
use App\Services\Automations\ExecutionContext;
use App\Services\Automations\NodeResult;
use App\Services\Lists\FieldValueService;

/**
 * F-2.4.2-037 — multi-branch switch/case over a subscriber attribute:
 * `email`, `status`, or a custom field (list_field_id). Case values compare
 * case-insensitively; no match → `default` branch.
 */
class SplitExecutor implements NodeExecutor
{
    public function __construct(private readonly FieldValueService $fieldValues) {}

    public function execute(AutomationNode $node, ExecutionContext $ctx): NodeResult
    {
        $value = mb_strtolower(trim($this->valueFor($node, $ctx)));
        $cases = array_values((array) $node->config('cases', []));

        foreach ($cases as $index => $case) {
            $expected = mb_strtolower(trim((string) (is_array($case) ? ($case['value'] ?? '') : $case)));

            if ($expected !== '' && $expected === $value) {
                return NodeResult::continue('case:'.$index, ['value' => $value]);
            }
        }

        return NodeResult::continue('default', ['value' => $value]);
    }

    private function valueFor(AutomationNode $node, ExecutionContext $ctx): string
    {
        $fieldId = (int) $node->config('list_field_id', 0);

        if ($fieldId > 0) {
            $field = ListField::withoutTenancy()->find($fieldId);

            return $field !== null ? (string) ($this->fieldValues->raw($ctx->subscriber, $field) ?? '') : '';
        }

        return match ((string) $node->config('source', 'email')) {
            'status' => $ctx->subscriber->status->value,
            default => (string) $ctx->subscriber->email,
        };
    }
}
