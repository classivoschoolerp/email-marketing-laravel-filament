<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Language;
use App\Models\User;
use Database\Seeders\LanguageSeeder;

// WP-20 — §3.22 F-3.22-002/003 + §6.5 F-6.5-005/006/007: locale resolution
// per admin user / customer / public list, RTL, and CJK end-to-end.

beforeEach(function (): void {
    $this->seed(LanguageSeeder::class);
});

it('applies the customer locale on the customer panel (per-tenant override)', function (): void {
    $customer = Customer::factory()->create(['locale' => 'it']);

    $this->actingAs($customer, 'customer')
        ->get('/customer')
        ->assertOk();

    expect(app()->getLocale())->toBe('it');
});

it('applies the admin user locale on the admin panel (per-user selection)', function (): void {
    $user = User::factory()->create();
    $user->forceFill(['locale' => 'ja'])->save();

    $this->actingAs($user, 'admin')
        ->get('/admin')
        ->assertOk();

    expect(app()->getLocale())->toBe('ja');
});

it('falls back to the installation default when the account locale is not installed', function (): void {
    $customer = Customer::factory()->create(['locale' => 'zz']);

    $this->actingAs($customer, 'customer')
        ->get('/customer')
        ->assertOk();

    expect(app()->getLocale())->toBe('en');
});

it('ignores disabled language packs', function (): void {
    Language::query()->where('code', 'it')->update(['enabled' => false]);

    $customer = Customer::factory()->create(['locale' => 'it']);

    $this->actingAs($customer, 'customer')
        ->get('/customer')
        ->assertOk();

    expect(app()->getLocale())->toBe('en');
});

it('renders public list pages in the list default language with correct CJK output (F-6.5-007)', function (): void {
    $customer = Customer::factory()->create();
    $list = EmailList::factory()->for($customer)->create(['default_language' => 'ja']);

    $response = $this->get(route('lists.public.subscribe', ['list_uid' => $list->uid]));

    $response->assertOk()
        // Default subscribe-form content from lang/ja/lists.php with
        // [LIST_NAME] substituted — multibyte survives end-to-end.
        ->assertSee($list->name.' に登録する')
        // Form labels from lang/ja/lists.php embed strings.
        ->assertSee('メールアドレス')
        ->assertSee('登録する')
        // LTR pack → dir="ltr" on the public layout.
        ->assertSee('dir="ltr"', false);

    expect(app()->getLocale())->toBe('ja');
});

it('renders italian public pages for italian lists', function (): void {
    $customer = Customer::factory()->create();
    $list = EmailList::factory()->for($customer)->create(['default_language' => 'it']);

    $this->get(route('lists.public.subscribe', ['list_uid' => $list->uid]))
        ->assertOk()
        ->assertSee('Iscriviti a '.$list->name)
        ->assertSee('Indirizzo email');
});

it('applies dir=rtl on public pages for RTL language packs (F-3.22-003)', function (): void {
    Language::query()->create(['code' => 'ar', 'name' => 'العربية', 'rtl' => true, 'enabled' => true]);

    $customer = Customer::factory()->create();
    $list = EmailList::factory()->for($customer)->create(['default_language' => 'ar']);

    // No lang/ar files exist — strings fall back to English, but the layout
    // direction follows the pack's RTL flag.
    $this->get(route('lists.public.subscribe', ['list_uid' => $list->uid]))
        ->assertOk()
        ->assertSee('dir="rtl"', false);
});

it('injects the rtl direction hook into panels for RTL locales', function (): void {
    Language::query()->create(['code' => 'ar', 'name' => 'العربية', 'rtl' => true, 'enabled' => true]);

    $customer = Customer::factory()->create(['locale' => 'ar']);

    $this->actingAs($customer, 'customer')
        ->get('/customer')
        ->assertOk()
        ->assertSee('document.documentElement.setAttribute("dir", "rtl")', false);
});

it('uses the CommonSettings default language for guests', function (): void {
    \App\Services\I18n\Locales::setDefault('it');

    $this->get(route('block_request.form'))->assertOk();

    expect(app()->getLocale())->toBe('it');
});
