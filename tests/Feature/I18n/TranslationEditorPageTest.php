<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\TranslationEditorPage;
use App\Models\Language;
use App\Models\TranslationOverride;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

// WP-20 — §6.5 F-6.5-003 / §3.22 F-3.22-004: the in-app translation editor.

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(), 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('renders the translation editor for an admin', function (): void {
    $this->get(TranslationEditorPage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('i18n.editor.title'));
});

it('blocks guests from the translation editor', function (): void {
    auth('admin')->logout();

    $this->get(TranslationEditorPage::getUrl(panel: 'admin'))->assertRedirect();
});

it('loads the effective strings for the selected language and group', function (): void {
    Language::factory()->create(['code' => 'it', 'name' => 'Italiano']);

    Livewire::test(TranslationEditorPage::class)
        ->fillForm(['locale' => 'it', 'group' => 'public'])
        ->assertFormSet(function (array $state): void {
            expect($state['strings']['lists.subscribe_success'])->toBe('Grazie per esserti iscritto!');
        });
});

it('saves edited strings as overrides that win in the UI', function (): void {
    Language::factory()->create(['code' => 'it', 'name' => 'Italiano']);

    // NB: `set()` instead of `fillForm()` — the testing helper dot-flattens
    // state keys, which would explode the dotted translation keys into
    // nested arrays (the real KeyValue UI entangles the object wholesale).
    Livewire::test(TranslationEditorPage::class)
        ->fillForm(['locale' => 'it', 'group' => 'public'])
        ->set('data.strings', array_merge(
            app(\App\Services\I18n\TranslationCatalog::class)->effective('it', 'public'),
            ['lists.subscribe_success' => 'Grazie mille per l\'iscrizione!'],
        ))
        ->call('save')
        ->assertHasNoFormErrors();

    expect(TranslationOverride::query()
        ->where('locale', 'it')->where('group', 'public')->where('key', 'lists.subscribe_success')
        ->value('value'))->toBe('Grazie mille per l\'iscrizione!');

    app()->setLocale('it');
    expect(__('public.lists.subscribe_success'))->toBe('Grazie mille per l\'iscrizione!');
    app()->setLocale('en');
});

/*
 * WP-42 closing proof for F-3.22-004. The editor was built by WP-20; these two
 * assertions pin the parts of the row that were exercised only indirectly:
 * the round-trip (edit → revert) leaves NO override row behind (D-71), and a
 * key that does not exist in the reference locale is never persisted, so the
 * editor cannot be used to inject junk translation keys.
 */
it('drops the override again when a string is reverted to the file value (F-3.22-004)', function (): void {
    Language::factory()->create(['code' => 'it', 'name' => 'Italiano']);

    $catalog = app(\App\Services\I18n\TranslationCatalog::class);
    $fileValue = $catalog->fileStrings('it', 'public')['lists.subscribe_success'];

    Livewire::test(TranslationEditorPage::class)
        ->fillForm(['locale' => 'it', 'group' => 'public'])
        ->set('data.strings', array_merge($catalog->effective('it', 'public'), [
            'lists.subscribe_success' => 'Edited value',
        ]))
        ->call('save')
        ->assertHasNoFormErrors();

    expect(TranslationOverride::query()
        ->where('locale', 'it')->where('group', 'public')->where('key', 'lists.subscribe_success')
        ->count())->toBe(1);

    Livewire::test(TranslationEditorPage::class)
        ->fillForm(['locale' => 'it', 'group' => 'public'])
        ->set('data.strings', array_merge($catalog->effective('it', 'public'), [
            'lists.subscribe_success' => $fileValue,
        ]))
        ->call('save')
        ->assertHasNoFormErrors();

    expect(TranslationOverride::query()
        ->where('locale', 'it')->where('group', 'public')->where('key', 'lists.subscribe_success')
        ->count())->toBe(0);

    app()->setLocale('it');
    expect(__('public.lists.subscribe_success'))->toBe($fileValue);
    app()->setLocale('en');
});

it('refuses to persist a key that is not in the reference locale (F-3.22-004)', function (): void {
    Language::factory()->create(['code' => 'it', 'name' => 'Italiano']);

    $catalog = app(\App\Services\I18n\TranslationCatalog::class);

    Livewire::test(TranslationEditorPage::class)
        ->fillForm(['locale' => 'it', 'group' => 'public'])
        ->set('data.strings', array_merge($catalog->effective('it', 'public'), [
            'lists.totally_invented_key' => 'Junk',
        ]))
        ->call('save')
        ->assertHasNoFormErrors();

    expect(TranslationOverride::query()->where('key', 'lists.totally_invented_key')->exists())->toBeFalse();
});
