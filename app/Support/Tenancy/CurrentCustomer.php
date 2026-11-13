<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Customer;

/**
 * Holds the tenant for the current request/job. The CustomerScope global scope reads
 * from here — never from the UI layer (docs/ARCHITECTURE.md rule 3). Queued jobs must call set()
 * explicitly in handle() with the customer id they were constructed with.
 */
final class CurrentCustomer
{
    private ?Customer $customer = null;

    public function set(?Customer $customer): void
    {
        $this->customer = $customer;
    }

    public function get(): ?Customer
    {
        return $this->customer;
    }

    public function id(): ?int
    {
        return $this->customer?->id;
    }

    public function check(): bool
    {
        return $this->customer !== null;
    }

    /**
     * Run a callback as the given tenant, restoring the previous tenant afterwards.
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAs(?Customer $customer, callable $callback): mixed
    {
        $previous = $this->customer;
        $this->customer = $customer;

        try {
            return $callback();
        } finally {
            $this->customer = $previous;
        }
    }
}
