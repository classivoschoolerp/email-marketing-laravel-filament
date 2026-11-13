<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Customer settings (spec §3.25.4).
 */
class CustomerSettings extends Settings
{
    public const PASSWORD_STRENGTH_WEAK = 'weak';

    public const PASSWORD_STRENGTH_MEDIUM = 'medium';

    public const PASSWORD_STRENGTH_STRONG = 'strong';

    /** @var list<string> */
    public const PASSWORD_STRENGTHS = [
        self::PASSWORD_STRENGTH_WEAK,
        self::PASSWORD_STRENGTH_MEDIUM,
        self::PASSWORD_STRENGTH_STRONG,
    ];

    /** @var list<string> */
    public const QUOTA_PERIODS = ['hourly', 'daily', 'monthly'];

    /**
     * P1 delivery server types from spec §2.9.2. WP-14's driver registry becomes
     * the canonical source once built; keys are stable driver identifiers.
     *
     * @var list<string>
     */
    public const SENDING_SERVER_TYPES = [
        'smtp',
        'smtp-amazon-ses',
        'amazon-ses-api',
        'sendgrid-api',
        'mailgun-api',
        'sparkpost-api',
        'postmark-api',
        'elastic-email-api',
        'mailjet-api',
        'brevo-api',
        'postal',
        'powermta',
        'php-mail',
        'pickup-directory',
        'sendmail',
        'gmail-oauth',
        'blastengine',
        'postfix',
        'exim',
        'qmail',
        // F-2.9.2-013 (P2, WP-43): modern transactional ESPs. Appended, never
        // reordered — DriverRegistry asserts its keys match this list exactly
        // (D-21), so a driver may only ship once its key is declared here.
        'resend-api',
        'loops-api',
        'mailtrap-api',
    ];

    // Registration (F-3.25.4-001)
    public bool $registration_enabled;

    public bool $registration_requires_approval;

    public bool $registration_requires_email_confirmation;

    /** FK into customer_groups (F-3.25.4-002). */
    public ?int $default_customer_group_id;

    /**
     * Global whitelist of sending server types customers may create (F-3.25.4-003).
     *
     * @var list<string>
     */
    public array $allowed_sending_server_types;

    // Global defaults for new customers (F-3.25.4-004) — 0 = unlimited
    public int $default_max_lists;

    public int $default_max_campaigns;

    public int $default_max_subscribers;

    /** Emails per quota period; 0 = unlimited. */
    public int $default_sending_quota;

    /** One of: hourly | daily | monthly. */
    public string $default_sending_quota_period;

    public bool $default_cdn_enabled;

    public ?string $default_cdn_url;

    public bool $default_api_enabled;

    public int $default_api_rate_limit_per_minute;

    // Delivery servers (F-3.25.4-005)
    public bool $force_system_servers_only;

    // Welcome email (F-3.25.4-006)
    public bool $welcome_email_enabled;

    // Account deletion (F-3.25.4-007)
    public bool $self_serve_deletion_enabled;

    // Terms (F-3.25.4-008)
    public bool $terms_acceptance_required;

    /** One of: weak | medium | strong (F-3.25.4-009). */
    public string $minimum_password_strength;

    /** 0 = never auto-suspend (F-3.25.4-010). */
    public int $inactive_auto_suspend_days;

    public static function group(): string
    {
        return 'customer';
    }
}
