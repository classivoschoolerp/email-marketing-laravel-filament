<?php

declare(strict_types=1);

namespace App\Support\Quota;

use App\Settings\CustomerSettings;

/**
 * Canonical key catalogue for the §3.5 customer-group quota/permission
 * matrix. Every value is stored as a CustomerGroupLimit (integer, null =
 * unlimited) or CustomerGroupPermission (boolean) key-value row — the exact
 * rows QuotaService::limitForGroup()/groupAllows() already read, and which
 * customer_limits/customer_permissions override per customer (§3.4).
 *
 * Key reuse: max_lists, max_subscribers, max_campaigns, max_delivery_servers,
 * sends_per_hour/day/month (config emp.quotas.defaults + WP-06/07 policies),
 * max_templates (WP-10 TemplateQuota) and max_campaign_recipients (§3.25.3)
 * keep their existing names so later work packages read the same rows.
 *
 * List-valued settings (allowed server types, template builders, attachment
 * types) are encoded as one boolean permission row per member using the
 * serverTypeKey()/builderKey()/attachmentTypeKey() prefixes, so
 * QuotaService::groupAllows("servers.type.smtp") answers the whitelist
 * question directly (WP-14/15 contract).
 */
final class CustomerGroupMatrix
{
    /**
     * Limit keys per §3.5 tab. null row = unlimited, absent row = config
     * default (emp.quotas.defaults), integer row = hard limit.
     *
     * @var array<string, list<string>>
     */
    public const LIMITS = [
        'lists' => [
            'max_lists',
            'max_subscribers_per_list',
            'max_subscribers',
            'max_import_file_size_mb',
            'max_custom_fields',
            'max_segments',
        ],
        'campaigns' => [
            'max_campaigns',
            'max_campaigns_per_day',
            'max_attachment_size_mb',
            'sends_per_hour',
            'sends_per_day',
            'sends_per_month',
            'max_campaign_recipients',
        ],
        'servers' => [
            'max_delivery_servers',
            'max_bounce_servers',
            'max_fbl_servers',
            'max_email_box_monitors',
        ],
        'domains' => [
            'max_sending_domains',
            'max_tracking_domains',
        ],
        'templates' => [
            'max_templates',
        ],
        'features' => [
            'max_surveys',
            'max_landing_pages',
            'max_forms',
            'max_automations',
            'max_automation_active_contacts',
            'sms_credits',
            'transactional_monthly_quota',
            'verification_credits',
            // §2.15.8 F-2.15.8-010 (D-73 shape). It MUST be listed here:
            // the admin customer editor rewrites customer_limits from this
            // catalogue on save, so a key missing from it would have its
            // balance wiped every time an operator edited the customer.
            'ai_credits',
            'max_sub_users',
            // §2.13 CRM (WP-44). Contacts are the only CRM row worth a hard
            // ceiling: deals, tasks and notes are all downstream of a contact,
            // so capping contacts caps the module. Absent row = the config
            // default (unlimited), which is what every account had before the
            // CRM existed.
            'max_crm_contacts',
        ],
        'api' => [
            'max_api_keys',
            'api_requests_per_minute',
        ],
        'misc' => [
            'max_storage_mb',
        ],
    ];

    /**
     * Boolean feature-permission keys per §3.5 tab, read via
     * QuotaService::groupAllows()/allows().
     *
     * @var array<string, list<string>>
     */
    public const PERMISSIONS = [
        'lists' => [
            'lists.import',
            'lists.export',
        ],
        'campaigns' => [
            'campaigns.regular',
            'campaigns.autoresponder',
            'campaigns.recurring',
            'campaigns.attachments',
            'campaigns.template_tags',
            'campaigns.ab_testing',
            'campaigns.filters',
            'campaigns.scheduling',
            'campaigns.send_to_segment',
            'sending_quota.auto_reset',
        ],
        'servers' => [
            'servers.delivery.add',
            'servers.bounce.add',
            'servers.fbl.add',
            'servers.monitors.add',
            'servers.must_validate',
        ],
        'domains' => [
            'domains.sending.add',
            'domains.tracking.add',
        ],
        'templates' => [
            'templates.gallery',
        ],
        'features' => [
            'surveys.enabled',
            'landing_pages.enabled',
            'forms.enabled',
            'automations.enabled',
            // §2.13 CRM (WP-44) — the single gate every CRM policy checks.
            'crm.enabled',
            'sms.enabled',
            // WP-46 §2.10 P3 channels. Each is its own key rather than riding
            // `sms.enabled`, because they are separately priced and separately
            // provisioned: an account can have WhatsApp without SMS.
            'whatsapp.enabled',
            'web_push.enabled',
            'in_app.enabled',
            'transactional.enabled',
            'branding.custom_logo',
            'branding.remove_powered_by',
            // WP-46 §4.7 F-4.7-004 — agency mode: the customer brands its own
            // panel and its team members' system emails. Separate from
            // `branding.custom_logo` (which is about the customer's own
            // sending identity), because this one repaints the product.
            'branding.agency',
            'tracking.force_open',
            'tracking.force_click',
        ],
        'api' => [
            'api.enabled',
            // Authorises the privileged `admin` API-key scope (§2.14.1
            // F-2.14.1-004) — i.e. platform-wide /customers CRUD from an API
            // key. Defaults to DENIED via config emp.group_permissions
            // .defaults.api.admin, unlike every other matrix permission.
            'api.admin',
        ],
        'misc' => [
            'subscribers.delete',
            'blacklist.manage',
            'suppression.manage',
            'warmup.enabled',
        ],
    ];

    public const SERVER_TYPE_PREFIX = 'servers.type.';

    public const BUILDER_PREFIX = 'templates.builder.';

    public const ATTACHMENT_TYPE_PREFIX = 'campaigns.attachment_type.';

    /**
     * Attachment extension whitelist offered at group level; the global
     * §3.25.3 campaign settings stay the outer bound.
     *
     * @var list<string>
     */
    public const ATTACHMENT_TYPES = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'txt', 'csv', 'zip', 'png', 'jpg', 'gif',
    ];

    /** @return list<string> every limit key */
    public static function limitKeys(): array
    {
        return array_merge(...array_values(self::LIMITS));
    }

    /** @return list<string> every static permission key (without type-list keys) */
    public static function permissionKeys(): array
    {
        return array_merge(...array_values(self::PERMISSIONS));
    }

    public static function serverTypeKey(string $type): string
    {
        return self::SERVER_TYPE_PREFIX.$type;
    }

    public static function builderKey(string $builder): string
    {
        return self::BUILDER_PREFIX.$builder;
    }

    public static function attachmentTypeKey(string $extension): string
    {
        return self::ATTACHMENT_TYPE_PREFIX.$extension;
    }

    /**
     * Sending-server driver keys (D-21) — read-only reuse of the §3.25.4
     * whitelist source. WP-14's driver registry supersedes as option source.
     *
     * @return list<string>
     */
    public static function serverTypes(): array
    {
        return CustomerSettings::SENDING_SERVER_TYPES;
    }
}
