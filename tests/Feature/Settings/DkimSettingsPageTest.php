<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\Settings\DkimSettingsPage;
use App\Models\Customer;
use App\Models\User;
use App\Services\Dkim\GenerateDkimKeypair;
use App\Services\Settings\SettingsReader;
use App\Settings\DkimSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(), 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

// F-3.25.10-001
it('renders the DKIM settings page for an admin', function (): void {
    $this->get(DkimSettingsPage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('settings.dkim.title'))
        // F-3.25.10-003 — the SPF recommendation is displayed
        ->assertSee('v=spf1 a mx include:');
});

it('denies the customer guard access to the DKIM settings page', function (): void {
    auth('admin')->logout();
    $this->actingAs(Customer::factory()->create(), 'customer');

    $this->get(DkimSettingsPage::getUrl(panel: 'admin'))->assertRedirect();
});

it('shows the seeded DKIM defaults', function (): void {
    Livewire::test(DkimSettingsPage::class)
        ->assertFormSet([
            'enabled' => false,
            'selector' => 'default',
            'force_global_signing' => false,
        ]);
});

// F-3.25.10-001 / F-3.25.10-002
it('persists DKIM settings', function (): void {
    Livewire::test(DkimSettingsPage::class)
        ->fillForm([
            'enabled' => true,
            'selector' => 'mailer2026',
            'force_global_signing' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(DkimSettings::class)->refresh();

    expect($settings->enabled)->toBeTrue()
        ->and($settings->selector)->toBe('mailer2026')
        ->and($settings->force_global_signing)->toBeTrue();
});

it('rejects an invalid DKIM selector', function (): void {
    Livewire::test(DkimSettingsPage::class)
        ->fillForm(['selector' => 'not a selector!'])
        ->call('save')
        ->assertHasFormErrors(['selector']);
});

// F-3.25.10-001 — RSA-2048 keypair generation service
it('generates a working RSA-2048 keypair', function (): void {
    $pair = app(GenerateDkimKeypair::class)();

    expect($pair['private_key'])->toContain('-----BEGIN PRIVATE KEY-----')
        ->and($pair['public_key'])->toContain('-----BEGIN PUBLIC KEY-----');

    // 2048-bit modulus.
    $details = openssl_pkey_get_details(openssl_pkey_get_public($pair['public_key']));
    expect($details['bits'])->toBe(2048)
        ->and($details['type'])->toBe(OPENSSL_KEYTYPE_RSA);

    // Sign with the private key, verify with the public one.
    $signature = '';
    openssl_sign('wp-05 dkim roundtrip', $signature, $pair['private_key'], OPENSSL_ALGO_SHA256);

    expect(openssl_verify('wp-05 dkim roundtrip', $signature, $pair['public_key'], OPENSSL_ALGO_SHA256))->toBe(1);
});

// F-3.25.10-001 — page action stores the keypair (private key encrypted)
it('generates and stores the keypair from the page action', function (): void {
    Livewire::test(DkimSettingsPage::class)
        ->callAction('generateKeypair')
        ->assertHasNoActionErrors();

    $settings = app(DkimSettings::class)->refresh();

    expect($settings->private_key)->toContain('-----BEGIN PRIVATE KEY-----')
        ->and($settings->public_key)->toContain('-----BEGIN PUBLIC KEY-----');

    // Private key is encrypted at rest.
    $payload = (string) DB::table('settings')
        ->where('group', 'dkim')
        ->where('name', 'private_key')
        ->value('payload');

    expect($payload)->not->toContain('BEGIN PRIVATE KEY');
});

// F-3.25.10-001 / F-3.25.10-003 — DNS TXT record + SPF recommendation format
it('produces a valid DKIM TXT record and SPF recommendation', function (): void {
    $pair = app(GenerateDkimKeypair::class)();

    $settings = app(DkimSettings::class);
    $settings->selector = 'mailer';
    $settings->private_key = $pair['private_key'];
    $settings->public_key = $pair['public_key'];
    $settings->save();

    $reader = SettingsReader::dkim();

    expect($reader->txtRecordHost())->toBe('mailer._domainkey')
        ->and($reader->txtRecordValue())->toMatch('/^v=DKIM1; k=rsa; p=[A-Za-z0-9+\/]+={0,2}$/')
        ->and($reader->dnsTxtRecord())->toStartWith('mailer._domainkey IN TXT "v=DKIM1; k=rsa; p=')
        ->and($reader->spfRecommendation())->toMatch('/^v=spf1 a mx include:\S+ ~all$/');

    // The p= value decodes back to the generated public key (DER round-trip).
    $base64 = substr((string) $reader->txtRecordValue(), strlen('v=DKIM1; k=rsa; p='));
    $pem = "-----BEGIN PUBLIC KEY-----\n"
        .chunk_split($base64, 64, "\n")
        .'-----END PUBLIC KEY-----';

    expect(openssl_pkey_get_public($pem))->not->toBeFalse();
});

it('has no TXT record before a keypair exists', function (): void {
    expect(SettingsReader::dkim()->keypairConfigured())->toBeFalse()
        ->and(SettingsReader::dkim()->txtRecordValue())->toBeNull()
        ->and(SettingsReader::dkim()->dnsTxtRecord())->toBeNull();
});
