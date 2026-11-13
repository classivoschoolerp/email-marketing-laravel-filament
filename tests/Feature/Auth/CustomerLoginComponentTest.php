<?php

declare(strict_types=1);

use App\Models\Customer;
use Filament\Facades\Filament;
use Livewire\Livewire;

it('authenticates the demo customer through the real Filament login component', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('customer'));

    $customer = Customer::factory()->create([
        'email' => 'probe@example.test',
        'password' => 'demo-password-1234',
    ]);

    Livewire::test(\App\Filament\Customer\Auth\Login::class)
        ->fillForm([
            'email' => 'probe@example.test',
            'password' => 'demo-password-1234',
        ])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(auth('customer')->id())->toBe($customer->id);
});
