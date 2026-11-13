<?php

declare(strict_types=1);

namespace App\Jobs\Campaigns;

use App\Enums\CampaignStatus;
use App\Jobs\Concerns\IdempotentJob;
use App\Mail\CampaignApprovedMail;
use App\Mail\CampaignBlockedMail;
use App\Mail\CampaignChangesRequestedMail;
use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Campaigns\CampaignStatusMachine;
use App\Support\Queue\Queues;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * Queued campaign bulk actions (Part 8 F-Phase-3-Differentiat-006, docs/ARCHITECTURE.md
 * rule 5 — "nothing bulk runs synchronously").
 *
 * Closes the WP-25 audit deviation: the customer campaigns index and the
 * admin campaigns overview flipped statuses inline inside the Livewire
 * request. Both now hand the selection to this job.
 *
 * Division of labour with D-28 (Filament v3.3 bulk actions run no per-record
 * policies):
 *   • the CALLER filters the selection with the actor's policy — a queued job
 *     has no authenticated actor, so authorization must never move in here;
 *   • the JOB re-checks CampaignStatusMachine before every flip, because the
 *     status may legitimately have moved between queueing and running (the
 *     WP-13 send wave flips sending → sent constantly). Rows the machine
 *     forbids are skipped, exactly as they were inline.
 *
 * D-57: every status change goes through CampaignStatusMachine — resume
 * still means "paused → sending iff send_started_at, else → scheduled".
 */
class BulkCampaignActionJob implements ShouldQueue
{
    use Dispatchable;
    use IdempotentJob;
    use InteractsWithQueue;
    use Queueable;

    public const ACTION_PAUSE = 'pause';

    public const ACTION_RESUME = 'resume';

    public const ACTION_BLOCK = 'block';

    public const ACTION_DELETE = 'delete';

    /** §3.7 F-3.7-004 approval queue: pending-approval → scheduled. */
    public const ACTION_APPROVE = 'approve';

    /** §3.7 F-3.7-004 approval queue: pending-approval → draft, with a reason. */
    public const ACTION_REQUEST_CHANGES = 'request-changes';

    public const ACTIONS = [
        self::ACTION_PAUSE,
        self::ACTION_RESUME,
        self::ACTION_BLOCK,
        self::ACTION_DELETE,
        self::ACTION_APPROVE,
        self::ACTION_REQUEST_CHANGES,
    ];

    /**
     * @param  list<int>  $campaignIds  rows the caller already authorized (D-28)
     * @param  int|null  $customerId  tenant context; NULL = operator/admin bulk
     * @param  string|null  $blockReason  §3.15 blocked-mail reason (block only;
     *                                    reused as the F-3.7-004 "changes
     *                                    requested" reason)
     * @param  bool  $notifyCustomer  send the F-3.15-007 blocked / F-3.7-004
     *                                approval mails (admin only)
     */
    public function __construct(
        public readonly string $action,
        public readonly array $campaignIds,
        public readonly ?int $customerId,
        public readonly string $operationId,
        public readonly ?string $blockReason = null,
        public readonly bool $notifyCustomer = false,
    ) {
        $this->onQueue(Queues::CAMPAIGNS_HIGH);
    }

    public function handle(CurrentCustomer $current): void
    {
        if (! in_array($this->action, self::ACTIONS, true) || $this->campaignIds === []) {
            return;
        }

        if ($this->customerId === null) {
            $this->run();

            return;
        }

        $customer = Customer::query()->withoutGlobalScopes()->find($this->customerId);

        if ($customer === null) {
            return;
        }

        // Tenant context so model events, scopes and observers behave exactly
        // as they did when the action ran inline in the panel request.
        $current->runAs($customer, fn () => $this->run());
    }

    private function run(): void
    {
        $machine = app(CampaignStatusMachine::class);

        foreach (array_chunk($this->campaignIds, 200) as $chunk) {
            $query = Campaign::withoutTenancy()->whereKey($chunk);

            // Belt and braces: a tenant bulk may only ever touch its own rows,
            // even if a caller passed a foreign id.
            if ($this->customerId !== null) {
                $query->where('customer_id', $this->customerId);
            }

            foreach ($query->get() as $campaign) {
                $this->apply($machine, $campaign);
            }
        }
    }

    private function apply(CampaignStatusMachine $machine, Campaign $campaign): void
    {
        match ($this->action) {
            self::ACTION_PAUSE => $machine->canPause($campaign)
                ? $machine->transition($campaign, CampaignStatus::Paused)
                : null,
            self::ACTION_RESUME => $machine->canResume($campaign)
                ? $machine->resume($campaign)
                : null,
            self::ACTION_BLOCK => $machine->canTransition($campaign->status, CampaignStatus::Blocked)
                ? $this->block($machine, $campaign)
                : null,
            self::ACTION_DELETE => $campaign->delete(),
            // F-3.7-004: both approval-queue verbs re-check the status here,
            // because an admin may have acted on one row between queueing and
            // running — a campaign that is no longer pending-approval must not
            // be dragged back into the flow.
            self::ACTION_APPROVE => $campaign->status === CampaignStatus::PendingApproval
                && $machine->canTransition($campaign->status, CampaignStatus::Scheduled)
                ? $this->approve($machine, $campaign)
                : null,
            self::ACTION_REQUEST_CHANGES => $campaign->status === CampaignStatus::PendingApproval
                && $machine->canTransition($campaign->status, CampaignStatus::Draft)
                ? $this->requestChanges($machine, $campaign)
                : null,
            default => null,
        };
    }

    private function approve(CampaignStatusMachine $machine, Campaign $campaign): void
    {
        $machine->transition($campaign, CampaignStatus::Scheduled);

        $this->mailOwner($campaign, fn (string $name): CampaignApprovedMail => new CampaignApprovedMail($name));
    }

    private function requestChanges(CampaignStatusMachine $machine, Campaign $campaign): void
    {
        $machine->transition($campaign, CampaignStatus::Draft);

        $this->mailOwner(
            $campaign,
            fn (string $name): CampaignChangesRequestedMail => new CampaignChangesRequestedMail(
                $name,
                $this->blockReason !== null && trim($this->blockReason) !== '' ? $this->blockReason : null,
            ),
        );
    }

    /**
     * @param  callable(string): (CampaignApprovedMail|CampaignChangesRequestedMail)  $factory
     */
    private function mailOwner(Campaign $campaign, callable $factory): void
    {
        if (! $this->notifyCustomer) {
            return;
        }

        $email = $campaign->customer()->withoutGlobalScopes()->first()?->email;

        if ($email === null || $email === '') {
            return;
        }

        Mail::to((string) $email)->queue($factory((string) $campaign->name));
    }

    private function block(CampaignStatusMachine $machine, Campaign $campaign): void
    {
        $machine->transition($campaign, CampaignStatus::Blocked);

        if (! $this->notifyCustomer) {
            return;
        }

        $email = $campaign->customer()->withoutGlobalScopes()->first()?->email;

        if ($email === null || $email === '') {
            return;
        }

        Mail::to((string) $email)->queue(new CampaignBlockedMail(
            (string) $campaign->name,
            $this->blockReason !== null && trim($this->blockReason) !== ''
                ? $this->blockReason
                : null,
        ));
    }

    public function idempotencyKey(): string
    {
        return 'campaigns-bulk:'.$this->action.':'.$this->operationId;
    }
}
