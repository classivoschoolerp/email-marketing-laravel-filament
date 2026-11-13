<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\User;
use App\Support\Tenancy\CurrentCustomer;

it('serves the admin panel login page', function (): void {
    $this->get('/admin/login')->assertOk();
});

it('serves the customer panel login page', function (): void {
    $this->get('/customer/login')->assertOk();
});

it('redirects guests away from panel dashboards', function (): void {
    $this->get('/admin')->assertRedirect();
    $this->get('/customer')->assertRedirect();
});

it('lets an admin user into /admin via the admin guard', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'admin')->get('/admin')->assertOk();
});

it('lets a customer into /customer and binds the tenant context', function (): void {
    $customer = Customer::factory()->create();

    $this->actingAs($customer, 'customer')->get('/customer')->assertOk();

    expect(app(CurrentCustomer::class)->id())->toBe($customer->id);
});

it('does not let a customer session into the admin panel', function (): void {
    $customer = Customer::factory()->create();

    $this->actingAs($customer, 'customer')->get('/admin')->assertRedirect();
});
