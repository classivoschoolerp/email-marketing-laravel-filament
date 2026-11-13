<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AutoresponderDelayUnit;
use App\Enums\AutoresponderTrigger;
use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Enums\RecurringInterval;
use App\Enums\TemplateBuilder;
use App\Models\Concerns\BelongsToCustomer;
use App\Observers\CampaignApprovalObserver;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Campaign (spec §2.3, WP-11/12). Tenant-scoped via BelongsToCustomer.
 * Status changes go through App\Services\Campaigns\CampaignStatusMachine
 * only. Delivery counters (delivery_total/sent/failed) belong to the WP-13
 * sending engine — zeros until then.
 *
 * WP-18: CampaignApprovalObserver watches status updates and queues the
 * §3.15 approval-request mail when a campaign enters pending-approval.
 */
#[ObservedBy(CampaignApprovalObserver::class)]
class Campaign extends Model
{
    use BelongsToCustomer;

    /** @use HasFactory<CampaignFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => CampaignType::class,
            'status' => CampaignStatus::class,
            'builder' => TemplateBuilder::class,
            'ar_trigger' => AutoresponderTrigger::class,
            'ar_delay_unit' => AutoresponderDelayUnit::class,
            'rec_interval' => RecurringInterval::class,
            'send_at' => 'datetime',
            'send_immediately' => 'boolean',
            'ar_once_per_subscriber' => 'boolean',
            'rec_days_of_week' => 'array',
            'rec_starts_at' => 'datetime',
            'rec_ends_at' => 'datetime',
            'next_run_at' => 'datetime',
            'auto_plain_text' => 'boolean',
            'track_opens' => 'boolean',
            'track_clicks' => 'boolean',
            'url_tracking_exceptions' => 'array',
            'utm_enabled' => 'boolean',
            'utm_params' => 'array',
            // WP-45 §2.3.4 F-2.3.4-024 — goal tracking / conversion pixel.
            // `goal_value` is integer MINOR UNITS (docs/ARCHITECTURE.md money rule).
            'goal_tracking_enabled' => 'boolean',
            'goal_value' => 'integer',
            'goal_window_days' => 'integer',
            'delivery_server_ids' => 'array',
            'send_window_days' => 'array',
            'suppression_list_ids' => 'array',
            'embed_images' => 'boolean',
            'plain_text_only' => 'boolean',
            'custom_headers' => 'array',
            'share_reports' => 'boolean',
            'notify_on_finish' => 'boolean',
            'send_started_at' => 'datetime',
            'send_finished_at' => 'datetime',

            // WP-35 Phase-2 options (§2.3.6 / §2.3.7 / §2.3.4 / §2.3.9).
            'ab_enabled' => 'boolean',
            'ab_variables' => 'array',
            'ab_auto_send_winner' => 'boolean',
            'ab_test_finished_at' => 'datetime',
            'ab_winner_decided_at' => 'datetime',
            'send_at_recipient_local_time' => 'boolean',
            // WP-46 §2.3.4 F-2.3.4-011 — Send Time Optimization.
            'send_time_optimized' => 'boolean',
            'webhook_events' => 'array',
            'archived_at' => 'datetime',
            'is_starred' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $campaign): void {
            $campaign->uid ??= (string) Str::uuid();
        });
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(CampaignGroup::class, 'campaign_group_id');
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(EmailList::class, 'email_list_id');
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(ListSegment::class, 'list_segment_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'template_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(CampaignAttachment::class);
    }

    /* ================================================================ *
     *  WP-35 Phase-2 relations (§2.3.6 / §2.3.7 / §2.3.2 / §2.3.3)
     * ================================================================ */

    /** A/B test variants, always in composer/report order (§2.3.6). */
    public function variants(): HasMany
    {
        return $this->hasMany(CampaignVariant::class)->orderBy('position')->orderBy('id');
    }

    /** §2.3.7 recipient filters, in the order the customer arranged them. */
    public function filters(): HasMany
    {
        return $this->hasMany(CampaignFilter::class)->orderBy('position')->orderBy('id');
    }

    /** §2.3.2 F-2.3.2-004 free-form tags. */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(CampaignTag::class, 'campaign_campaign_tag');
    }

    /**
     * §2.3.2 F-2.3.2-006 EXTRA target lists. The primary list stays on
     * `email_list_id` (see the campaign_target_lists migration).
     */
    public function targetLists(): BelongsToMany
    {
        return $this->belongsToMany(EmailList::class, 'campaign_target_lists', 'campaign_id', 'email_list_id');
    }

    /** §2.3.3 F-2.3.3-019 content version history. */
    public function revisions(): HasMany
    {
        return $this->hasMany(CampaignRevision::class);
    }

    public function winnerVariant(): BelongsTo
    {
        return $this->belongsTo(CampaignVariant::class, 'ab_winner_variant_id');
    }

    /**
     * Every list this campaign targets, primary first (F-2.3.2-006).
     *
     * @return list<int>
     */
    public function targetListIds(): array
    {
        $ids = [];

        if ($this->email_list_id !== null) {
            $ids[] = (int) $this->email_list_id;
        }

        // The relation accessor (not the query builder) so a transient wizard
        // campaign can have its target lists set with setRelation().
        foreach ($this->targetLists as $list) {
            $ids[] = (int) $list->id;
        }

        return array_values(array_unique($ids));
    }

    /** F-2.3.9-006: archived campaigns are hidden from the default index. */
    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** Wizard may still edit? (§2.3.9 — edit until sending.) */
    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * Asset directory on the public disk (spec §0.2 isolation):
     * customers/{customer uid}/campaigns/{campaign uid}.
     */
    public function assetDirectory(): string
    {
        $customerUid = $this->customer()->withoutGlobalScopes()->first()?->uid ?? 'unknown';

        return 'customers/'.$customerUid.'/campaigns/'.$this->uid;
    }

    /** Public share-report URL when sharing is enabled (F-2.3.4-020). */
    public function shareReportUrl(): ?string
    {
        if (! $this->share_reports || $this->share_reports_secret === null) {
            return null;
        }

        return route('campaigns.public.report', ['secret' => $this->share_reports_secret]);
    }

    /** Send progress percentage from the WP-13 delivery counters. */
    public function sendProgressPercent(): int
    {
        if ((int) $this->delivery_total === 0) {
            return 0;
        }

        return (int) min(100, round(
            ((int) $this->delivery_sent + (int) $this->delivery_failed) * 100 / (int) $this->delivery_total
        ));
    }

    /**
     * ETA of the running send in seconds, from the observed send rate
     * (F-2.3.1-003, WP-13). Null when not sending / nothing processed yet.
     */
    public function sendEtaSeconds(): ?int
    {
        if ($this->status !== CampaignStatus::Sending || $this->send_started_at === null) {
            return null;
        }

        $done = (int) $this->delivery_sent + (int) $this->delivery_failed;
        $remaining = (int) $this->delivery_total - $done;

        if ($done < 1 || $remaining <= 0) {
            return null;
        }

        $elapsed = max(1, (int) abs($this->send_started_at->diffInSeconds(now())));

        return (int) ceil($remaining * $elapsed / $done);
    }
}
