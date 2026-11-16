<?php

declare(strict_types=1);

namespace App\Services\Automations;

use App\Enums\AutomationNodeType;
use App\Enums\AutomationRunStatus;
use App\Models\Automation;
use App\Models\AutomationEmailSend;
use App\Models\AutomationRun;
use App\Models\AutomationRunLog;
use App\Models\AutomationWebhookLog;
use Illuminate\Support\Collection;

/**
 * Report queries for §2.4.3: funnel, per-node stats, email performance,
 * goal conversion + revenue, live per-node counts and the error log. All
 * counts recomputed from source tables (runs / run_logs / email_sends) —
 * the counter columns on automations/nodes are cheap-display only (D-64
 * spirit).
 */
class AutomationStats
{
    /**
     * Funnel + per-node stats in BFS order (F-2.4.3-001/002).
     *
     * @return list<array<string, mixed>>
     */
    public function perNode(Automation $automation): array
    {
        $graph = Graph::for($automation);

        $reached = AutomationRunLog::query()
            ->where('automation_id', $automation->id)
            ->whereIn('action', [AutomationRunLog::ACTION_REACHED, AutomationRunLog::ACTION_ENTERED])
            ->selectRaw('node_id, count(distinct run_id) as runs')
            ->groupBy('node_id')
            ->pluck('runs', 'node_id');

        $active = $this->activeByNode($automation);

        $endedAt = AutomationRun::withoutTenancy()
            ->where('automation_id', $automation->id)
            ->where('status', '!=', AutomationRunStatus::Active->value)
            ->selectRaw('current_node_id, count(*) as runs')
            ->groupBy('current_node_id')
            ->pluck('runs', 'current_node_id');

        $completedAt = AutomationRun::withoutTenancy()
            ->where('automation_id', $automation->id)
            ->where('status', AutomationRunStatus::Completed->value)
            ->selectRaw('current_node_id, count(*) as runs')
            ->groupBy('current_node_id')
            ->pluck('runs', 'current_node_id');

        $converted = AutomationRunLog::query()
            ->where('automation_id', $automation->id)
            ->where('action', AutomationRunLog::ACTION_GOAL_MET)
            ->selectRaw('node_id, count(distinct run_id) as runs')
            ->groupBy('node_id')
            ->pluck('runs', 'node_id');

        $emailStats = $this->emailStatsByNode($automation);

        $rows = [];
        $entered = $this->summary($automation)['entered'];

        foreach ($graph->bfsOrder() as $node) {
            $nodeReached = (int) ($reached[$node->id] ?? 0);
            $email = $emailStats[$node->id] ?? null;
            $ended = (int) ($endedAt[$node->id] ?? 0);
            $completedHere = (int) ($completedAt[$node->id] ?? 0);

            $rows[] = [
                'node' => $node,
                'reached' => $nodeReached,
                'reached_pct' => $entered > 0 ? round($nodeReached / $entered * 100, 1) : null,
                'active' => (int) ($active[$node->id] ?? 0),
                'sent' => (int) ($email['sent'] ?? 0),
                'opened' => (int) ($email['opened'] ?? 0),
                'clicked' => (int) ($email['clicked'] ?? 0),
                'converted' => (int) ($converted[$node->id] ?? 0),
                // dropped = journeys that ended on this node without
                // completing (exited / failed here).
                'dropped' => max(0, $ended - $completedHere),
            ];
        }

        return $rows;
    }

    /** @return array<int|string, int> node_id => active runs (F-2.4.2-004) */
    public function activeByNode(Automation $automation): array
    {
        return AutomationRun::withoutTenancy()
            ->where('automation_id', $automation->id)
            ->where('status', AutomationRunStatus::Active->value)
            ->selectRaw('current_node_id, count(*) as runs')
            ->groupBy('current_node_id')
            ->pluck('runs', 'current_node_id')
            ->all();
    }

    /** @return array{entered: int, active: int, completed: int, converted: int, conversion_rate: ?float, revenue: int} */
    public function summary(Automation $automation): array
    {
        $base = AutomationRun::withoutTenancy()->where('automation_id', $automation->id);

        $entered = (int) (clone $base)->count();
        $converted = (int) (clone $base)->whereNotNull('goal_met_at')->count();

        return [
            'entered' => $entered,
            'active' => (int) (clone $base)->where('status', AutomationRunStatus::Active->value)->count(),
            'completed' => (int) (clone $base)->where('status', AutomationRunStatus::Completed->value)->count(),
            'converted' => $converted,
            'conversion_rate' => $entered > 0 ? round($converted / $entered * 100, 1) : null,
            'revenue' => (int) (clone $base)->whereNotNull('goal_met_at')->sum('conversion_value'),
        ];
    }

    /**
     * Email performance per send node (F-2.4.3-003).
     *
     * @return array<int, array{sent: int, failed: int, skipped: int, opened: int, clicked: int}>
     */
    public function emailStatsByNode(Automation $automation): array
    {
        $rows = AutomationEmailSend::withoutTenancy()
            ->where('automation_id', $automation->id)
            ->selectRaw("node_id,
                sum(case when status = 'sent' then 1 else 0 end) as sent,
                sum(case when status = 'failed' then 1 else 0 end) as failed,
                sum(case when status = 'skipped' then 1 else 0 end) as skipped,
                sum(case when opened_at is not null then 1 else 0 end) as opened,
                sum(case when clicked_at is not null then 1 else 0 end) as clicked")
            ->groupBy('node_id')
            ->get();

        $stats = [];

        foreach ($rows as $row) {
            $stats[(int) $row->node_id] = [
                'sent' => (int) $row->sent,
                'failed' => (int) $row->failed,
                'skipped' => (int) $row->skipped,
                'opened' => (int) $row->opened,
                'clicked' => (int) $row->clicked,
            ];
        }

        return $stats;
    }

    /**
     * Error log: failed nodes + failed webhooks (F-2.4.3-006).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function errors(Automation $automation, int $limit = 100): Collection
    {
        $nodeErrors = AutomationRunLog::query()
            ->where('automation_id', $automation->id)
            ->where('action', AutomationRunLog::ACTION_ERROR)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (AutomationRunLog $log): array => [
                'kind' => 'node',
                'node_uid' => $log->node_uid,
                'node_type' => $log->node_type,
                'run_id' => $log->run_id,
                'message' => (string) data_get($log->detail, 'error', ''),
                'at' => $log->created_at,
            ]);

        $webhookErrors = AutomationWebhookLog::withoutTenancy()
            ->where('automation_id', $automation->id)
            ->where('status', AutomationWebhookLog::STATUS_FAILED)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (AutomationWebhookLog $log): array => [
                'kind' => 'webhook',
                'node_uid' => null,
                'node_type' => AutomationNodeType::ActionWebhook->value,
                'run_id' => $log->run_id,
                'message' => trim(($log->status_code !== null ? 'HTTP '.$log->status_code.' ' : '').(string) $log->error),
                'at' => $log->updated_at ?? $log->created_at,
            ]);

        return $nodeErrors->concat($webhookErrors)
            ->sortByDesc('at')
            ->take($limit)
            ->values();
    }

    /**
     * Contacts currently parked on a node (F-2.4.3-005 drill-down).
     *
     * @return Collection<int, AutomationRun>
     */
    public function contactsAtNode(Automation $automation, int $nodeId, ?int $limit = null): Collection
    {
        return AutomationRun::withoutTenancy()
            ->where('automation_id', $automation->id)
            ->where('status', AutomationRunStatus::Active->value)
            ->where('current_node_id', $nodeId)
            ->with('subscriber')
            ->orderBy('entered_at')
            ->limit($limit ?? max(1, (int) config('emp-automations.report_drilldown_limit', 500)))
            ->get();
    }

    /**
     * CSV export rows (F-2.4.3-007): summary + per-node table.
     *
     * @return list<list<string|int|float|null>>
     */
    public function exportRows(Automation $automation): array
    {
        $summary = $this->summary($automation);

        $rows = [
            [__('automations.report.export.automation'), $automation->name],
            [__('automations.report.export.entered'), $summary['entered']],
            [__('automations.report.export.active'), $summary['active']],
            [__('automations.report.export.completed'), $summary['completed']],
            [__('automations.report.export.converted'), $summary['converted']],
            [__('automations.report.export.conversion_rate'), $summary['conversion_rate'] ?? 0],
            [__('automations.report.export.revenue'), $summary['revenue']],
            [],
            [
                __('automations.report.columns.node'),
                __('automations.report.columns.type'),
                __('automations.report.columns.reached'),
                __('automations.report.columns.active'),
                __('automations.report.columns.sent'),
                __('automations.report.columns.opened'),
                __('automations.report.columns.clicked'),
                __('automations.report.columns.converted'),
                __('automations.report.columns.dropped'),
            ],
        ];

        foreach ($this->perNode($automation) as $row) {
            /** @var \App\Models\AutomationNode $node */
            $node = $row['node'];

            $rows[] = [
                $node->label ?? $node->type->label(),
                $node->type->value,
                $row['reached'],
                $row['active'],
                $row['sent'],
                $row['opened'],
                $row['clicked'],
                $row['converted'],
                $row['dropped'],
            ];
        }

        return $rows;
    }
}
