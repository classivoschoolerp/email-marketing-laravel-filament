<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\Settings\SkinSettingsPage;
use App\Models\Customer;
use App\Models\User;
use App\Services\Settings\SettingsReader;
use App\Settings\SkinSettings;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(), 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

// F-3.25.8-001
it('renders the skin settings page for an admin', function (): void {
    $this->get(SkinSettingsPage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('settings.skin.title'));
});

it('denies the customer guard access to the skin settings page', function (): void {
    auth('admin')->logout();
    $this->actingAs(Customer::factory()->create(), 'customer');

    $this->get(SkinSettingsPage::getUrl(panel: 'admin'))->assertRedirect();
});

it('shows the seeded skin defaults', function (): void {
    Livewire::test(SkinSettingsPage::class)
        ->assertFormSet([
            'skin_backend' => 'default',
            'skin_customer' => 'default',
            'skin_frontend' => 'default',
            'custom_css_backend' => null,
            'custom_header_html' => null,
            'hide_branding' => false,
        ]);
});

// F-3.25.8-001 … F-3.25.8-004
it('persists skin settings', function (): void {
    Livewire::test(SkinSettingsPage::class)
        ->fillForm([
            'skin_backend' => 'classic',
            'skin_customer' => 'modern',
            'skin_frontend' => 'default',
            'custom_css_backend' => '.topbar { background: #123456; }',
            'custom_css_customer' => '.cust { color: red; }',
            'custom_css_frontend' => 'body { font-size: 15px; }',
            'custom_js_backend' => 'console.log("backend");',
            'custom_js_customer' => 'console.log("customer");',
            'custom_js_frontend' => 'console.log("frontend");',
            'custom_header_html' => '<header>ACME</header>',
            'custom_footer_html' => '<footer>ACME Inc.</footer>',
            'hide_branding' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(SkinSettings::class)->refresh();

    expect($settings->skin_backend)->toBe('classic')
        ->and($settings->skin_customer)->toBe('modern')
        ->and($settings->custom_css_backend)->toBe('.topbar { background: #123456; }')
        ->and($settings->custom_js_customer)->toBe('console.log("customer");')
        ->and($settings->custom_header_html)->toBe('<header>ACME</header>')
        ->and($settings->custom_footer_html)->toBe('<footer>ACME Inc.</footer>')
        ->and($settings->hide_branding)->toBeTrue();
});

// F-3.25.8-001 — only known skin slugs are accepted
it('rejects an unknown skin slug', function (): void {
    Livewire::test(SkinSettingsPage::class)
        ->fillForm(['skin_backend' => 'not-a-skin'])
        ->call('save')
        ->assertHasFormErrors(['skin_backend']);
});

// F-3.25.8-002 — live injection into the admin panel
it('injects backend custom css and js into the admin panel', function (): void {
    $settings = app(SkinSettings::class);
    $settings->custom_css_backend = '.wp05-admin-css-marker { color: #bada55; }';
    $settings->custom_js_backend = 'window.__wp05AdminJsMarker = true;';
    $settings->custom_css_customer = '.wp05-customer-css-marker { color: #c0ffee; }';
    $settings->save();

    $response = $this->get('/admin')->assertOk();

    $response->assertSee('.wp05-admin-css-marker', escape: false)
        ->assertSee('<style data-skin-area="backend">', escape: false)
        ->assertSee('window.__wp05AdminJsMarker', escape: false)
        ->assertSee('<script data-skin-area="backend">', escape: false)
        // The customer-area CSS must not leak into the admin panel.
        ->assertDontSee('.wp05-customer-css-marker', escape: false);
});

// F-3.25.8-002 — live injection into the customer panel
it('injects customer custom css and js into the customer panel', function (): void {
    $settings = app(SkinSettings::class);
    $settings->custom_css_customer = '.wp05-customer-css-marker { color: #c0ffee; }';
    $settings->custom_js_customer = 'window.__wp05CustomerJsMarker = true;';
    $settings->custom_css_backend = '.wp05-admin-css-marker { color: #bada55; }';
    $settings->save();

    auth('admin')->logout();
    $this->actingAs(Customer::factory()->create(), 'customer');

    $this->get('/customer')
        ->assertOk()
        ->assertSee('.wp05-customer-css-marker', escape: false)
        ->assertSee('<style data-skin-area="customer">', escape: false)
        ->assertSee('window.__wp05CustomerJsMarker', escape: false)
        ->assertDontSee('.wp05-admin-css-marker', escape: false);
});

it('injects nothing when no custom css or js is configured', function (): void {
    $this->get('/admin')
        ->assertOk()
        ->assertDontSee('data-skin-area', escape: false);
});

// F-3.25.8-002/003/004 — reader for the themes work package
it('exposes skin settings through the reader', function (): void {
    $settings = app(SkinSettings::class);
    $settings->custom_css_frontend = 'body { color: black; }';
    $settings->custom_header_html = '<header>H</header>';
    $settings->custom_footer_html = '<footer>F</footer>';
    $settings->hide_branding = true;
    $settings->save();

    $reader = SettingsReader::skin();

    expect($reader->skin('backend'))->toBe('default')
        ->and($reader->customCss('frontend'))->toBe('body { color: black; }')
        ->and($reader->customJs('frontend'))->toBeNull()
        ->and($reader->headerHtml())->toBe('<header>H</header>')
        ->and($reader->footerHtml())->toBe('<footer>F</footer>')
        ->and($reader->hideBranding())->toBeTrue();

    expect(fn () => $reader->skin('nope'))->toThrow(InvalidArgumentException::class);
});
