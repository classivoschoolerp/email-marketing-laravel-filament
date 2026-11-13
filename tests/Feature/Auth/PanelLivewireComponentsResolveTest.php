<?php

declare(strict_types=1);

use App\Models\Customer;
use Filament\Facades\Filament;
use Livewire\Mechanisms\ComponentRegistry;

/*
|--------------------------------------------------------------------------
| Regression: panel component names must survive a browser round-trip
|--------------------------------------------------------------------------
| Filament registers a panel's Livewire components inside Panel::register(),
| which snapshots the auth route actions at that moment. A route action
| replaced AFTER that point still renders — the page loads and looks correct —
| but its component name is never registered with Livewire. The failure only
| shows up when the user submits the form: /livewire/update cannot resolve the
| name, and Livewire reports it as HTTP 419 "Page Expired", which reads like a
| CSRF/session problem and sends you looking in the wrong place entirely.
|
| That shipped once and made the customer login unusable in a browser while
| every Livewire::test() unit test kept passing, because Livewire::test()
| resolves the component by class and never exercises the name lookup.
*/

/** @return list<array{0: string, 1: string}> [panel id, component class] */
function authRouteActions(): array
{
    $actions = [];

    foreach (Filament::getPanels() as $panel) {
        foreach ([
            $panel->hasLogin() ? $panel->getLoginRouteAction() : null,
            $panel->hasRegistration() ? $panel->getRegistrationRouteAction() : null,
            $panel->hasPasswordReset() ? $panel->getRequestPasswordResetRouteAction() : null,
            $panel->hasPasswordReset() ? $panel->getResetPasswordRouteAction() : null,
        ] as $action) {
            if (is_string($action) && class_exists($action) && is_subclass_of($action, \Livewire\Component::class)) {
                $actions[] = [$panel->getId(), $action];
            }
        }
    }

    return $actions;
}

it('registers every panel auth page as a resolvable Livewire component', function (): void {
    $registry = app(ComponentRegistry::class);
    $actions = authRouteActions();

    expect($actions)->not->toBeEmpty('No panel auth route actions were discovered.');

    foreach ($actions as [$panelId, $class]) {
        $name = $registry->getName($class);

        // getClass() is what /livewire/update calls on every form submission.
        // If this throws, the page renders but nothing on it can be submitted.
        expect($registry->getClass($name))->toBe(
            $class,
            "Panel [{$panelId}] renders [{$class}] as [{$name}], but that name does not resolve back. ".
            'Set the route action inside the PanelProvider, or call $panel->register() after replacing it.'
        );
    }
});

it('completes a real HTTP Livewire round-trip on the customer login form', function (): void {
    Customer::factory()->create([
        'email' => 'roundtrip@example.test',
        'password' => 'demo-password-1234',
    ]);

    $page = $this->get('/customer/login')->assertOk();

    expect((string) $page->getContent())->toMatch('/wire:snapshot="/');

    preg_match('/wire:snapshot="([^"]+)"/', (string) $page->getContent(), $m);
    $snapshot = json_decode(html_entity_decode($m[1], ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);

    // The exact call the browser makes when the user presses "Sign in". This
    // goes through the real HTTP stack, so it covers component-name lookup,
    // release-token verification and CSRF — none of which Livewire::test() hits.
    $response = $this->postJson('/livewire/update', [
        'components' => [[
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'updates' => [
                'data.email' => 'roundtrip@example.test',
                'data.password' => 'demo-password-1234',
            ],
            'calls' => [['path' => '', 'method' => 'authenticate', 'params' => []]],
        ]],
    ]);

    // 419 is the exact symptom of the bug this test exists for: Livewire
    // reports an unresolvable component name as "Page Expired", which looks
    // like a CSRF failure and is not one. A successful sign-in answers 200
    // (JSON redirect effect) or 302; either is fine, 419 never is.
    expect($response->status())->not->toBe(
        419,
        'Livewire could not resolve the login component - see this file\'s header.'
    )->toBeIn([200, 302]);

    expect(auth('customer')->check())->toBeTrue();
});
