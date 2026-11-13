<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\LocalePreferencePage;
use App\Models\User;
use Database\Seeders\LanguageSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

// WP-20 — §6.5 F-6.5-005: per-admin-user language selection.

beforeEach(function (): void {
    $this->seed(LanguageSeeder::class);
    $this->admin = User::factory()->create();
    $this->actingAs($this->admin, 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('renders the locale preference page for an admin', function (): void {
    $this->get(LocalePreferencePage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('i18n.locale_page.title'));
});

it('blocks guests from the locale preference page', function (): void {
    auth('admin')->logout();

    $this->get(LocalePreferencePage::getUrl(panel: 'admin'))->assertRedirect();
});

it('saves the admin user locale', function (): void {
    Livewire::test(LocalePreferencePage::class)
        ->fillForm(['locale' => 'ja'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->admin->refresh()->locale)->toBe('ja');
});

it('rejects locales that are not installed', function (): void {
    Livewire::test(LocalePreferencePage::class)
        ->fillForm(['locale' => 'zz'])
        ->call('save')
        ->assertHasFormErrors(['locale']);

    expect($this->admin->refresh()->locale)->toBe('en');
});
