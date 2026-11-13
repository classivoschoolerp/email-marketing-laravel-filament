<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Three-tier role model (spec §0.3, docs/DECISIONS.md D-06):
 * system roles are built-in + locked, global roles are operator-defined and
 * shared across tenants, customer roles belong to a single tenant.
 */
enum RoleScope: string
{
    case System = 'system';
    case Global = 'global';
    case Customer = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::System => __('auth-module.roles.scope.system'),
            self::Global => __('auth-module.roles.scope.global'),
            self::Customer => __('auth-module.roles.scope.customer'),
        };
    }
}
