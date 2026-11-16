<?php

declare(strict_types=1);

namespace App\Services\Automations;

use App\Enums\AutomationNodeType;
use App\Services\Automations\Executors\AssignOwnerExecutor;
use App\Services\Automations\Executors\CrmExecutor;
use App\Services\Automations\Executors\CrossAutomationExecutor;
use App\Services\Automations\Executors\LeadScoreExecutor;
use App\Services\Automations\Executors\DelayExecutor;
use App\Services\Automations\Executors\ExitExecutor;
use App\Services\Automations\Executors\FrequencyGuardExecutor;
use App\Services\Automations\Executors\GoalExecutor;
use App\Services\Automations\Executors\IfElseExecutor;
use App\Services\Automations\Executors\JumpExecutor;
use App\Services\Automations\Executors\ListActionExecutor;
use App\Services\Automations\Executors\NodeExecutor;
use App\Services\Automations\Executors\NotifyTeamExecutor;
use App\Services\Automations\Executors\PercentageExecutor;
use App\Services\Automations\Executors\SendEmailExecutor;
use App\Services\Automations\Executors\SmsExecutor;
use App\Services\Automations\Executors\SplitExecutor;
use App\Services\Automations\Executors\SuppressExecutor;
use App\Services\Automations\Executors\TagExecutor;
use App\Services\Automations\Executors\TriggerExecutor;
use App\Services\Automations\Executors\UpdateFieldExecutor;
use App\Services\Automations\Executors\WaitEventExecutor;
use App\Services\Automations\Executors\WebhookExecutor;
use App\Services\Automations\Executors\WebPushExecutor;
use App\Services\Automations\Executors\WhatsAppExecutor;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Node type → executor mapping (WP-21).
 *
 * WP-36 (§6.1 F-6.1-013 "Automation trigger & action types"): this is now a
 * real registry in the docs/ARCHITECTURE.md rule-2 sense. A plugin calls register() from
 * its service provider to
 *
 *   - REPLACE the executor for a built-in node type (e.g. its own send-email
 *     implementation), or
 *   - PUBLISH a brand-new type key ("action.acme.crm_sync") that for()
 *     resolves.
 *
 * Registered executors always win; the built-in match below is the fallback,
 * so with no plugins installed behaviour is identical to WP-21.
 *
 * Bound as a singleton from routes/modules/plugins.php (D-41/D-51) so those
 * registrations survive for the whole process.
 *
 * Known limitation, stated plainly rather than papered over: `automation_nodes.type`
 * casts to the closed `AutomationNodeType` enum, and GraphValidator/NodeForms
 * switch over the same enum. A plugin-published type is therefore resolvable
 * and executable, but a *persisted* graph cannot yet reference one — see
 * docs/plugins.md §Known limits for the exact change that would open it up.
 */
class ExecutorRegistry
{
    /** @var array<string, class-string<NodeExecutor>|NodeExecutor> */
    private array $registered = [];

    public function __construct(private readonly Container $container) {}

    /**
     * Plugin-facing registration API (F-6.1-013).
     *
     * @param  class-string<NodeExecutor>|NodeExecutor  $executor
     */
    public function register(AutomationNodeType|string $type, string|NodeExecutor $executor): void
    {
        if (is_string($executor) && ! is_subclass_of($executor, NodeExecutor::class)) {
            throw new InvalidArgumentException(
                "Automation executor [{$executor}] must implement ".NodeExecutor::class.'.'
            );
        }

        $this->registered[$this->key($type)] = $executor;
    }

    public function has(AutomationNodeType|string $type): bool
    {
        return isset($this->registered[$this->key($type)]);
    }

    /** @return list<string> plugin-registered type keys only */
    public function keys(): array
    {
        return array_keys($this->registered);
    }

    public function for(AutomationNodeType|string $type): NodeExecutor
    {
        $key = $this->key($type);

        if (isset($this->registered[$key])) {
            $executor = $this->registered[$key];

            return $executor instanceof NodeExecutor ? $executor : $this->container->make($executor);
        }

        $type = $type instanceof AutomationNodeType
            ? $type
            : (AutomationNodeType::tryFrom($key)
                ?? throw new InvalidArgumentException("Unknown automation node type [{$key}]."));

        if ($type->isTrigger()) {
            return $this->container->make(TriggerExecutor::class);
        }

        $class = match ($type) {
            AutomationNodeType::ActionSendEmail => SendEmailExecutor::class,
            AutomationNodeType::ActionNotifyTeam => NotifyTeamExecutor::class,
            AutomationNodeType::ActionAddTag,
            AutomationNodeType::ActionRemoveTag => TagExecutor::class,
            AutomationNodeType::ActionUpdateField => UpdateFieldExecutor::class,
            AutomationNodeType::ActionList => ListActionExecutor::class,
            AutomationNodeType::ActionSuppress => SuppressExecutor::class,
            AutomationNodeType::ActionCrossAutomation => CrossAutomationExecutor::class,
            AutomationNodeType::ActionWebhook => WebhookExecutor::class,
            // WP-32 §2.10 F-2.10-012 — multichannel journey node.
            AutomationNodeType::ActionSms => SmsExecutor::class,
            // WP-46 §2.10 — F-2.4.2-022 / F-2.4.2-023.
            AutomationNodeType::ActionWhatsApp => WhatsAppExecutor::class,
            AutomationNodeType::ActionWebPush => WebPushExecutor::class,
            // WP-44 §2.13 CRM — F-2.4.2-031, -032, -033.
            AutomationNodeType::ActionCrm => CrmExecutor::class,
            AutomationNodeType::ActionLeadScore => LeadScoreExecutor::class,
            AutomationNodeType::ActionAssignOwner => AssignOwnerExecutor::class,
            AutomationNodeType::LogicDelay => DelayExecutor::class,
            AutomationNodeType::LogicWaitEvent => WaitEventExecutor::class,
            AutomationNodeType::LogicIfElse => IfElseExecutor::class,
            AutomationNodeType::LogicSplit => SplitExecutor::class,
            AutomationNodeType::LogicPercentage => PercentageExecutor::class,
            AutomationNodeType::LogicGoal => GoalExecutor::class,
            AutomationNodeType::LogicExit => ExitExecutor::class,
            AutomationNodeType::LogicJump => JumpExecutor::class,
            AutomationNodeType::LogicFrequencyGuard => FrequencyGuardExecutor::class,
            default => TriggerExecutor::class,
        };

        return $this->container->make($class);
    }

    private function key(AutomationNodeType|string $type): string
    {
        return $type instanceof AutomationNodeType ? $type->value : $type;
    }
}
