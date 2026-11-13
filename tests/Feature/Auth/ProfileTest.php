<?php

declare(strict_types=1);

use App\Filament\Customer\Pages\Profile;
use App\Models\Customer;
use App\Notifications\Auth\ConfirmEmailChange;
use App\Notifications\Auth\PasswordChangedNotification;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('customer'));

    $this->customer = Customer::factory()->create([
        'password' => 'Secret123',
        'timezone' => 'UTC',
        'locale' => 'en',
    ]);

    $this->actingAs($this->customer, 'customer');
});

// F-2.15.1-001 + F-2.15.1-002 + F-2.15.1-003
it('updates identity, preferences and company details', function (): void {
    Livewire::test(Profile::class)
        ->fillForm([
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'phone' => '+15550001111',
            'timezone' => 'Europe/Berlin',
            'locale' => 'en',
            'date_format' => 'd/m/Y',
            'company' => 'COBOL Corp',
            'company_address' => '1 Compiler Way',
            'company_city' => 'Arlington',
            'company_zone' => 'VA',
            'company_country' => 'US',
            'company_zip' => '22201',
            'company_website' => 'https://cobol.example',
            'company_vat' => 'US-12345',
        ], 'profileForm')
        ->call('saveProfile')
        ->assertHasNoFormErrors([], 'profileForm');

    $customer = $this->customer->fresh();

    expect($customer->first_name)->toBe('Grace')
        ->and($customer->last_name)->toBe('Hopper')
        ->and($customer->phone)->toBe('+15550001111')
        ->and($customer->timezone)->toBe('Europe/Berlin')
        ->and($customer->date_format)->toBe('d/m/Y')
        ->and($customer->company)->toBe('COBOL Corp')
        ->and($customer->company_city)->toBe('Arlington')
        ->and($customer->company_zone)->toBe('VA')
        ->and($customer->company_country)->toBe('US')
        ->and($customer->company_zip)->toBe('22201')
        ->and($customer->company_website)->toBe('https://cobol.example')
        ->and($customer->company_vat)->toBe('US-12345');
});

// F-2.15.1-001 — avatar stored under customers/{uid}/
it('stores the avatar under the customer uid directory', function (): void {
    Storage::fake('public');

    Livewire::test(Profile::class)
        ->fillForm(['avatar' => UploadedFile::fake()->image('avatar.png')], 'profileForm')
        ->call('saveProfile')
        ->assertHasNoFormErrors([], 'profileForm');

    $path = $this->customer->fresh()->avatar_path;

    expect($path)->not->toBeNull()
        ->and(str_starts_with((string) $path, 'customers/'.$this->customer->uid.'/'))->toBeTrue();

    Storage::disk('public')->assertExists((string) $path);
});

// F-2.15.1-004 — change password with current-password confirmation
it('changes the password after confirming the current one', function (): void {
    Notification::fake();

    Livewire::test(Profile::class)
        ->fillForm([
            'current_password' => 'Secret123',
            'password' => 'BrandNew456',
            'password_confirmation' => 'BrandNew456',
        ], 'passwordForm')
        ->call('savePassword')
        ->assertHasNoFormErrors([], 'passwordForm');

    expect(Hash::check('BrandNew456', $this->customer->fresh()->password))->toBeTrue();

    Notification::assertSentTo($this->customer, PasswordChangedNotification::class);
});

it('refuses a password change with a wrong current password', function (): void {
    Livewire::test(Profile::class)
        ->fillForm([
            'current_password' => 'not-my-password',
            'password' => 'BrandNew456',
            'password_confirmation' => 'BrandNew456',
        ], 'passwordForm')
        ->call('savePassword')
        ->assertHasFormErrors(['current_password'], 'passwordForm');

    expect(Hash::check('Secret123', $this->customer->fresh()->password))->toBeTrue();
});

// F-2.15.1-005 — email change with re-verification to the new address
it('parks an email change until the new address confirms', function (): void {
    Notification::fake();

    $oldEmail = $this->customer->email;

    Livewire::test(Profile::class)
        ->fillForm(['email' => 'new-address@example.com'], 'profileForm')
        ->call('saveProfile')
        ->assertHasNoFormErrors([], 'profileForm');

    $customer = $this->customer->fresh();

    expect($customer->email)->toBe($oldEmail)
        ->and($customer->pending_email)->toBe('new-address@example.com');

    Notification::assertSentOnDemand(
        ConfirmEmailChange::class,
        fn ($notification, $channels, $notifiable): bool => ($notifiable->routes['mail'] ?? null) === 'new-address@example.com',
    );
});

it('applies the email change from the signed confirmation link', function (): void {
    $this->customer->forceFill(['pending_email' => 'new-address@example.com'])->save();

    $url = URL::temporarySignedRoute(
        'email-change.confirm',
        now()->addHours((int) config('emp.email_change.ttl_hours')),
        ['uid' => $this->customer->uid, 'hash' => sha1('new-address@example.com')],
    );

    $this->get($url)->assertRedirect('/customer/profile');

    $customer = $this->customer->fresh();

    expect($customer->email)->toBe('new-address@example.com')
        ->and($customer->pending_email)->toBeNull()
        ->and($customer->email_verified_at)->not->toBeNull();
});

it('rejects tampered or expired email change links', function (): void {
    $this->customer->forceFill(['pending_email' => 'new-address@example.com'])->save();

    // Unsigned.
    $this->get(route('email-change.confirm', ['uid' => $this->customer->uid, 'hash' => sha1('new-address@example.com')]))
        ->assertForbidden();

    // Expired.
    $url = URL::temporarySignedRoute(
        'email-change.confirm',
        now()->addHours((int) config('emp.email_change.ttl_hours')),
        ['uid' => $this->customer->uid, 'hash' => sha1('new-address@example.com')],
    );

    $this->travel((int) config('emp.email_change.ttl_hours') + 1)->hours();

    $this->get($url)->assertForbidden();

    expect($this->customer->fresh()->email)->not->toBe('new-address@example.com');
});

it('rejects an email already used by another customer', function (): void {
    Customer::factory()->create(['email' => 'taken@example.com']);

    Livewire::test(Profile::class)
        ->fillForm(['email' => 'taken@example.com'], 'profileForm')
        ->call('saveProfile')
        ->assertHasFormErrors(['email'], 'profileForm');
});
