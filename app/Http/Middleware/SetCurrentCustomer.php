<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Customer;
use App\Support\Tenancy\CurrentCustomer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the authenticated customer as the current tenant for the request. Runs after
 * auth on the /customer panel and on public tenant-scoped routes that resolve a
 * customer from the URL.
 */
final class SetCurrentCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('customer');

        if ($user instanceof Customer) {
            app(CurrentCustomer::class)->set($user);
        }

        return $next($request);
    }
}
