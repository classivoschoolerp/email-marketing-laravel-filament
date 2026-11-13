<?php

declare(strict_types=1);

use App\Settings\CampaignSettings;
use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('campaign', function (SettingsBlueprint $blueprint): void {
            // F-3.25.3-001
            $blueprint->add('attachments_enabled', true);
            $blueprint->add('attachment_max_size_mb', 10);
            $blueprint->add('attachment_max_count', 5);
            $blueprint->add('attachment_allowed_mime_types', [
                'application/pdf',
                'image/jpeg',
                'image/png',
                'image/gif',
                'text/plain',
                'application/zip',
            ]);
            $blueprint->add('attachment_allowed_extensions', [
                'pdf', 'jpg', 'jpeg', 'png', 'gif', 'txt', 'zip',
            ]);

            // F-3.25.3-002
            $blueprint->add('enabled_template_tags', CampaignSettings::KNOWN_TEMPLATE_TAGS);

            // F-3.25.3-003
            $blueprint->add('subject_blacklisted_words', []);
            $blueprint->add('content_blacklisted_words', []);
            $blueprint->add('blacklisted_words_action', CampaignSettings::BLACKLISTED_WORDS_ACTION_BLOCK);

            // F-3.25.3-004
            $blueprint->add('forbidden_from_domains', []);
            $blueprint->add('forbidden_reply_to_domains', []);

            // F-3.25.3-005
            $blueprint->add('webhooks_enabled', false);
            $blueprint->add('webhook_endpoints', []);

            // F-3.25.3-006
            $blueprint->add('default_open_tracking', true);
            $blueprint->add('default_click_tracking', true);

            // F-3.25.3-007
            $blueprint->add('max_campaign_recipients', 0);

            // F-3.25.3-008
            $blueprint->add('approval_required', false);

            // F-3.25.3-009
            $blueprint->add('send_at_min_offset_minutes', 0);

            // F-3.25.3-010
            $blueprint->add('bounce_auto_pause_percent', 5.0);
            $blueprint->add('complaint_auto_pause_percent', 0.5);

            // F-3.25.3-011
            $blueprint->add('default_plain_text_generation', true);

            // F-3.25.3-012
            $blueprint->add('retry_max_attempts', 3);
            $blueprint->add('retry_backoff_seconds', 300);
        });
    }
};
