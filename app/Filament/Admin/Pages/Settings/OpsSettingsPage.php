<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Settings;

use App\Services\Reports\LogFileReader;
use App\Settings\OpsSettings;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Config;

/**
 * §6.6 operations settings (WP-24): error-notification severity, slow-query
 * recording, resource thresholds + alert email, backup retention/uploads,
 * worker staleness, the smart-cache layer and the S3 object-storage toggle.
 */
class OpsSettingsPage extends BaseSettingsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'System Health';

    protected static ?string $slug = 'settings/operations';

    protected static ?int $navigationSort = 15;

    protected static function settingsClass(): string
    {
        return OpsSettings::class;
    }

    public function getTitle(): string|Htmlable
    {
        return __('settings.ops.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('settings.ops.navigation_label');
    }

    /**
     * @return array<int, Component>
     */
    protected function schema(): array
    {
        return [
            Section::make(__('settings.ops.sections.errors'))
                ->description(__('settings.ops.sections.errors_description'))
                ->columns(2)
                ->schema([
                    Toggle::make('error_notifications_enabled')
                        ->label(__('settings.ops.fields.error_notifications_enabled')),
                    Select::make('error_notification_level')
                        ->label(__('settings.ops.fields.error_notification_level'))
                        ->options(array_combine(LogFileReader::LEVELS, LogFileReader::LEVELS))
                        ->in(LogFileReader::LEVELS)
                        ->required(),
                ]),

            Section::make(__('settings.ops.sections.performance'))
                ->description(__('settings.ops.sections.performance_description'))
                ->columns(3)
                ->schema([
                    Toggle::make('slow_query_logging')
                        ->label(__('settings.ops.fields.slow_query_logging'))
                        ->helperText(__('settings.ops.help.slow_query_logging')),
                    self::castInt(
                        TextInput::make('slow_query_threshold_ms')
                            ->label(__('settings.ops.fields.slow_query_threshold_ms'))
                            ->numeric()->integer()->minValue(1)->maxValue(60000)
                            ->required(),
                    ),
                    self::castInt(
                        TextInput::make('slow_query_retention_days')
                            ->label(__('settings.ops.fields.slow_query_retention_days'))
                            ->helperText(__('settings.ops.help.zero_forever'))
                            ->numeric()->integer()->minValue(0)->maxValue(365)
                            ->required(),
                    ),
                ]),

            Section::make(__('settings.ops.sections.thresholds'))
                ->description(__('settings.ops.sections.thresholds_description'))
                ->columns(3)
                ->schema([
                    self::castInt(
                        TextInput::make('disk_free_percent_warning')
                            ->label(__('settings.ops.fields.disk_free_percent_warning'))
                            ->numeric()->integer()->minValue(0)->maxValue(100)
                            ->required(),
                    ),
                    self::castInt(
                        TextInput::make('memory_percent_warning')
                            ->label(__('settings.ops.fields.memory_percent_warning'))
                            ->numeric()->integer()->minValue(0)->maxValue(100)
                            ->required(),
                    ),
                    self::castInt(
                        TextInput::make('database_size_mb_warning')
                            ->label(__('settings.ops.fields.database_size_mb_warning'))
                            ->numeric()->integer()->minValue(0)
                            ->required(),
                    ),
                    Toggle::make('threshold_email_enabled')
                        ->label(__('settings.ops.fields.threshold_email_enabled')),
                    self::castNullableString(
                        TextInput::make('threshold_email_to')
                            ->label(__('settings.ops.fields.threshold_email_to'))
                            ->email()
                            ->maxLength(255),
                    ),
                ]),

            Section::make(__('settings.ops.sections.backups'))
                ->description(__('settings.ops.sections.backups_description'))
                ->columns(3)
                ->schema([
                    Toggle::make('backup_include_uploads')
                        ->label(__('settings.ops.fields.backup_include_uploads')),
                    self::castInt(
                        TextInput::make('backup_retention_days')
                            ->label(__('settings.ops.fields.backup_retention_days'))
                            ->helperText(__('settings.ops.help.zero_forever'))
                            ->numeric()->integer()->minValue(0)->maxValue(3650)
                            ->required(),
                    ),
                    TextInput::make('backup_time')
                        ->label(__('settings.ops.fields.backup_time'))
                        ->helperText(__('settings.ops.help.backup_time'))
                        ->regex('/^([01]\d|2[0-3]):[0-5]\d$/')
                        ->required(),
                ]),

            Section::make(__('settings.ops.sections.workers'))
                ->columns(2)
                ->schema([
                    self::castInt(
                        TextInput::make('worker_stale_seconds')
                            ->label(__('settings.ops.fields.worker_stale_seconds'))
                            ->helperText(__('settings.ops.help.worker_stale_seconds'))
                            ->numeric()->integer()->minValue(10)->maxValue(3600)
                            ->required(),
                    ),
                ]),

            Section::make(__('settings.ops.sections.cache'))
                ->description(__('settings.ops.sections.cache_description'))
                ->columns(2)
                ->schema([
                    Toggle::make('smart_cache_enabled')
                        ->label(__('settings.ops.fields.smart_cache_enabled')),
                    self::castInt(
                        TextInput::make('smart_cache_ttl_seconds')
                            ->label(__('settings.ops.fields.smart_cache_ttl_seconds'))
                            ->helperText(__('settings.ops.help.smart_cache_ttl_seconds'))
                            ->numeric()->integer()->minValue(0)->maxValue(86400)
                            ->required(),
                    ),
                ]),

            Section::make(__('settings.ops.sections.storage'))
                ->description(__('settings.ops.sections.storage_description'))
                ->columns(2)
                ->schema([
                    Toggle::make('object_storage_enabled')
                        ->label(__('settings.ops.fields.object_storage_enabled'))
                        ->helperText(__('settings.ops.help.object_storage_enabled')),
                    Select::make('object_storage_disk')
                        ->label(__('settings.ops.fields.object_storage_disk'))
                        ->options(fn (): array => self::diskOptions())
                        ->in(fn (): array => array_keys(self::diskOptions()))
                        ->required(),
                ]),
        ];
    }

    /** @return array<string, string> */
    private static function diskOptions(): array
    {
        $disks = array_keys((array) Config::get('filesystems.disks', []));

        return array_combine($disks, $disks) ?: ['local' => 'local'];
    }
}
