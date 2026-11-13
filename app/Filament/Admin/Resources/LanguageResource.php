<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\LanguageResource\Pages;
use App\Models\Language;
use App\Services\I18n\LanguagePackExporter;
use App\Services\I18n\LanguagePackImporter;
use App\Services\I18n\Locales;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Installed languages admin (spec §3.22 F-3.22-001 / §6.5 F-6.5-004, WP-20):
 * list installed packs, upload a pack (zip of lang files), create a new pack
 * from the reference locale, export the effective strings, set the
 * installation default (writes the CommonSettings value — the source of
 * truth — and mirrors is_default) and toggle/flag RTL packs.
 */
class LanguageResource extends Resource
{
    protected static ?string $model = Language::class;

    protected static ?string $navigationIcon = 'heroicon-o-language';

    protected static ?string $slug = 'languages';

    protected static ?int $navigationSort = 43;

    public static function getNavigationGroup(): ?string
    {
        return __('ops.navigation.location');
    }

    public static function getNavigationLabel(): string
    {
        return __('i18n.languages.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('i18n.languages.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('i18n.languages.plural_label');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label(__('i18n.languages.code'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('i18n.languages.name'))
                    ->searchable(),
                Tables\Columns\IconColumn::make('rtl')
                    ->label(__('i18n.languages.rtl'))
                    ->boolean(),
                Tables\Columns\IconColumn::make('enabled')
                    ->label(__('i18n.languages.enabled'))
                    ->boolean(),
                Tables\Columns\IconColumn::make('is_default')
                    ->label(__('i18n.languages.default'))
                    ->boolean(),
            ])
            ->defaultSort('code')
            ->actions([
                Tables\Actions\EditAction::make()
                    ->form([
                        Forms\Components\TextInput::make('name')
                            ->label(__('i18n.languages.name'))
                            ->required()
                            ->maxLength(64),
                        Forms\Components\Toggle::make('rtl')
                            ->label(__('i18n.languages.rtl')),
                        Forms\Components\Toggle::make('enabled')
                            ->label(__('i18n.languages.enabled'))
                            ->disabled(fn (Language $record): bool => $record->is_default)
                            ->helperText(fn (Language $record): ?string => $record->is_default
                                ? __('i18n.languages.default_always_enabled')
                                : null),
                    ]),
                Tables\Actions\Action::make('set_default')
                    ->label(__('i18n.languages.set_default'))
                    ->icon('heroicon-m-star')
                    ->requiresConfirmation()
                    ->visible(fn (Language $record): bool => $record->enabled && ! $record->is_default)
                    ->action(function (Language $record): void {
                        // CommonSettings::default_language is the source of
                        // truth; is_default only mirrors it (WP-20 contract).
                        Locales::setDefault($record->code);

                        Notification::make()->success()
                            ->title(__('i18n.languages.default_set', ['code' => $record->code]))
                            ->send();
                    }),
                Tables\Actions\Action::make('export')
                    ->label(__('i18n.languages.export'))
                    ->icon('heroicon-m-arrow-down-tray')
                    ->action(function (Language $record): BinaryFileResponse {
                        $path = app(LanguagePackExporter::class)->export($record->code);

                        return response()->download($path)->deleteFileAfterSend();
                    }),
                Tables\Actions\DeleteAction::make()
                    // The reference pack and the current default are
                    // load-bearing — removing the row (files stay on disk)
                    // is only offered for other packs.
                    ->visible(fn (Language $record): bool => ! $record->is_default
                        && $record->code !== (string) config('app.fallback_locale', 'en')),
            ])
            ->headerActions([
                Tables\Actions\Action::make('upload_pack')
                    ->label(__('i18n.languages.upload'))
                    ->icon('heroicon-m-arrow-up-tray')
                    ->form([
                        Forms\Components\TextInput::make('code')
                            ->label(__('i18n.languages.code'))
                            ->required()
                            ->maxLength(12)
                            ->regex('/^[a-z0-9_\-]+$/'),
                        Forms\Components\TextInput::make('name')
                            ->label(__('i18n.languages.name'))
                            ->required()
                            ->maxLength(64),
                        Forms\Components\Toggle::make('rtl')
                            ->label(__('i18n.languages.rtl')),
                        Forms\Components\FileUpload::make('file')
                            ->label(__('i18n.languages.pack_file'))
                            ->disk('local')
                            ->directory('language-packs')
                            ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed'])
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        try {
                            $count = app(LanguagePackImporter::class)->import(
                                Storage::disk('local')->path((string) $data['file']),
                                (string) $data['code'],
                            );
                        } catch (ValidationException $exception) {
                            Notification::make()->danger()
                                ->title(collect($exception->errors())->flatten()->first())
                                ->send();

                            return;
                        } finally {
                            Storage::disk('local')->delete((string) $data['file']);
                        }

                        Language::query()->updateOrCreate(
                            ['code' => (string) $data['code']],
                            ['name' => (string) $data['name'], 'rtl' => (bool) ($data['rtl'] ?? false)],
                        );
                        Locales::syncDefaultFlag();

                        Notification::make()->success()
                            ->title(__('i18n.languages.uploaded', ['count' => $count]))
                            ->send();
                    }),
                Tables\Actions\Action::make('create_pack')
                    ->label(__('i18n.languages.create'))
                    ->icon('heroicon-m-plus')
                    ->form([
                        Forms\Components\TextInput::make('code')
                            ->label(__('i18n.languages.code'))
                            ->required()
                            ->maxLength(12)
                            ->regex('/^[a-z0-9_\-]+$/')
                            ->unique('languages', 'code'),
                        Forms\Components\TextInput::make('name')
                            ->label(__('i18n.languages.name'))
                            ->required()
                            ->maxLength(64),
                        Forms\Components\Toggle::make('rtl')
                            ->label(__('i18n.languages.rtl')),
                    ])
                    ->action(function (array $data): void {
                        // New pack = the reference locale's files copied as
                        // the starting point (F-6.5-004).
                        app(LanguagePackImporter::class)->createFromReference((string) $data['code']);

                        Language::query()->create([
                            'code' => (string) $data['code'],
                            'name' => (string) $data['name'],
                            'rtl' => (bool) ($data['rtl'] ?? false),
                            'enabled' => true,
                        ]);

                        Notification::make()->success()
                            ->title(__('i18n.languages.created'))
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLanguages::route('/'),
        ];
    }
}
