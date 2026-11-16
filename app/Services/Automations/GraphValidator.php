<?php

declare(strict_types=1);

namespace App\Services\Automations;

use App\Enums\AutomationNodeType;
use App\Models\AutomationNode;

/**
 * Publish-time canvas validation (F-2.4.2-002): exactly one trigger, the
 * trigger is the root, every node reachable, no dangling edges ("orphan
 * branches"), jump targets exist, percentage splits sum to 100, and each
 * node carries its type's required config keys.
 *
 * Canvas shape: {nodes: [{uid, type, label, config, edges}]}.
 */
class GraphValidator
{
    /**
     * @param  array<string, mixed>  $canvas
     * @return list<string> translated error messages; empty = valid
     */
    public function validate(array $canvas): array
    {
        $errors = [];
        $nodes = $this->nodes($canvas);

        if ($nodes === []) {
            return [__('automations.validation.empty')];
        }

        $byUid = [];
        $triggers = [];

        foreach ($nodes as $node) {
            $uid = (string) ($node['uid'] ?? '');
            $type = AutomationNodeType::tryFrom((string) ($node['type'] ?? ''));

            if ($uid === '' || $type === null) {
                $errors[] = __('automations.validation.malformed_node', ['uid' => $uid !== '' ? $uid : '?']);

                continue;
            }

            if (isset($byUid[$uid])) {
                $errors[] = __('automations.validation.duplicate_uid', ['uid' => $uid]);

                continue;
            }

            $byUid[$uid] = ['type' => $type, 'config' => (array) ($node['config'] ?? []), 'edges' => (array) ($node['edges'] ?? [])];

            if ($type->isTrigger()) {
                $triggers[] = $uid;
            }
        }

        if (count($triggers) !== 1) {
            $errors[] = __('automations.validation.one_trigger', ['count' => count($triggers)]);
        }

        // Edges must point at existing nodes and use known branch keys.
        $incoming = [];

        foreach ($byUid as $uid => $node) {
            $validBranches = AutomationNode::branchKeysFor($node['type'], $node['config']);

            foreach ($node['edges'] as $branch => $target) {
                if ($target === null || $target === '') {
                    continue;
                }

                if (! in_array((string) $branch, $validBranches, true)) {
                    $errors[] = __('automations.validation.orphan_branch', ['uid' => $uid, 'branch' => (string) $branch]);
                }

                if (! isset($byUid[(string) $target])) {
                    $errors[] = __('automations.validation.dangling_edge', ['uid' => $uid, 'target' => (string) $target]);

                    continue;
                }

                $incoming[(string) $target] = ($incoming[(string) $target] ?? 0) + 1;
            }

            $errors = array_merge($errors, $this->configErrors($uid, $node['type'], $node['config'], $byUid));
        }

        foreach ($triggers as $uid) {
            if (($incoming[$uid] ?? 0) > 0) {
                $errors[] = __('automations.validation.trigger_root', ['uid' => $uid]);
            }
        }

        // Reachability from the trigger.
        if (count($triggers) === 1 && $errors === []) {
            $reachable = $this->reachable($triggers[0], $byUid);

            foreach (array_keys($byUid) as $uid) {
                if (! isset($reachable[$uid])) {
                    $errors[] = __('automations.validation.unreachable', ['uid' => $uid]);
                }
            }
        }

        return array_values(array_unique($errors));
    }

    /** @param array<string, mixed> $canvas
     * @return list<array<string, mixed>> */
    public function nodes(array $canvas): array
    {
        return array_values((array) ($canvas['nodes'] ?? []));
    }

    /**
     * @param  array<string, array{type: AutomationNodeType, config: array<string, mixed>, edges: array<string, mixed>}>  $byUid
     * @return array<string, true>
     */
    private function reachable(string $root, array $byUid): array
    {
        $seen = [$root => true];
        $queue = [$root];

        while ($queue !== []) {
            $uid = array_shift($queue);
            $node = $byUid[$uid];

            $targets = array_values(array_filter(array_map(
                fn ($target): string => (string) ($target ?? ''),
                $node['edges'],
            ), fn (string $target): bool => $target !== ''));

            // Jump-to-node keeps its target alive.
            if ($node['type'] === AutomationNodeType::LogicJump && ($node['config']['target'] ?? 'node') === 'node') {
                $target = (string) ($node['config']['target_node_uid'] ?? '');

                if ($target !== '') {
                    $targets[] = $target;
                }
            }

            foreach ($targets as $target) {
                if (isset($byUid[$target]) && ! isset($seen[$target])) {
                    $seen[$target] = true;
                    $queue[] = $target;
                }
            }
        }

        return $seen;
    }

    /**
     * Required-config checks per node type.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $byUid
     * @return list<string>
     */
    private function configErrors(string $uid, AutomationNodeType $type, array $config, array $byUid): array
    {
        $missing = [];

        $require = function (string $key) use ($config, &$missing): void {
            $value = $config[$key] ?? null;

            if ($value === null || $value === '' || $value === []) {
                $missing[] = $key;
            }
        };

        switch ($type) {
            case AutomationNodeType::TriggerTagAdded:
            case AutomationNodeType::TriggerTagRemoved:
                $require('tag');
                break;
            case AutomationNodeType::TriggerFieldChanged:
            case AutomationNodeType::TriggerDate:
                $require('list_field_id');
                break;
            case AutomationNodeType::TriggerCampaignNotOpened:
                $require('campaign_id');
                $require('days');
                break;
            case AutomationNodeType::TriggerSegmentEntered:
            case AutomationNodeType::TriggerSegmentExited:
                $require('segment_id');
                break;
            case AutomationNodeType::ActionSendEmail:
                $require('subject');

                if (($config['mode'] ?? 'inline') === 'template') {
                    $require('template_id');
                } else {
                    $require('content_html');
                }
                break;
            case AutomationNodeType::ActionNotifyTeam:
                $require('recipients');
                $require('subject');
                break;
            case AutomationNodeType::ActionAddTag:
            case AutomationNodeType::ActionRemoveTag:
                $require('tag');
                break;
            case AutomationNodeType::ActionUpdateField:
                $require('list_field_id');
                break;
            case AutomationNodeType::ActionList:
                $require('op');
                $require('list_id');
                break;
            case AutomationNodeType::ActionSuppress:
                if (($config['mode'] ?? 'blacklist') === 'suppression') {
                    $require('suppression_list_id');
                }
                break;
            case AutomationNodeType::ActionCrossAutomation:
                $require('op');
                $require('automation_id');
                break;
            case AutomationNodeType::ActionWebhook:
                $require('url');
                break;
                // WP-32 §2.10 F-2.10-012 — an SMS node without a body would
                // publish happily and then fail at run time on every subscriber.
            case AutomationNodeType::ActionSms:
                $require('body');
                break;
                // WP-46 §2.10. A WhatsApp node without a template can never
                // send: outside the 24-hour window the provider only accepts
                // an approved template, so the id is the one required key.
            case AutomationNodeType::ActionWhatsApp:
                $require('whatsapp_template_id');
                break;
                // A web-push node with no title renders as an empty
                // notification in every browser — refuse it at publish time.
            case AutomationNodeType::ActionWebPush:
                $require('title');
                break;
                // WP-44 §2.13 CRM. Same reasoning as the SMS node: a CRM node
                // missing its one required key would publish and then fail on
                // every contact that reached it.
            case AutomationNodeType::TriggerLeadScore:
                $require('threshold');
                break;
            case AutomationNodeType::ActionCrm:
                $require('op');

                if (($config['op'] ?? 'create_task') === 'update_deal_stage') {
                    $require('stage_id');
                } else {
                    $require('title');
                }
                break;
            case AutomationNodeType::ActionLeadScore:
                $require('op');
                $require('points');
                break;
            case AutomationNodeType::ActionAssignOwner:
                if (($config['mode'] ?? 'round_robin') === 'specific') {
                    $require('sub_user_id');
                }
                break;
            case AutomationNodeType::LogicWaitEvent:
                $require('event');
                $require('timeout_value');
                break;
            case AutomationNodeType::LogicIfElse:
            case AutomationNodeType::LogicGoal:
                $require('conditions');
                break;
            case AutomationNodeType::LogicSplit:
                $require('cases');
                break;
            case AutomationNodeType::LogicJump:
                if (($config['target'] ?? 'node') === 'automation') {
                    $require('target_automation_id');
                } else {
                    $target = (string) ($config['target_node_uid'] ?? '');

                    if ($target === '') {
                        $missing[] = 'target_node_uid';
                    } elseif (! isset($byUid[$target])) {
                        return [__('automations.validation.jump_target', ['uid' => $uid])];
                    }
                }
                break;
            case AutomationNodeType::LogicPercentage:
                $variants = array_values((array) ($config['variants'] ?? []));
                $sum = 0;

                foreach ($variants as $variant) {
                    $sum += (int) (($variant['percent'] ?? 0));
                }

                if (count($variants) < 2 || $sum !== 100) {
                    return [__('automations.validation.percentage_sum', ['uid' => $uid])];
                }
                break;
            default:
                break;
        }

        return $missing === []
            ? []
            : [__('automations.validation.missing_config', ['uid' => $uid, 'keys' => implode(', ', $missing)])];
    }
}
