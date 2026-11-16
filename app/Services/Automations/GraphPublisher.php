<?php

declare(strict_types=1);

namespace App\Services\Automations;

use App\Enums\AutomationNodeType;
use App\Enums\AutomationRunStatus;
use App\Models\Automation;
use App\Models\AutomationNode;
use App\Models\AutomationRun;
use App\Models\AutomationSegmentMember;
use App\Models\ListField;
use App\Models\ListSegment;
use App\Services\Segments\SegmentQueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Publish = validate the draft canvas, materialize it into automation_nodes
 * (upsert by node uid so runs + stats survive edits), bump `version`,
 * snapshot into automation_versions and denormalize the trigger onto the
 * automations row (F-2.4.2-002). Runs parked on nodes removed by the new
 * version exit with reason `node_removed`.
 */
class GraphPublisher
{
    public function __construct(
        private readonly GraphValidator $validator,
        private readonly SegmentQueryBuilder $segments,
    ) {}

    /**
     * @param  array<string, mixed>|null  $canvas  null = publish the stored draft
     *
     * @throws ValidationException
     */
    public function publish(Automation $automation, ?array $canvas = null): Automation
    {
        $canvas = $canvas ?? (array) ($automation->canvas ?? []);
        $errors = $this->validator->validate($canvas);

        if ($errors !== []) {
            throw ValidationException::withMessages(['canvas' => $errors]);
        }

        $now = Carbon::now();

        DB::transaction(function () use ($automation, $canvas, $now): void {
            $nodes = $this->validator->nodes($canvas);
            $keptUids = [];
            $sort = 0;

            foreach ($nodes as $definition) {
                $uid = (string) $definition['uid'];
                $keptUids[] = $uid;

                AutomationNode::query()->updateOrCreate(
                    ['automation_id' => $automation->id, 'uid' => $uid],
                    [
                        'type' => (string) $definition['type'],
                        'label' => isset($definition['label']) && $definition['label'] !== '' ? (string) $definition['label'] : null,
                        'config' => (array) ($definition['config'] ?? []),
                        'edges' => (object) ($definition['edges'] ?? []),
                        'sort' => $sort++,
                    ],
                );
            }

            // Nodes dropped by this version: exit their parked runs, then delete.
            $removed = AutomationNode::query()
                ->where('automation_id', $automation->id)
                ->whereNotIn('uid', $keptUids)
                ->get();

            foreach ($removed as $node) {
                $stranded = AutomationRun::withoutTenancy()
                    ->where('automation_id', $automation->id)
                    ->where('current_node_id', $node->id)
                    ->where('status', AutomationRunStatus::Active->value)
                    ->get();

                foreach ($stranded as $run) {
                    $run->forceFill([
                        'status' => AutomationRunStatus::Exited,
                        'completed_at' => $now,
                        'wake_at' => null,
                        'waiting_for' => null,
                        'exit_reason' => 'node_removed',
                    ])->save();

                    Automation::withoutTenancy()->whereKey($automation->id)
                        ->where('active_count', '>', 0)->decrement('active_count');
                }

                $node->delete();
            }

            $trigger = collect($this->validator->nodes($canvas))
                ->first(fn (array $node): bool => AutomationNodeType::tryFrom((string) $node['type'])?->isTrigger() ?? false);

            $triggerConfig = (array) ($trigger['config'] ?? []);

            $automation->forceFill([
                'canvas' => $canvas,
                'version' => (int) $automation->version + 1,
                'published_at' => $now,
                'trigger_type' => (string) $trigger['type'],
                'trigger_config' => $triggerConfig,
                'email_list_id' => $this->listIdFrom((string) $trigger['type'], $triggerConfig),
            ])->save();

            $automation->versions()->create([
                'version' => $automation->version,
                'canvas' => $canvas,
                'published_at' => $now,
            ]);
        });

        $automation->refresh();
        $this->seedSegmentState($automation);

        return $automation;
    }

    /**
     * Baseline the segment membership snapshot WITHOUT enrolling, so only
     * future enters/exits fire (F-2.4.2-018, documented behaviour).
     */
    public function seedSegmentState(Automation $automation): void
    {
        $type = $automation->triggerType();

        if (! in_array($type, [AutomationNodeType::TriggerSegmentEntered, AutomationNodeType::TriggerSegmentExited], true)) {
            return;
        }

        $segmentId = (int) data_get($automation->trigger_config, 'segment_id', 0);
        $segment = $segmentId > 0 ? ListSegment::withoutTenancy()->find($segmentId) : null;

        AutomationSegmentMember::query()->where('automation_id', $automation->id)->delete();

        if ($segment === null) {
            return;
        }

        $now = Carbon::now();

        $this->segments->subscribers($segment)->select('subscribers.id')->chunkById(500, function ($subscribers) use ($automation, $now): void {
            AutomationSegmentMember::query()->insert(
                $subscribers->map(fn ($subscriber): array => [
                    'automation_id' => $automation->id,
                    'subscriber_id' => $subscriber->id,
                    'created_at' => $now,
                ])->all(),
            );
        });

        $automation->forceFill(['segment_seeded_at' => $now])->save();
    }

    /** @param array<string, mixed> $config */
    private function listIdFrom(string $type, array $config): ?int
    {
        $listId = (int) ($config['list_id'] ?? 0);

        if ($listId > 0) {
            return $listId;
        }

        $segmentId = (int) ($config['segment_id'] ?? 0);

        if ($segmentId > 0) {
            $segmentListId = (int) (ListSegment::withoutTenancy()->whereKey($segmentId)->value('list_id') ?? 0);

            return $segmentListId > 0 ? $segmentListId : null;
        }

        $fieldId = (int) ($config['list_field_id'] ?? 0);

        if ($fieldId > 0) {
            $fieldListId = (int) (ListField::withoutTenancy()->whereKey($fieldId)->value('list_id') ?? 0);

            return $fieldListId > 0 ? $fieldListId : null;
        }

        return null;
    }
}
