<?php

declare(strict_types=1);

namespace App\Services\Branding;

use App\Http\Middleware\ResolveBrand;
use App\Models\Customer;
use App\Models\Domain;
use Illuminate\Http\Request;

/**
 * Request-scoped holder for the resolved brand (spec §6.4 F-6.4-001).
 *
 * {@see ResolveBrand} fills this once per request; views,
 * render hooks and controllers read it. Bound as a container singleton, which
 * in Laravel means "one per application instance" — the test suite boots a
 * fresh application per test, so no state leaks between them.
 *
 * Resolution is lazy: a queue worker or an artisan command never runs the
 * middleware, and asking for the brand there must still answer with the
 * install-wide one instead of null.
 */
class BrandContext
{
    private ?Brand $brand = null;

    public function __construct(private readonly BrandResolver $resolver) {}

    public function set(Brand $brand): void
    {
        $this->brand = $brand;
    }

    public function setFromRequest(Request $request): Brand
    {
        return $this->brand = $this->resolver->forRequest($request);
    }

    public function current(): Brand
    {
        return $this->brand ??= $this->resolver->forHost(null);
    }

    public function domain(): ?Domain
    {
        return $this->current()->domain;
    }

    /** The brand a specific customer should see, ignoring the request host. */
    public function forCustomer(?Customer $customer): Brand
    {
        return $this->resolver->forCustomer($customer);
    }

    public function resolver(): BrandResolver
    {
        return $this->resolver;
    }
}
