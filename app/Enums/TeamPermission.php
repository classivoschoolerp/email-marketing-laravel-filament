<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * F-2.15.4-002 — the granular permission set a team member can be granted
 * inside one tenant (WP-39).
 *
 * These are spatie permission names on the `customer` guard, so a per-tenant
 * Role (roles.scope = customer, D-06/D-17) can carry them and RoleChecker
 * resolves them for a SubUser with no changes (D-19). Per-user
 * sub_user_permissions rows layer explicit grants/denies on top.
 *
 * The account OWNER (the Customer itself) is never checked against this
 * list — an owner always has everything; these gates only ever narrow a
 * sub-user.
 */
enum TeamPermission: string
{
    case ListsView = 'lists.view';
    case ListsManage = 'lists.manage';
    case SubscribersView = 'subscribers.view';
    case SubscribersManage = 'subscribers.manage';
    case CampaignsView = 'campaigns.view';
    case CampaignsManage = 'campaigns.manage';
    case CampaignsSend = 'campaigns.send';
    case TemplatesManage = 'templates.manage';
    case AutomationsManage = 'automations.manage';
    case ServersManage = 'servers.manage';
    case ReportsView = 'reports.view';
    case BillingManage = 'billing.manage';
    case TeamManage = 'team.manage';
    case SettingsManage = 'settings.manage';

    public function label(): string
    {
        return __('team.permissions.'.str_replace('.', '_', $this->value));
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Grouped options for the Filament checkbox list.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * Ready-made bundles offered when inviting somebody, so an operator
     * never has to hand-pick 14 checkboxes for the common cases.
     *
     * @return array<string, list<string>>
     */
    public static function presets(): array
    {
        return [
            'manager' => self::values(),
            'marketer' => [
                self::ListsView->value,
                self::ListsManage->value,
                self::SubscribersView->value,
                self::SubscribersManage->value,
                self::CampaignsView->value,
                self::CampaignsManage->value,
                self::CampaignsSend->value,
                self::TemplatesManage->value,
                self::ReportsView->value,
            ],
            'analyst' => [
                self::ListsView->value,
                self::SubscribersView->value,
                self::CampaignsView->value,
                self::ReportsView->value,
            ],
        ];
    }
}
