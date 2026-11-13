<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Install\Installer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * §6.9 F-6.9-001: the installer wizard is public by necessity (there is no
 * account until step 3), so it must disappear the moment the installation is
 * done. A 404 — not a redirect — so an installed site leaks nothing about the
 * wizard's existence.
 *
 * Two lock conditions:
 *  1. the install marker file exists;
 *  2. an administrator account already exists AND this session has not
 *     started the wizard.
 *
 * (2) protects an EXISTING deployment upgraded to a version that ships the
 * wizard: it has no marker file, and without this check `/install` would be a
 * public Super-Admin factory. The session exemption cannot be abused, because
 * the only thing that sets the session flag — the database step — is itself
 * behind this middleware.
 */
class EnsureNotInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        $installer = app(Installer::class);

        abort_if($installer->markerExists(), 404);

        abort_if(
            $installer->hasAdministrator() && ! $request->session()->has('install.database'),
            404,
        );

        return $next($request);
    }
}
