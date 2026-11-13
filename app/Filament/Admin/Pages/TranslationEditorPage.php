<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Services\I18n\LanguagePackExporter;
use App\Services\I18n\Locales;
use App\Services\I18n\TranslationCatalog;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * In-app translation string editor (spec §6.5 F-6.5-003 / §3.22 F-3.22-004,
 * WP-20): pick a language + group (scanned from the reference locale's lang
 * files), edit any string, save. Changed values land in translation_overrides
 * (loaded through OverrideTranslationLoader — upgrade-safe; lang/ files are
 * never written); values reverted to the file string drop their override row.
 * The header action exports the effective strings as a language pack zip.
 */
class TranslationEditorPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?string $slug = 'translation-editor';

    protected static ?int $navigationSort = 44;

    public static function getNavigationGroup(): ?string
    {
        return __('ops.navigation.location');
    }

    protected static string $view = 'filament.admin.pages.translation-editor';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $locale = array_key_first($this->localeOptions()) ?? 'en';
        $group = app(TranslationCatalog::class)->groups()[0] ?? '';

        $this->form->fill(['locale' => $locale, 'group' => $group]);
        $this->loadStrings();
    }

    public function getTitle(): string|Htmlable
    {
        return __('i18n.editor.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('i18n.editor.navigation_label');
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_pack')
                ->label(__('i18n.editor.export'))
                ->icon('heroicon-m-arrow-down-tray')
                ->action(function (): BinaryFileResponse {
                    $locale = (string) ($this->data['locale'] ?? 'en');
                    $path = app(LanguagePackExporter::class)->export($locale);

                    return response()->download($path)->deleteFileAfterSend();
                }),
        ];
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\Select::make('locale')
                    ->label(__('i18n.editor.language'))
                    ->options($this->localeOptions())
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn () => $this->loadStrings()),
                Forms\Components\Select::make('group')
                    ->label(__('i18n.editor.group'))
                    ->options(array_combine(
                        app(TranslationCatalog::class)->groups(),
                        app(TranslationCatalog::class)->groups(),
                    ))
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn () => $this->loadStrings()),
            ]),
            Forms\Components\KeyValue::make('strings')
                ->label(__('i18n.editor.strings'))
                ->keyLabel(__('i18n.editor.key'))
                ->valueLabel(__('i18n.editor.value'))
                ->editableKeys(false)
                ->addable(false)
                ->deletable(false),
        ]);
    }

    /** Load the effective strings of the selected locale+group. */
    public function loadStrings(): void
    {
        $locale = (string) (($this->data['locale'] ?? null) ?: 'en');
        $group = (string) (($this->data['group'] ?? null) ?: '');

        $this->data = array_merge((array) $this->data, [
            'strings' => $group === ''
                ? []
                : app(TranslationCatalog::class)->effective($locale, $group),
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        app(TranslationCatalog::class)->save(
            (string) $state['locale'],
            (string) $state['group'],
            (array) ($state['strings'] ?? []),
        );

        Notification::make()->title(__('i18n.editor.saved'))->success()->send();
    }

    /** @return array<string, string> */
    private function localeOptions(): array
    {
        $options = Locales::installed()
            ->mapWithKeys(fn ($language): array => [$language->code => $language->name])
            ->all();

        return $options === [] ? ['en' => 'English'] : $options;
    }
}
