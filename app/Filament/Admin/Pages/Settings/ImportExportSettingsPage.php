<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Settings;

use App\Settings\ImportExportSettings;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Import/Export settings page (spec §3.25.2, rows F-3.25.2-001 … F-3.25.2-005).
 */
class ImportExportSettingsPage extends BaseSettingsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-arrows-up-down';

    protected static ?string $navigationGroup = 'System Health';

    protected static ?string $slug = 'settings/import-export';

    protected static ?int $navigationSort = 2;

    protected static function settingsClass(): string
    {
        return ImportExportSettings::class;
    }

    public function getTitle(): string|Htmlable
    {
        return __('settings.import_export.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('settings.import_export.navigation_label');
    }

    /**
     * @return array<int, Component>
     */
    protected function schema(): array
    {
        return [
            Section::make(__('settings.import_export.sections.import_files'))
                ->columns(2)
                ->schema([
                    self::castInt(
                        TextInput::make('max_import_file_size_mb')
                            ->label(__('settings.import_export.fields.max_import_file_size_mb'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(10240)
                            ->required(),
                    ),
                    TagsInput::make('allowed_import_extensions')
                        ->label(__('settings.import_export.fields.allowed_import_extensions'))
                        ->helperText(__('settings.import_export.help.allowed_import_extensions'))
                        ->placeholder('csv')
                        ->required()
                        ->nestedRecursiveRules(['regex:/^[a-z0-9]{1,10}$/i']),
                ]),

            Section::make(__('settings.import_export.sections.import_runtime'))
                ->columns(3)
                ->schema([
                    self::castInt(
                        TextInput::make('import_batch_size')
                            ->label(__('settings.import_export.fields.import_batch_size'))
                            ->helperText(__('settings.import_export.help.import_batch_size'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(100000)
                            ->required(),
                    ),
                    self::castInt(
                        TextInput::make('import_memory_limit_mb')
                            ->label(__('settings.import_export.fields.import_memory_limit_mb'))
                            ->numeric()
                            ->integer()
                            ->minValue(16)
                            ->maxValue(16384)
                            ->required(),
                    ),
                    self::castInt(
                        TextInput::make('import_max_execution_seconds')
                            ->label(__('settings.import_export.fields.import_max_execution_seconds'))
                            ->helperText(__('settings.import_export.help.import_max_execution_seconds'))
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(86400)
                            ->required(),
                    ),
                ]),

            Section::make(__('settings.import_export.sections.import_sources'))
                ->columns(2)
                ->schema([
                    Toggle::make('import_from_url_enabled')
                        ->label(__('settings.import_export.fields.import_from_url_enabled'))
                        ->helperText(__('settings.import_export.help.import_from_url_enabled')),
                    Toggle::make('import_from_database_enabled')
                        ->label(__('settings.import_export.fields.import_from_database_enabled'))
                        ->helperText(__('settings.import_export.help.import_from_database_enabled')),
                ]),

            Section::make(__('settings.import_export.sections.export'))
                ->columns(3)
                ->schema([
                    self::castInt(
                        TextInput::make('export_batch_size')
                            ->label(__('settings.import_export.fields.export_batch_size'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(100000)
                            ->required(),
                    ),
                    self::castInt(
                        TextInput::make('export_max_rows_per_file')
                            ->label(__('settings.import_export.fields.export_max_rows_per_file'))
                            ->helperText(__('settings.import_export.help.export_max_rows_per_file'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->required(),
                    ),
                    self::castInt(
                        TextInput::make('export_retention_days')
                            ->label(__('settings.import_export.fields.export_retention_days'))
                            ->helperText(__('settings.import_export.help.export_retention_days'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(3650)
                            ->required(),
                    ),
                ]),
        ];
    }
}
