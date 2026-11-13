<?php

declare(strict_types=1);

use App\Models\SystemEmailTemplate;
use App\Services\SystemEmails\SystemEmailRenderer;

// WP-20 — §3.15 system email templates: resolution layering per key.

it('renders a non-empty translatable default for every system email key', function (): void {
    $renderer = app(SystemEmailRenderer::class);

    foreach (SystemEmailRenderer::keys() as $key) {
        $rendered = $renderer->resolve($key);

        expect($rendered->subject)->not->toBe('')
            ->and($rendered->subject)->not->toContain('system-emails.') // lang key resolved
            ->and($rendered->html)->not->toBe('')
            ->and($rendered->html)->not->toContain('system-emails.')
            ->and($rendered->subject)->not->toContain('[APP_NAME]')
            ->and($rendered->html)->not->toContain('[APP_NAME]');
    }
});

it('documents merge tags for every key and substitutes them escaped', function (): void {
    foreach (SystemEmailRenderer::keys() as $key) {
        expect(SystemEmailRenderer::tags($key))->not->toBeEmpty();
    }

    $rendered = app(SystemEmailRenderer::class)->resolve('campaign-finished', [
        'CAMPAIGN_NAME' => '<script>alert(1)</script>',
        'SENT_COUNT' => '10',
        'FAILED_COUNT' => '0',
        'TOTAL_COUNT' => '10',
    ]);

    expect($rendered->html)->not->toContain('<script>alert(1)</script>')
        ->and($rendered->html)->toContain('&lt;script&gt;')
        ->and($rendered->html)->toContain('10');
});

it('lets a stored override win over the default (subject and body)', function (): void {
    app(SystemEmailRenderer::class)->saveOverride(
        'campaign-finished',
        'Custom subject for [CAMPAIGN_NAME]',
        '<p>Custom body: [SENT_COUNT] sent.</p>',
    );

    $rendered = app(SystemEmailRenderer::class)->resolve('campaign-finished', [
        'CAMPAIGN_NAME' => 'Spring Sale',
        'SENT_COUNT' => '42',
        'FAILED_COUNT' => '0',
        'TOTAL_COUNT' => '42',
    ]);

    expect($rendered->subject)->toBe('Custom subject for Spring Sale')
        ->and($rendered->html)->toBe('<p>Custom body: 42 sent.</p>');
});

it('restores the translatable default on reset', function (): void {
    $renderer = app(SystemEmailRenderer::class);
    $default = $renderer->resolve('password-reset', ['RESET_URL' => 'https://x.test', 'EXPIRES_MINUTES' => 60]);

    $renderer->saveOverride('password-reset', 'Overridden', '<p>Overridden</p>');
    $renderer->resetOverride('password-reset');

    expect(SystemEmailTemplate::query()->where('key', 'password-reset')->exists())->toBeFalse();

    $restored = $renderer->resolve('password-reset', ['RESET_URL' => 'https://x.test', 'EXPIRES_MINUTES' => 60]);

    expect($restored->subject)->toBe($default->subject)
        ->and($restored->html)->toBe($default->html);
});

it('falls back to the default content when the override is disabled — the email still renders', function (): void {
    $renderer = app(SystemEmailRenderer::class);
    $renderer->saveOverride('quota-reached', 'Disabled subject', '<p>Disabled body</p>', enabled: false);

    $rendered = $renderer->resolve('quota-reached', ['USED' => '10', 'LIMIT' => '10', 'PERCENT' => 100]);

    expect($rendered->subject)->not->toBe('Disabled subject')
        ->and($rendered->html)->not->toContain('Disabled body')
        ->and($rendered->subject)->toBe(__('system-emails.defaults.quota-reached.subject'))
        ->and($rendered->html)->not->toBe('');
});

it('falls back per column when an override leaves subject or body blank', function (): void {
    app(SystemEmailRenderer::class)->saveOverride('customer-welcome', 'Only the subject', null);

    $rendered = app(SystemEmailRenderer::class)->resolve('customer-welcome', [
        'FIRST_NAME' => 'Ada', 'LAST_NAME' => 'Lovelace', 'LOGIN_URL' => 'https://x.test/login',
    ]);

    expect($rendered->subject)->toBe('Only the subject')
        ->and($rendered->html)->toContain('Ada');
});

it('rejects unknown keys on resolve and save', function (): void {
    expect(fn () => app(SystemEmailRenderer::class)->resolve('nope'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => app(SystemEmailRenderer::class)->saveOverride('nope', 'a', 'b'))
        ->toThrow(InvalidArgumentException::class);
});

it('renders defaults in the active locale with english fallback', function (): void {
    app()->setLocale('ja');

    // No ja system-emails.php shipped — falls back to English (F-6.5-001
    // fallback discipline) instead of exploding. [APP_NAME] is substituted.
    $rendered = app(SystemEmailRenderer::class)->resolve('password-changed');

    expect($rendered->subject)->toContain('password was changed')
        ->and($rendered->subject)->not->toContain('[APP_NAME]');

    app()->setLocale('en');
});
