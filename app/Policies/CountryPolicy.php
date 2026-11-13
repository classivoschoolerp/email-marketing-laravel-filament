<?php

declare(strict_types=1);

namespace App\Policies;

use App\Policies\Concerns\AdminLocationPolicy;

/**
 * §3.23 F-3.23-004 countries manager — behind `location.manage`.
 */
class CountryPolicy
{
    use AdminLocationPolicy;
}
