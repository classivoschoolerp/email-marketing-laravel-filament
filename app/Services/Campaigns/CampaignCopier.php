<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\CampaignAttachment;
use App\Services\Settings\SettingsReader;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Copy/duplicate a campaign (spec §2.3.9 F-2.3.9-003): fresh uid, draft
 * status, zeroed delivery counters and share secret. Content and step-3
 * options can each be excluded ("with or without content/options"). Stats
 * are never copied — tracking rows key on campaign uid, and the copy gets
 * a new one by construction.
 */
class CampaignCopier
{
    /** Step-3 option columns cleared when copying "without options". */
    private const OPTION_RESETS = [
        'url_tracking_exceptions' => null,
        'utm_enabled' => false,
        'utm_params' => null,
        'tracking_domain_id' => null,
        'delivery_server_ids' => null,
        'max_emails' => null,
        'send_rate_per_hour' => null,
        'send_window_days' => null,
        'send_window_start' => null,
        'send_window_end' => null,
        'batch_size' => null,
        'batch_interval_seconds' => null,
        'suppression_list_ids' => null,
        'embed_images' => false,
        'plain_text_only' => false,
        'custom_headers' => null,
        'share_reports' => false,
        'notify_on_finish' => false,
        'notes' => null,
    ];

    public function __construct(private readonly CampaignQuota $quota) {}

    public function copy(
        Campaign $source,
        bool $withContent = true,
        bool $withOptions = true,
        ?string $name = null,
    ): Campaign {
        $owner = $source->customer()->withoutGlobalScopes()->first();

        if ($owner !== null) {
            $this->quota->assertCanCreate($owner);
        }

        $copy = $source->replicate(['uid', 'deleted_at']);
        $copy->uid = (string) Str::uuid();
        $copy->name = $name ?? Str::limit($source->name, 240, '').' '.__('campaigns.copy_suffix');
        $copy->status = CampaignStatus::Draft;

        // Never carried over: run state, counters, share secret.
        $copy->forceFill([
            'share_reports_secret' => null,
            'rec_occurrences' => 0,
            'next_run_at' => null,
            'delivery_total' => 0,
            'delivery_sent' => 0,
            'delivery_failed' => 0,
            'send_started_at' => null,
            'send_finished_at' => null,
        ]);

        if (! $withContent) {
            $copy->forceFill([
                'template_id' => null,
                'builder' => null,
                'content_html' => null,
                'content_json' => null,
                'plain_text' => null,
            ]);
        }

        if (! $withOptions) {
            $settings = SettingsReader::campaign();
            $copy->forceFill(self::OPTION_RESETS + [
                'track_opens' => $settings->defaultOpenTracking(),
                'track_clicks' => $settings->defaultClickTracking(),
                'auto_plain_text' => $settings->defaultPlainTextGeneration(),
            ]);
        }

        $copy->save();

        if ($withContent) {
            $this->copyAssets($source, $copy);
        }

        if ($withOptions) {
            $this->copyAttachments($source, $copy);
        }

        return $copy->refresh();
    }

    private function copyAssets(Campaign $source, Campaign $copy): void
    {
        $disk = Storage::disk('public');
        $sourceDir = $source->assetDirectory();
        $copyDir = $copy->assetDirectory();

        foreach ($disk->allFiles($sourceDir) as $file) {
            if (str_starts_with(Str::after($file, $sourceDir), '/attachments')) {
                continue; // attachments are copied separately, options-gated
            }

            $disk->copy($file, $copyDir.Str::after($file, $sourceDir));
        }

        foreach (['content_html', 'content_json'] as $field) {
            $value = $copy->getAttribute($field);

            if (is_string($value) && $value !== '') {
                $copy->setAttribute($field, str_replace($sourceDir, $copyDir, $value));
            }
        }

        $copy->saveQuietly();
    }

    private function copyAttachments(Campaign $source, Campaign $copy): void
    {
        $disk = Storage::disk('public');

        foreach ($source->attachments()->get() as $attachment) {
            /** @var CampaignAttachment $attachment */
            $newPath = str_replace($source->assetDirectory(), $copy->assetDirectory(), $attachment->path);

            if ($disk->exists($attachment->path)) {
                $disk->copy($attachment->path, $newPath);
            }

            $copy->attachments()->create([
                'customer_id' => $copy->customer_id,
                'file_name' => $attachment->file_name,
                'path' => $newPath,
                'mime_type' => $attachment->mime_type,
                'size' => $attachment->size,
            ]);
        }
    }
}
