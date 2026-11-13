<?php

declare(strict_types=1);

namespace App\Support\Admin;

/**
 * Canonical admin-module catalogue for the user-group permission matrix
 * (spec §3.3 F-3.3-001): every module exposes view/create/update/delete
 * permissions named "{module}.{action}", enforced by the module's policy so
 * access control is applied at route level by Filament (F-3.3-002).
 *
 * Modules whose policies predate WP-03 keep their coarse permission names
 * (roles.manage, templates.manage, delegation areas per §0.3 F-0.3-011);
 * those are offered alongside the matrix via EXTRA_PERMISSIONS.
 */
final class AdminModules
{
    /** @var list<string> matrix modules (policies read "{module}.{action}") */
    public const MODULES = [
        'users',
        'user_groups',
        'customers',
        'customer_groups',
        'registration_fields',
    ];

    /** @var list<string> */
    public const ACTIONS = ['view', 'create', 'update', 'delete'];

    /**
     * Pre-existing coarse/delegation permissions (WP-02 RoleSeeder + WP-10)
     * assignable to groups next to the matrix (F-0.3-011).
     *
     * @var list<string>
     */
    public const EXTRA_PERMISSIONS = [
        'roles.view',
        'roles.manage',
        'customers.manage',
        'customers.impersonate',
        'plans.view',
        'plans.manage',
        'servers.view',
        'servers.manage',
        'billing.view',
        'billing.manage',
        'templates.manage',
        'blacklist.manage',
        // WP-24: §3.23 countries/zones reference data + §6.6 operations pages.
        'location.manage',
        // WP-36: §6.1/§3.20 extensions manager. Grants the ability to upload
        // and execute arbitrary PHP — see docs/plugins.md §Trust model.
        'plugins.manage',
        // WP-38 §6.3/§3.21/§4.7: themes, branding and the live customiser.
        // Deliberately a SEPARATE, lower-privilege key from plugins.manage —
        // a theme package cannot contain executable code (config/emp-themes.php),
        // so "may re-skin the product" must not imply "may run PHP".
        'themes.manage',
        // WP-38 §6.4: brand domains (host → logo/theme/from-addresses/pages).
        'domains.manage',
        // WP-38 §1.11/§3.24: articles, categories and static pages. Page
        // bodies are rendered as authored HTML, so this grants stored-XSS
        // reach over the public site and is an admin-only key.
        'cms.manage',
        // WP-41 §6.2: the live upgrade manager. Follows the plugins.manage
        // precedent — one coarse key, no "view but not apply" split. It grants
        // the ability to overwrite the running application's source code and
        // migrate the live database. See docs/upgrades.md §Trust model.
        'system.upgrade',
    ];

    public static function permission(string $module, string $action): string
    {
        return $module.'.'.$action;
    }

    /** @return list<string> every matrix permission name */
    public static function allPermissions(): array
    {
        $names = [];

        foreach (self::MODULES as $module) {
            foreach (self::ACTIONS as $action) {
                $names[] = self::permission($module, $action);
            }
        }

        return $names;
    }
}
