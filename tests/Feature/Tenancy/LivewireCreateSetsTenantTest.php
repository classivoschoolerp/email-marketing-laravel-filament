<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\EmailTemplate;

/*
|--------------------------------------------------------------------------
| Regression: tenancy must survive the Livewire round-trip
|--------------------------------------------------------------------------
| Filament applies a panel's auth middleware to panel ROUTES. Every Filament
| form submission is a POST to /livewire/update, which is NOT a panel route,
| so panel middleware only runs there when it is registered as PERSISTENT
| (see CustomerPanelProvider::authMiddleware(..., isPersistent: true)).
|
| Without that flag SetCurrentCustomer never ran on the request that actually
| wrote the row, CurrentCustomer was empty, and BelongsToCustomer stored
| customer_id = NULL. The record saved "successfully" and then vanished: the
| tenant scope filtered it out of every list and its pages 404'd.
|
| Livewire::test() cannot catch this - it never crosses the HTTP boundary and
| so never loses the middleware. This test must stay at the HTTP level.
*/

it('stamps the tenant on a record created through a real Livewire form post', function (): void {
    $customer = Customer::factory()->create([
        'email' => 'tenancy@example.test',
        'password' => 'demo-password-1234',
    ]);

    $this->actingAs($customer, 'customer');

    $page = $this->get('/customer/email-templates/create')->assertOk();

    // A Filament page renders several Livewire components (global search, the
    // notification bell, ...). Pick the one that IS the page, by its path.
    preg_match_all('/wire:snapshot="([^"]+)"/', (string) $page->getContent(), $m);

    $snapshot = null;

    foreach ($m[1] as $raw) {
        $candidate = json_decode(html_entity_decode($raw, ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);

        // memo.path is the URL and is identical for every component on the
        // page; memo.name is what identifies the component itself.
        if (str_contains((string) ($candidate['memo']['name'] ?? ''), 'create-email-template')) {
            $snapshot = $candidate;

            break;
        }
    }

    expect($snapshot)->not->toBeNull('The create page component was not found on the page.');

    // CurrentCustomer is a SCOPED binding: on a real server every request
    // starts with an empty tenant and only middleware re-establishes it. A test
    // keeps one container across both calls, so without this the tenant set
    // during the GET leaks into the POST and hides the very bug under test.
    $this->app->forgetScopedInstances();

    $response = $this->postJson('/livewire/update', [
        'components' => [[
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'updates' => [
                'data.name' => 'Tenancy regression template',
                'data.builder' => 'grapesjs',
            ],
            'calls' => [['path' => '', 'method' => 'create', 'params' => []]],
        ]],
    ]);

    // A successful create answers 200 (JSON redirect effect) or 302.
    expect($response->status())->toBeIn([200, 302]);

    $template = EmailTemplate::withoutGlobalScopes()
        ->where('name', 'Tenancy regression template')
        ->first();

    expect($template)->not->toBeNull('The template was never created.');

    // The actual regression: this was NULL, which orphaned the record from the
    // tenant that made it.
    expect($template->customer_id)->toBe(
        $customer->getKey(),
        'customer_id was not stamped - panel auth middleware is not persistent.'
    );

    // And it must be visible to its owner through the tenant scope.
    expect(EmailTemplate::query()->whereKey($template->getKey())->exists())->toBeTrue();
});
