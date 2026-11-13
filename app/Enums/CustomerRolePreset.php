<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * F-0.3-003 — the five named customer roles the spec lists verbatim:
 * Owner, Admin, Editor, Analyst, Read-only (WP-42).
 *
 * These are PRESETS, not a fourth role tier. Each one is provisioned as an
 * ordinary per-tenant role (roles.scope = customer, D-06/D-17) carrying the
 * TeamPermission set below, so the whole WP-39 stack keeps working with no
 * changes: RoleChecker resolves them for a SubUser (D-19), per-member
 * sub_user_permissions rows still layer grants/denies on top (F-2.15.4-002),
 * and disabling one still preserves its assignments (F-0.3-010). A tenant may
 * rename a preset role or re-tick its permissions — the `roles.preset` key is
 * what keeps provisioning idempotent, not the name.
 *
 * Permission sets, and why they differ:
 *
 *   Owner      everything, including billing and team management. This is the
 *              co-owner seat; the account Customer itself is never checked
 *              against TeamPermission at all (see that enum) — this role is
 *              how a SUB-USER gets the same reach.
 *   Admin      everything except `billing.manage`. Runs the account day to
 *              day; does not hold the payment instrument.
 *   Editor     the whole marketing surface — lists, subscribers, campaigns
 *              (including sending), templates, automations, plus reports —
 *              but no billing, team or account settings.
 *   Analyst    aggregate reporting only: lists, campaigns and reports, and
 *              deliberately NOT `subscribers.view`. An analyst needs the
 *              numbers, not the personal data behind them, and §4.5 data
 *              minimisation says not to hand out PII by default.
 *   Read-only  sees everything an Editor sees and changes nothing — the
 *              superset of Analyst, subscriber records included.
 */
enum CustomerRolePreset: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Editor = 'editor';
    case Analyst = 'analyst';
    case ReadOnly = 'read_only';

    /**
     * The role name written to `roles.name` on provisioning. Deliberately an
     * untranslated identifier: role names are data a tenant may rename, and
     * a name that changed with the viewer's locale would break both the D-17
     * uniqueness index and every audit-trail entry that quotes it. The
     * translated string is {@see label()}, used for display only.
     */
    public function roleName(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Editor => 'Editor',
            self::Analyst => 'Analyst',
            self::ReadOnly => 'Read-only',
        };
    }

    public function label(): string
    {
        return __('team.customer_roles.'.$this->value.'.label');
    }

    public function description(): string
    {
        return __('team.customer_roles.'.$this->value.'.description');
    }

    /**
     * The TeamPermission values this preset grants.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => TeamPermission::values(),
            self::Admin => array_values(array_diff(
                TeamPermission::values(),
                [TeamPermission::BillingManage->value],
            )),
            self::Editor => [
                TeamPermission::ListsView->value,
                TeamPermission::ListsManage->value,
                TeamPermission::SubscribersView->value,
                TeamPermission::SubscribersManage->value,
                TeamPermission::CampaignsView->value,
                TeamPermission::CampaignsManage->value,
                TeamPermission::CampaignsSend->value,
                TeamPermission::TemplatesManage->value,
                TeamPermission::AutomationsManage->value,
                TeamPermission::ReportsView->value,
            ],
            self::Analyst => [
                TeamPermission::ListsView->value,
                TeamPermission::CampaignsView->value,
                TeamPermission::ReportsView->value,
            ],
            self::ReadOnly => [
                TeamPermission::ListsView->value,
                TeamPermission::SubscribersView->value,
                TeamPermission::CampaignsView->value,
                TeamPermission::ReportsView->value,
            ],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** @return array<string, string> preset key => translated label */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
