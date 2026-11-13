<?php

declare(strict_types=1);

use App\Settings\PrivacySettings;

/*
 * WP-22 — F-3.25.16-004 (DPA text) + F-4.5-001 (sub-processor list): both
 * published as public /legal pages.
 */

it('shows a placeholder while no DPA text is published', function (): void {
    $this->get(route('privacy.legal.dpa'))
        ->assertOk()
        ->assertSee(__('privacy.legal.dpa_title'))
        ->assertSee(__('privacy.legal.dpa_missing'));
});

// F-3.25.16-004
it('publishes the configured DPA text at /legal/dpa', function (): void {
    $settings = app(PrivacySettings::class);
    $settings->dpa_text = "This Data Processing Agreement governs processing.\nAnnex I lists the data.";
    $settings->save();

    $this->get('/legal/dpa')
        ->assertOk()
        ->assertSee('This Data Processing Agreement governs processing.')
        ->assertSee('Annex I lists the data.')
        ->assertDontSee(__('privacy.legal.dpa_missing'));
});

// §4.5 sub-processor list
it('publishes the sub-processor list at /legal/sub-processors', function (): void {
    $this->get('/legal/sub-processors')
        ->assertOk()
        ->assertSee(__('privacy.legal.sub_processors_missing'));

    $settings = app(PrivacySettings::class);
    $settings->sub_processors_text = "Acme Cloud — hosting (EU)\nBeta Mail — delivery (US)";
    $settings->save();

    $this->get('/legal/sub-processors')
        ->assertOk()
        ->assertSee(__('privacy.legal.sub_processors_intro'))
        ->assertSee('Acme Cloud')
        ->assertSee('Beta Mail');
});

// Operator-authored text is page content, but never raw HTML.
it('escapes markup in the published legal text', function (): void {
    $settings = app(PrivacySettings::class);
    $settings->dpa_text = '<script>alert(1)</script>';
    $settings->save();

    $response = $this->get('/legal/dpa');

    $response->assertOk();
    expect($response->getContent())->not->toContain('<script>alert(1)</script>');
});
