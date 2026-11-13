<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\SystemEmailTemplatesPage;
use App\Models\SystemEmailTemplate;
use App\Models\User;
use App\Services\SystemEmails\SystemEmailRenderer;
use Filament\Facades\Filament;
use Livewire\Livewire;

// WP-20 — §3.15 F-3.15-016: the admin editor for every system email key.

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(), 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('renders the system email templates page for an admin with every key listed', function (): void {
    $this->get(SystemEmailTemplatesPage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('system-emails.page.title'))
        ->assertSee(__('system-emails.labels.registration-confirmation'))
        ->assertSee(__('system-emails.labels.admin-heartbeat-stale'));
});

it('blocks guests from the system email templates page', function (): void {
    auth('admin')->logout();

    $this->get(SystemEmailTemplatesPage::getUrl(panel: 'admin'))->assertRedirect();
});

it('preloads the default subject and body for the selected key', function (): void {
    Livewire::test(SystemEmailTemplatesPage::class)
        ->assertFormSet([
            'key' => SystemEmailRenderer::keys()[0],
            'subject' => app(SystemEmailRenderer::class)->defaultSubject(SystemEmailRenderer::keys()[0]),
            'enabled' => true,
        ]);
});

it('saves an override that the renderer then prefers', function (): void {
    Livewire::test(SystemEmailTemplatesPage::class)
        ->fillForm([
            'key' => 'campaign-finished',
            'subject' => 'Finished! [CAMPAIGN_NAME]',
            'body' => '<p>Campaign [CAMPAIGN_NAME] is done.</p>',
            'enabled' => true,
            'html_mode' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(SystemEmailTemplate::query()->where('key', 'campaign-finished')->exists())->toBeTrue();

    $rendered = app(SystemEmailRenderer::class)->resolve('campaign-finished', [
        'CAMPAIGN_NAME' => 'Promo', 'SENT_COUNT' => 1, 'FAILED_COUNT' => 0, 'TOTAL_COUNT' => 1,
    ]);

    expect($rendered->subject)->toBe('Finished! Promo')
        ->and($rendered->html)->toBe('<p>Campaign Promo is done.</p>');
});

it('resets a key back to the built-in default', function (): void {
    app(SystemEmailRenderer::class)->saveOverride('export-ready', 'Overridden', '<p>Overridden</p>');

    Livewire::test(SystemEmailTemplatesPage::class)
        ->fillForm(['key' => 'export-ready'])
        ->call('resetToDefault');

    expect(SystemEmailTemplate::query()->where('key', 'export-ready')->exists())->toBeFalse();
});

it('keeps sending with default content when the override is disabled', function (): void {
    Livewire::test(SystemEmailTemplatesPage::class)
        ->fillForm([
            'key' => 'quota-warning',
            'subject' => 'Should not be used',
            'body' => '<p>Should not be used</p>',
            'enabled' => false,
            'html_mode' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $rendered = app(SystemEmailRenderer::class)->resolve('quota-warning', [
        'USED' => 1, 'LIMIT' => 10, 'PERCENT' => 10,
    ]);

    expect($rendered->subject)->not->toBe('Should not be used')
        ->and($rendered->html)->not->toContain('Should not be used');
});
