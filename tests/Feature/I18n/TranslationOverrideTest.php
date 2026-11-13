<?php

declare(strict_types=1);

use App\Models\TranslationOverride;
use App\Services\I18n\LanguagePackExporter;
use App\Services\I18n\TranslationCatalog;

// WP-20 — §6.5 F-6.5-003/004: DB-backed translation overrides through the
// decorated loader, and the effective-strings pack export.

it('lets a stored override win over the lang file string', function (): void {
    expect(__('public.lists.subscribe_success'))->toBe('Thank you for subscribing!');

    TranslationOverride::query()->create([
        'locale' => 'en',
        'group' => 'public',
        'key' => 'lists.subscribe_success',
        'value' => 'Custom thanks!',
    ]);
    app(TranslationCatalog::class)->flush();

    expect(__('public.lists.subscribe_success'))->toBe('Custom thanks!');

    // Untouched keys still come from the file.
    expect(__('public.lists.profile_updated'))->toBe('Your profile has been updated.');
});

it('scans groups from the reference locale lang files', function (): void {
    $groups = app(TranslationCatalog::class)->groups();

    expect($groups)->toContain('public')
        ->toContain('lists')
        ->toContain('auth-module')
        ->toContain('system-emails');
});

it('computes effective strings as override > locale file > reference file', function (): void {
    $catalog = app(TranslationCatalog::class);

    // Italian file value wins over the English reference…
    expect($catalog->effective('it', 'public')['lists.subscribe_success'])
        ->toBe('Grazie per esserti iscritto!');

    // …an override beats the file…
    TranslationOverride::query()->create([
        'locale' => 'it', 'group' => 'public', 'key' => 'lists.subscribe_success', 'value' => 'Grazie mille!',
    ]);

    expect($catalog->effective('it', 'public')['lists.subscribe_success'])->toBe('Grazie mille!');

    // …and keys missing from the locale fall back to the reference string.
    $effective = $catalog->effective('xx', 'public');
    expect($effective['lists.subscribe_success'])->toBe('Thank you for subscribing!');
});

it('stores only real deviations and drops overrides reverted to the file value', function (): void {
    $catalog = app(TranslationCatalog::class);

    $catalog->save('en', 'public', [
        'lists.subscribe_success' => 'Changed!',
        'lists.profile_updated' => 'Your profile has been updated.', // identical → no row
        'not.a.real.key' => 'junk', // unknown → ignored
    ]);

    expect(TranslationOverride::query()->count())->toBe(1)
        ->and(__('public.lists.subscribe_success'))->toBe('Changed!');

    // Reverting to the file value removes the override row.
    $catalog->save('en', 'public', ['lists.subscribe_success' => 'Thank you for subscribing!']);

    expect(TranslationOverride::query()->count())->toBe(0)
        ->and(__('public.lists.subscribe_success'))->toBe('Thank you for subscribing!');
});

it('exports a language pack zip of the effective strings that round-trips', function (): void {
    TranslationOverride::query()->create([
        'locale' => 'it', 'group' => 'public', 'key' => 'lists.subscribe_success', 'value' => 'Grazie mille!',
    ]);

    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'emp-pack-'.uniqid();
    $path = app(LanguagePackExporter::class)->export('it', $dir);

    expect(is_file($path))->toBeTrue();

    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();

    $content = (string) $zip->getFromName('it/public.php');
    $zip->close();

    $tmp = $dir.DIRECTORY_SEPARATOR.'roundtrip.php';
    file_put_contents($tmp, $content);

    $lines = require $tmp;

    expect($lines)->toBeArray()
        ->and($lines['lists']['subscribe_success'])->toBe('Grazie mille!')
        // Italian file strings are present too.
        ->and($lines['lists']['profile_updated'])->toBe('Il tuo profilo è stato aggiornato.');

    @unlink($path);
    @unlink($tmp);
    @rmdir($dir);
});
