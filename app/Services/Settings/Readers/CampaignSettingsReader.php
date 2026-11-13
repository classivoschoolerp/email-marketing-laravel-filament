<?php

declare(strict_types=1);

namespace App\Services\Settings\Readers;

use App\Settings\CampaignSettings;

/**
 * Typed accessors for the `campaign` settings group (spec §3.25.3).
 */
final readonly class CampaignSettingsReader
{
    public function __construct(private CampaignSettings $settings) {}

    public function attachmentsEnabled(): bool
    {
        return $this->settings->attachments_enabled;
    }

    public function maxAttachmentSizeMb(): int
    {
        return $this->settings->attachment_max_size_mb;
    }

    public function maxAttachmentCount(): int
    {
        return $this->settings->attachment_max_count;
    }

    /**
     * @return list<string>
     */
    public function attachmentAllowedMimeTypes(): array
    {
        return $this->settings->attachment_allowed_mime_types;
    }

    /**
     * @return list<string>
     */
    public function attachmentAllowedExtensions(): array
    {
        return $this->settings->attachment_allowed_extensions;
    }

    /**
     * @return list<string>
     */
    public function enabledTemplateTags(): array
    {
        return $this->settings->enabled_template_tags;
    }

    public function templateTagEnabled(string $tag): bool
    {
        return in_array($tag, $this->settings->enabled_template_tags, true);
    }

    /**
     * @return list<string>
     */
    public function subjectBlacklistedWords(): array
    {
        return $this->settings->subject_blacklisted_words;
    }

    /**
     * @return list<string>
     */
    public function contentBlacklistedWords(): array
    {
        return $this->settings->content_blacklisted_words;
    }

    /**
     * One of: block | flag.
     */
    public function blacklistedWordsAction(): string
    {
        return $this->settings->blacklisted_words_action;
    }

    public function blacklistedWordsBlock(): bool
    {
        return $this->settings->blacklisted_words_action === CampaignSettings::BLACKLISTED_WORDS_ACTION_BLOCK;
    }

    /**
     * @return list<string>
     */
    public function forbiddenFromDomains(): array
    {
        return $this->settings->forbidden_from_domains;
    }

    /**
     * @return list<string>
     */
    public function forbiddenReplyToDomains(): array
    {
        return $this->settings->forbidden_reply_to_domains;
    }

    public function webhooksEnabled(): bool
    {
        return $this->settings->webhooks_enabled;
    }

    /**
     * @return list<string>
     */
    public function webhookEndpoints(): array
    {
        return $this->settings->webhook_endpoints;
    }

    public function defaultOpenTracking(): bool
    {
        return $this->settings->default_open_tracking;
    }

    public function defaultClickTracking(): bool
    {
        return $this->settings->default_click_tracking;
    }

    /**
     * 0 = unlimited.
     */
    public function maxCampaignRecipients(): int
    {
        return $this->settings->max_campaign_recipients;
    }

    public function approvalRequired(): bool
    {
        return $this->settings->approval_required;
    }

    public function sendAtMinOffsetMinutes(): int
    {
        return $this->settings->send_at_min_offset_minutes;
    }

    public function bounceAutoPausePercent(): float
    {
        return $this->settings->bounce_auto_pause_percent;
    }

    public function complaintAutoPausePercent(): float
    {
        return $this->settings->complaint_auto_pause_percent;
    }

    public function defaultPlainTextGeneration(): bool
    {
        return $this->settings->default_plain_text_generation;
    }

    public function retryMaxAttempts(): int
    {
        return $this->settings->retry_max_attempts;
    }

    public function retryBackoffSeconds(): int
    {
        return $this->settings->retry_backoff_seconds;
    }
}
