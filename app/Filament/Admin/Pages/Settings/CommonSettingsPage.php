<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Settings;

use App\Settings\CommonSettings;
use DateTimeZone;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\File;

/**
 * Common settings page (spec §3.25.1, rows F-3.25.1-001 … F-3.25.1-013).
 */
class CommonSettingsPage extends BaseSettingsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Appearance';

    protected static ?string $slug = 'settings/common';

    protected static ?int $navigationSort = 1;

    protected static function settingsClass(): string
    {
        return CommonSettings::class;
    }

    public function getTitle(): string|Htmlable
    {
        return __('settings.common.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('settings.common.navigation_label');
    }

    /**
     * @return array<int, Component>
     */
    protected function schema(): array
    {
        return [
            Section::make(__('settings.common.sections.site'))
                ->description(__('settings.common.sections.site_description'))
                ->columns(2)
                ->schema([
                    TextInput::make('site_name')
                        ->label(__('settings.common.fields.site_name'))
                        ->required()
                        ->maxLength(255),
                    self::castNullableString(
                        TextInput::make('site_tagline')
                            ->label(__('settings.common.fields.site_tagline'))
                            ->maxLength(255),
                    ),
                    self::castNullableString(
                        Textarea::make('site_description')
                            ->label(__('settings.common.fields.site_description'))
                            ->rows(2)
                            ->maxLength(1000),
                    ),
                    self::castNullableString(
                        Textarea::make('site_keywords')
                            ->label(__('settings.common.fields.site_keywords'))
                            ->helperText(__('settings.common.help.site_keywords'))
                            ->rows(2)
                            ->maxLength(1000),
                    ),
                ]),

            Section::make(__('settings.common.sections.branding'))
                ->description(__('settings.common.sections.branding_description'))
                ->columns(2)
                ->schema([
                    FileUpload::make('logo_path')
                        ->label(__('settings.common.fields.logo_path'))
                        ->disk('public')
                        ->directory('branding')
                        ->visibility('public')
                        ->image()
                        ->maxSize(2048),
                    FileUpload::make('favicon_path')
                        ->label(__('settings.common.fields.favicon_path'))
                        ->helperText(__('settings.common.help.favicon_path'))
                        ->disk('public')
                        ->directory('branding')
                        ->visibility('public')
                        ->acceptedFileTypes([
                            'image/x-icon',
                            'image/vnd.microsoft.icon',
                            'image/png',
                            'image/svg+xml',
                        ])
                        ->maxSize(512),
                ]),

            Section::make(__('settings.common.sections.company'))
                ->description(__('settings.common.sections.company_description'))
                ->columns(2)
                ->schema([
                    self::castNullableString(
                        TextInput::make('company_name')
                            ->label(__('settings.common.fields.company_name'))
                            ->maxLength(255),
                    ),
                    self::castNullableString(
                        TextInput::make('company_vat')
                            ->label(__('settings.common.fields.company_vat'))
                            ->maxLength(64),
                    ),
                    self::castNullableString(
                        Textarea::make('company_address')
                            ->label(__('settings.common.fields.company_address'))
                            ->rows(2)
                            ->maxLength(1000),
                    ),
                    self::castNullableString(
                        TextInput::make('company_phone')
                            ->label(__('settings.common.fields.company_phone'))
                            ->tel()
                            ->maxLength(64),
                    ),
                    self::castNullableString(
                        TextInput::make('company_email')
                            ->label(__('settings.common.fields.company_email'))
                            ->email()
                            ->maxLength(255),
                    ),
                ]),

            Section::make(__('settings.common.sections.localization'))
                ->columns(2)
                ->schema([
                    Select::make('default_timezone')
                        ->label(__('settings.common.fields.default_timezone'))
                        ->options(self::timezoneOptions())
                        ->in(DateTimeZone::listIdentifiers())
                        ->searchable()
                        ->required(),
                    Select::make('default_language')
                        ->label(__('settings.common.fields.default_language'))
                        ->options(self::languageOptions())
                        ->in(array_keys(self::languageOptions()))
                        ->required(),
                    Select::make('date_format')
                        ->label(__('settings.common.fields.date_format'))
                        ->options(self::dateFormatOptions())
                        ->in(array_keys(self::dateFormatOptions()))
                        ->required(),
                    Select::make('time_format')
                        ->label(__('settings.common.fields.time_format'))
                        ->options(self::timeFormatOptions())
                        ->in(array_keys(self::timeFormatOptions()))
                        ->required(),
                    self::castInt(
                        Select::make('first_day_of_week')
                            ->label(__('settings.common.fields.first_day_of_week'))
                            ->options(self::dayOfWeekOptions())
                            ->in(array_keys(self::dayOfWeekOptions()))
                            ->required(),
                    ),
                ]),

            Section::make(__('settings.common.sections.behaviour'))
                ->columns(2)
                ->schema([
                    Toggle::make('clean_urls')
                        ->label(__('settings.common.fields.clean_urls'))
                        ->helperText(__('settings.common.help.clean_urls')),
                    Toggle::make('auto_update')
                        ->label(__('settings.common.fields.auto_update'))
                        ->helperText(__('settings.common.help.auto_update')),
                    self::castInt(
                        Select::make('grid_page_size')
                            ->label(__('settings.common.fields.grid_page_size'))
                            ->helperText(__('settings.common.help.grid_page_size'))
                            ->options([10 => '10', 25 => '25', 50 => '50', 100 => '100'])
                            ->in([10, 25, 50, 100])
                            ->required(),
                    ),
                ]),

            Section::make(__('settings.common.sections.offline'))
                ->description(__('settings.common.sections.offline_description'))
                ->columns(2)
                ->schema([
                    Toggle::make('site_offline')
                        ->label(__('settings.common.fields.site_offline'))
                        ->helperText(__('settings.common.help.site_offline')),
                    Toggle::make('api_offline')
                        ->label(__('settings.common.fields.api_offline'))
                        ->helperText(__('settings.common.help.api_offline')),
                    Textarea::make('site_offline_message')
                        ->label(__('settings.common.fields.site_offline_message'))
                        ->rows(2)
                        ->required()
                        ->maxLength(1000),
                    TagsInput::make('maintenance_allowed_ips')
                        ->label(__('settings.common.fields.maintenance_allowed_ips'))
                        ->helperText(__('settings.common.help.maintenance_allowed_ips'))
                        ->placeholder('203.0.113.10')
                        ->nestedRecursiveRules(['ip']),
                ]),

            Section::make(__('settings.common.sections.integrations'))
                ->columns(2)
                ->schema([
                    self::castNullableString(
                        TextInput::make('recaptcha_site_key')
                            ->label(__('settings.common.fields.recaptcha_site_key'))
                            ->maxLength(255),
                    ),
                    self::castNullableString(
                        TextInput::make('recaptcha_secret_key')
                            ->label(__('settings.common.fields.recaptcha_secret_key'))
                            ->password()
                            ->revealable()
                            ->maxLength(255),
                    ),
                    TagsInput::make('admin_notification_emails')
                        ->label(__('settings.common.fields.admin_notification_emails'))
                        ->helperText(__('settings.common.help.admin_notification_emails'))
                        ->placeholder('admin@example.com')
                        ->nestedRecursiveRules(['email'])
                        ->columnSpanFull(),
                ]),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected static function timezoneOptions(): array
    {
        $identifiers = DateTimeZone::listIdentifiers();

        return array_combine($identifiers, $identifiers);
    }

    /**
     * @return array<string, string>
     */
    protected static function languageOptions(): array
    {
        $locales = collect(File::isDirectory(base_path('lang')) ? File::directories(base_path('lang')) : [])
            ->map(fn (string $directory): string => basename($directory))
            ->push('en')
            ->unique()
            ->sort()
            ->values();

        return $locales->combine($locales)->all();
    }

    /**
     * @return array<string, string>
     */
    protected static function dateFormatOptions(): array
    {
        return collect(['Y-m-d', 'd/m/Y', 'm/d/Y', 'd.m.Y', 'j M Y', 'M j, Y'])
            ->mapWithKeys(fn (string $format): array => [$format => $format.' — '.now()->format($format)])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    protected static function timeFormatOptions(): array
    {
        return collect(['H:i', 'H:i:s', 'g:i A'])
            ->mapWithKeys(fn (string $format): array => [$format => $format.' — '.now()->format($format)])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected static function dayOfWeekOptions(): array
    {
        return [
            0 => __('settings.days.sunday'),
            1 => __('settings.days.monday'),
            2 => __('settings.days.tuesday'),
            3 => __('settings.days.wednesday'),
            4 => __('settings.days.thursday'),
            5 => __('settings.days.friday'),
            6 => __('settings.days.saturday'),
        ];
    }
}
