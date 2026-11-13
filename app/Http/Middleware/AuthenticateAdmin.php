<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Filament\Facades\Filament;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;

/**
 * auth:admin for the WP-03 module routes: unauthenticated requests are sent
 * to the admin panel login instead of the (non-existent) generic "login"
 * route the framework default expects.
 */
class AuthenticateAdmin extends Authenticate
{
    protected function redirectTo(Request $request): ?string
    {
        return Filament::getPanel('admin')->getLoginUrl();
    }
}
