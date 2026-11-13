<?php

declare(strict_types=1);

use App\Models\EmailList;
use App\Models\User;
use App\Settings\PrivacySettings;
use Filament\Facades\Filament;

/*
 * WP-22 — F-3.25.16-001 / F-4.5-006: cookie consent banner on PUBLIC pages.
 */

beforeEach(function (): void {
    $this->list = EmailList::factory()->create(['visible' => true, 'status' => EmailList::STATUS_ACTIVE]);
    $this->url = route('lists.public.subscribe', ['list_uid' => $this->list->uid]);
});

it('renders no banner while the setting is off', function (): void {
    $this->get($this->url)
        ->assertOk()
        ->assertDontSee('emp-cookie-consent');
});

// F-4.5-006
it('renders the banner on a public page when enabled', function (): void {
    $settings = app(PrivacySettings::class);
    $settings->cookie_consent_enabled = true;
    $settings->cookie_consent_text = 'Cookies keep this site running.';
    $settings->cookie_policy_url = 'https://example.com/cookie-policy';
    $settings->save();

    $this->get($this->url)
        ->assertOk()
        ->assertSee('emp-cookie-consent')
        ->assertSee('Cookies keep this site running.')
        ->assertSee('https://example.com/cookie-policy')
        ->assertSee(__('privacy.cookies.accept'))
        ->assertSee(__('privacy.cookies.decline'));
});

it('falls back to the built-in wording when no text is configured', function (): void {
    $settings = app(PrivacySettings::class);
    $settings->cookie_consent_enabled = true;
    $settings->save();

    $this->get($this->url)->assertSee(__('privacy.cookies.default_text'));
});

// F-3.25.16-001 — the visitor's choice is stored and the banner disappears.
it('stores the visitor choice in a cookie and stops showing the banner', function (): void {
    $settings = app(PrivacySettings::class);
    $settings->cookie_consent_enabled = true;
    $settings->save();

    $response = $this->from($this->url)->post(route('privacy.cookie_consent'), ['choice' => 'accepted']);

    $response->assertRedirect($this->url)
        ->assertCookie(config('emp-privacy.cookies.cookie_name'), 'accepted');

    $this->withCookie(config('emp-privacy.cookies.cookie_name'), 'accepted')
        ->get($this->url)
        ->assertOk()
        ->assertDontSee('emp-cookie-consent');
});

it('normalises any other choice value to declined', function (): void {
    $this->post(route('privacy.cookie_consent'), ['choice' => 'whatever'])
        ->assertCookie(config('emp-privacy.cookies.cookie_name'), 'declined');
});

// The banner belongs to the public frontend only — never the panels.
it('never renders the banner inside the admin panel', function (): void {
    $settings = app(PrivacySettings::class);
    $settings->cookie_consent_enabled = true;
    $settings->save();

    $this->actingAs(User::factory()->create(), 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->get('/admin')->assertSuccessful()->assertDontSee('emp-cookie-consent');
});
