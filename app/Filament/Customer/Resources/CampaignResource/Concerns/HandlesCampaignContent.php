<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\CampaignResource\Concerns;

use App\Enums\CampaignStatus;
use App\Filament\Customer\Resources\EmailTemplateResource;
use App\Models\Campaign;
use App\Models\EmailList;
use App\Models\EmailTemplate;
use App\Services\Campaigns\AbTest\AbTestManager;
use App\Services\Campaigns\CampaignRevisions;
use App\Services\Campaigns\CampaignScheduler;
use App\Services\Campaigns\CampaignTagger;
use App\Services\Templates\TemplateUrlImporter;
use App\Services\Templates\TemplateZipImporter;
use App\Services\Templates\UnsubscribeTagChecker;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Shared Create/Edit page plumbing for the content step (spec §2.3.3):
 * choose-source application (templates / gallery / previous campaign /
 * blank / ZIP upload via the WP-10 TemplateZipImporter), attachment row
 * syncing (§2.3.4 F-2.3.4-015), share-secret generation (F-2.3.4-020) and
 * the confirmation-step Schedule/Save-draft action (§2.3.5).
 */
trait HandlesCampaignContent
{
    private ?string $pendingZipPath = null;

    /** @var list<string>|null null = attachments field absent from the form */
    private ?array $pendingAttachmentPaths = null;

    private ?string $confirmAction = null;

    /**
     * Pull the file-upload + confirmation state out of the dehydrated data
     * (their values live in columns of their own, not on campaigns).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function stashTransientState(array $data): array
    {
        $zip = $data['zip_upload'] ?? null;
        $this->pendingZipPath = is_array($zip) ? (array_values($zip)[0] ?? null) : (is_string($zip) ? $zip : null);
        unset($data['zip_upload']);

        if (array_key_exists('attachments_upload', $data)) {
            $paths = $data['attachments_upload'];
            $this->pendingAttachmentPaths = is_array($paths)
                ? array_values(array_filter($paths, 'is_string'))
                : [];
            unset($data['attachments_upload']);
        }

        $this->confirmAction = is_string($this->data['confirm_action'] ?? null)
            ? $this->data['confirm_action']
            : null;

        return $data;
    }

    /**
     * Apply the chosen content source (F-2.3.3-001): my templates / system
     * gallery / a previous campaign / blank starter. ZIP handling happens
     * after save (the record must exist for its asset directory).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyContentSource(array $data): array
    {
        $source = $this->data['content_source'] ?? null;

        if (in_array($source, ['template', 'gallery'], true)) {
            $template = EmailTemplate::browsable()->find((int) ($this->data['source_template_id'] ?? 0));

            if ($template instanceof EmailTemplate) {
                $data['content_html'] = $template->content_html;
                $data['content_json'] = $template->content_json;
                $data['builder'] = $template->builder?->value ?? ($data['builder'] ?? null);
                $data['template_id'] = $template->id;
            }
        } elseif ($source === 'campaign') {
            $sourceCampaign = Campaign::query()->find((int) ($this->data['source_campaign_id'] ?? 0));

            if ($sourceCampaign instanceof Campaign) {
                $data['content_html'] = $sourceCampaign->content_html;
                $data['content_json'] = $sourceCampaign->content_json;
                $data['builder'] = $sourceCampaign->builder?->value ?? ($data['builder'] ?? null);
                $data['plain_text'] = $sourceCampaign->plain_text;
            }
        } elseif ($source === 'blank' && trim((string) ($data['content_html'] ?? '')) === '') {
            $data['content_html'] = EmailTemplateResource::starterHtml();
        }

        return $data;
    }

    /** ZIP upload → TemplateZipImporter into the campaign (F-2.3.3-005). */
    private function importPendingZip(Campaign $campaign): void
    {
        // URL source: fetch through the WP-10 URL importer (same row).
        if (($this->data['content_source'] ?? null) === 'url') {
            $url = $this->data['source_url'] ?? null;

            if (is_string($url) && trim($url) !== '') {
                try {
                    app(TemplateUrlImporter::class)->import($campaign, trim($url));
                } catch (ValidationException $e) {
                    Notification::make()->danger()
                        ->title(collect($e->errors())->flatten()->first())
                        ->persistent()
                        ->send();
                }
            }

            return;
        }

        if ($this->pendingZipPath === null || ($this->data['content_source'] ?? null) !== 'upload') {
            return;
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($this->pendingZipPath)) {
            return;
        }

        try {
            app(TemplateZipImporter::class)->import($campaign, $disk->path($this->pendingZipPath));
        } finally {
            $disk->delete($this->pendingZipPath);
        }
    }

    /**
     * Reconcile campaign_attachments rows with the FileUpload state
     * (F-2.3.4-015). Removed rows delete their file via the model event.
     */
    private function syncAttachments(Campaign $campaign): void
    {
        if ($this->pendingAttachmentPaths === null) {
            return; // field not present (attachments disabled)
        }

        $paths = $this->pendingAttachmentPaths;
        $disk = Storage::disk('public');

        $campaign->attachments()
            ->whereNotIn('path', $paths === [] ? [''] : $paths)
            ->get()
            ->each(fn ($attachment) => $attachment->delete());

        $existing = $campaign->attachments()->pluck('path')->all();

        foreach ($paths as $path) {
            if (in_array($path, $existing, true) || ! $disk->exists($path)) {
                continue;
            }

            $campaign->attachments()->create([
                'customer_id' => $campaign->customer_id,
                'file_name' => basename($path),
                'path' => $path,
                'mime_type' => $disk->mimeType($path) ?: null,
                'size' => $disk->size($path),
            ]);
        }
    }

    /** Secret share-URL generation when sharing is on (F-2.3.4-020). */
    private function ensureShareSecret(Campaign $campaign): void
    {
        if ($campaign->share_reports && $campaign->share_reports_secret === null) {
            $campaign->forceFill(['share_reports_secret' => Str::random(40)])->saveQuietly();
        }
    }

    /** Confirmation step outcome (F-2.3.5-009/010). */
    private function applyConfirmAction(Campaign $campaign): void
    {
        if ($this->confirmAction !== 'schedule') {
            return;
        }

        try {
            $status = app(CampaignScheduler::class)->scheduleOrSend($campaign);

            Notification::make()
                ->success()
                ->title($status === CampaignStatus::PendingApproval
                    ? __('campaigns.notifications.pending_approval')
                    : __('campaigns.notifications.scheduled'))
                ->send();
        } catch (ValidationException $e) {
            Notification::make()
                ->danger()
                ->title(collect($e->errors())->flatten()->first())
                ->persistent()
                ->send();
        }
    }

    /**
     * WP-35 §2.3.2 — persist the two form-only targeting fields:
     * additional target lists (F-2.3.2-006, re-fenced to the tenant) and
     * free-form tags (F-2.3.2-004). Both live in their own tables, so they
     * are dehydrated out of the campaign payload and applied here.
     *
     * §2.3.6 A/B invariants (labels, positions, weights, cleared overrides)
     * are re-established at the same point, after the variant repeater has
     * written its rows.
     */
    private function syncCampaignRelations(Campaign $campaign): void
    {
        if (array_key_exists('extra_list_ids', $this->data)) {
            $ids = array_values(array_filter(
                array_map(intval(...), (array) $this->data['extra_list_ids']),
                fn (int $id): bool => $id !== (int) $campaign->email_list_id,
            ));

            $campaign->targetLists()->sync(
                EmailList::query()->whereIn('id', $ids)->pluck('id')->all()
            );
        }

        if (array_key_exists('campaign_tags', $this->data)) {
            app(CampaignTagger::class)
                ->sync($campaign, array_values(array_filter(
                    (array) $this->data['campaign_tags'],
                    is_string(...),
                )));
        }

        $manager = app(AbTestManager::class);

        if ($campaign->ab_enabled) {
            $manager->ensureVariants($campaign);
        }

        $manager->normalize($campaign);
    }

    /** F-2.3.3-019 — record a content version on every content-bearing save. */
    private function recordCampaignRevision(Campaign $campaign): void
    {
        if (trim((string) $campaign->content_html) !== '') {
            app(CampaignRevisions::class)->record($campaign);
        }
    }

    private function warnOnMissingUnsubscribe(Campaign $campaign): void
    {
        if (
            $campaign->content_html !== null
            && trim($campaign->content_html) !== ''
            && ! app(UnsubscribeTagChecker::class)->passes($campaign->content_html)
        ) {
            Notification::make()
                ->warning()
                ->title(__('campaigns.warnings.missing_unsubscribe'))
                ->send();
        }
    }
}
