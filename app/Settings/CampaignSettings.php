<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Campaign settings (spec §3.25.3).
 */
class CampaignSettings extends Settings
{
    public const BLACKLISTED_WORDS_ACTION_BLOCK = 'block';

    public const BLACKLISTED_WORDS_ACTION_FLAG = 'flag';

    /**
     * Merge tags known at this wave. WP-13 (sending engine, spec §0.6) owns the
     * canonical tag registry; this list seeds the enable/disable UI until then.
     *
     * @var list<string>
     */
    public const KNOWN_TEMPLATE_TAGS = [
        'SUBSCRIBER_EMAIL',
        'SUBSCRIBER_NAME',
        'SUBSCRIBER_FIRST_NAME',
        'SUBSCRIBER_LAST_NAME',
        'SUBSCRIBER_UID',
        'LIST_NAME',
        'LIST_UID',
        'CAMPAIGN_NAME',
        'CAMPAIGN_SUBJECT',
        'CAMPAIGN_UID',
        'UNSUBSCRIBE_URL',
        'DIRECT_UNSUBSCRIBE_URL',
        'UPDATE_PROFILE_URL',
        'WEB_VERSION_URL',
        'FORWARD_URL',
        'COMPANY_NAME',
        'COMPANY_FULL_ADDRESS',
        'CURRENT_YEAR',
        'CURRENT_MONTH',
        'CURRENT_DAY',
    ];

    // Attachments (F-3.25.3-001)
    public bool $attachments_enabled;

    public int $attachment_max_size_mb;

    public int $attachment_max_count;

    /** @var list<string> */
    public array $attachment_allowed_mime_types;

    /** @var list<string> */
    public array $attachment_allowed_extensions;

    /**
     * Template tags enabled for use in campaign content (F-3.25.3-002).
     *
     * @var list<string>
     */
    public array $enabled_template_tags;

    // Blacklisted words (F-3.25.3-003)

    /** @var list<string> */
    public array $subject_blacklisted_words;

    /** @var list<string> */
    public array $content_blacklisted_words;

    /** One of: block | flag. */
    public string $blacklisted_words_action;

    // Forbidden domains (F-3.25.3-004)

    /** @var list<string> */
    public array $forbidden_from_domains;

    /** @var list<string> */
    public array $forbidden_reply_to_domains;

    // Webhooks (F-3.25.3-005)
    public bool $webhooks_enabled;

    /** @var list<string> */
    public array $webhook_endpoints;

    // Tracking defaults (F-3.25.3-006)
    public bool $default_open_tracking;

    public bool $default_click_tracking;

    /** 0 = unlimited (F-3.25.3-007). */
    public int $max_campaign_recipients;

    // Approval (F-3.25.3-008)
    public bool $approval_required;

    /** Minutes a scheduled send-at must lie in the future (F-3.25.3-009). */
    public int $send_at_min_offset_minutes;

    // Auto-pause thresholds, percent 0–100 (F-3.25.3-010)
    public float $bounce_auto_pause_percent;

    public float $complaint_auto_pause_percent;

    // Plain text (F-3.25.3-011)
    public bool $default_plain_text_generation;

    // Retry policy (F-3.25.3-012)
    public int $retry_max_attempts;

    public int $retry_backoff_seconds;

    public static function group(): string
    {
        return 'campaign';
    }
}
