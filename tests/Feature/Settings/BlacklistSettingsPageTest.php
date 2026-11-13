<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\Settings\BlacklistSettingsPage;
use App\Models\Customer;
use App\Models\User;
use App\Services\Settings\SettingsReader;
use App\Settings\BlacklistSettings;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(), 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

// F-3.25.5-001
it('renders the blacklist settings page for an admin', function (): void {
    $this->get(BlacklistSettingsPage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('settings.blacklist.title'));
});

it('denies the customer guard access to the blacklist settings page', function (): void {
    auth('admin')->logout();
    $this->actingAs(Customer::factory()->create(), 'customer');

    $this->get(BlacklistSettingsPage::getUrl(panel: 'admin'))->assertRedirect();
});

it('shows the seeded blacklist defaults', function (): void {
    Livewire::test(BlacklistSettingsPage::class)
        ->assertFormSet([
            'enabled' => true,
            'regex_rules' => [],
            'apply_at' => 'both',
            'shared_across_customers' => true,
        ]);
});

// F-3.25.5-001 … F-3.25.5-004
it('persists blacklist settings including regex rules', function (): void {
    // The simple repeater's internal state is item-shaped; it dehydrates to a
    // flat list of patterns on save.
    Livewire::test(BlacklistSettingsPage::class)
        ->fillForm([
            'enabled' => false,
            'regex_rules' => [
                ['pattern' => '/^spam-.*@example\.com$/i'],
                ['pattern' => '/@disposable\.test$/'],
            ],
            'apply_at' => 'import',
            'shared_across_customers' => false,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(BlacklistSettings::class)->refresh();

    expect($settings->enabled)->toBeFalse()
        ->and(array_values($settings->regex_rules))->toBe(['/^spam-.*@example\.com$/i', '/@disposable\.test$/'])
        ->and($settings->apply_at)->toBe('import')
        ->and($settings->shared_across_customers)->toBeFalse();
});

// F-3.25.5-002 — patterns must be valid regexes
it('rejects an invalid regular expression rule', function (): void {
    Livewire::test(BlacklistSettingsPage::class)
        ->fillForm([
            'regex_rules' => [
                ['pattern' => '/valid@pattern/'],
                ['pattern' => '(unclosed[group'],
            ],
        ])
        ->call('save')
        ->assertHasFormErrors(['regex_rules.1.pattern']);

    expect(app(BlacklistSettings::class)->refresh()->regex_rules)->toBe([]);
});

// F-3.25.5-003
it('rejects an unknown apply-at stage', function (): void {
    Livewire::test(BlacklistSettingsPage::class)
        ->fillForm(['apply_at' => 'sometimes'])
        ->call('save')
        ->assertHasFormErrors(['apply_at']);
});

it('exposes blacklist settings through the reader', function (): void {
    $settings = app(BlacklistSettings::class);
    $settings->regex_rules = ['/^spam-/i'];
    $settings->apply_at = BlacklistSettings::APPLY_AT_SEND;
    $settings->save();

    $reader = SettingsReader::blacklist();

    expect($reader->enabled())->toBeTrue()
        ->and($reader->regexRules())->toBe(['/^spam-/i'])
        ->and($reader->appliesAtImport())->toBeFalse()
        ->and($reader->appliesAtSend())->toBeTrue()
        ->and($reader->sharedAcrossCustomers())->toBeTrue()
        ->and($reader->matchesRegexRule('spam-bot@example.com'))->toBeTrue()
        ->and($reader->matchesRegexRule('friend@example.com'))->toBeFalse();
});
