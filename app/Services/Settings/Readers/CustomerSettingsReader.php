<?php

declare(strict_types=1);

namespace App\Services\Settings\Readers;

use App\Models\CustomerGroup;
use App\Settings\CustomerSettings;

/**
 * Typed accessors for the `customer` settings group (spec §3.25.4).
 */
final readonly class CustomerSettingsReader
{
    public function __construct(private CustomerSettings $settings) {}

    public function registrationEnabled(): bool
    {
        return $this->settings->registration_enabled;
    }

    public function registrationRequiresApproval(): bool
    {
        return $this->settings->registration_requires_approval;
    }

    public function registrationRequiresEmailConfirmation(): bool
    {
        return $this->settings->registration_requires_email_confirmation;
    }

    public function defaultCustomerGroupId(): ?int
    {
        return $this->settings->default_customer_group_id;
    }

    public function defaultCustomerGroup(): ?CustomerGroup
    {
        return $this->settings->default_customer_group_id === null
            ? null
            : CustomerGroup::query()->find($this->settings->default_customer_group_id);
    }

    /**
     * @return list<string>
     */
    public function allowedSendingServerTypes(): array
    {
        return $this->settings->allowed_sending_server_types;
    }

    public function sendingServerTypeAllowed(string $type): bool
    {
        return in_array($type, $this->settings->allowed_sending_server_types, true);
    }

    /**
     * 0 = unlimited.
     */
    public function defaultMaxLists(): int
    {
        return $this->settings->default_max_lists;
    }

    /**
     * 0 = unlimited.
     */
    public function defaultMaxCampaigns(): int
    {
        return $this->settings->default_max_campaigns;
    }

    /**
     * 0 = unlimited.
     */
    public function defaultMaxSubscribers(): int
    {
        return $this->settings->default_max_subscribers;
    }

    /**
     * 0 = unlimited.
     */
    public function defaultSendingQuota(): int
    {
        return $this->settings->default_sending_quota;
    }

    /**
     * One of: hourly | daily | monthly.
     */
    public function defaultSendingQuotaPeriod(): string
    {
        return $this->settings->default_sending_quota_period;
    }

    public function defaultCdnEnabled(): bool
    {
        return $this->settings->default_cdn_enabled;
    }

    public function defaultCdnUrl(): ?string
    {
        return $this->settings->default_cdn_url;
    }

    public function defaultApiEnabled(): bool
    {
        return $this->settings->default_api_enabled;
    }

    public function defaultApiRateLimitPerMinute(): int
    {
        return $this->settings->default_api_rate_limit_per_minute;
    }

    public function forceSystemServersOnly(): bool
    {
        return $this->settings->force_system_servers_only;
    }

    public function welcomeEmailEnabled(): bool
    {
        return $this->settings->welcome_email_enabled;
    }

    public function selfServeDeletionEnabled(): bool
    {
        return $this->settings->self_serve_deletion_enabled;
    }

    public function termsAcceptanceRequired(): bool
    {
        return $this->settings->terms_acceptance_required;
    }

    /**
     * One of: weak | medium | strong.
     */
    public function minimumPasswordStrength(): string
    {
        return $this->settings->minimum_password_strength;
    }

    /**
     * 0 = never auto-suspend.
     */
    public function inactiveAutoSuspendDays(): int
    {
        return $this->settings->inactive_auto_suspend_days;
    }
}
