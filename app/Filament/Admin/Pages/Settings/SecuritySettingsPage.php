<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Settings;

use App\Settings\SecuritySettings;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Security settings page (spec §3.25.15, rows F-3.25.15-001 … 007, WP-22).
 * Every value is consumed at runtime — see {@see SecuritySettings} for the
 * consumer of each row.
 */
class SecuritySettingsPage extends BaseSettingsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-lock-closed';

    protected static ?string $navigationGroup = 'Users & Access';

    protected static ?string $slug = 'settings/security';

    protected static ?int $navigationSort = 15;

    protected static function settingsClass(): string
    {
        return SecuritySettings::class;
    }

    public function getTitle(): string|Htmlable
    {
        return __('settings.security.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('settings.security.navigation_label');
    }

    /**
     * @return array<int, Component>
     */
    protected function schema(): array
    {
        return [
            // F-3.25.15-001
            Section::make(__('settings.security.sections.login'))
                ->description(__('settings.security.sections.login_description'))
                ->columns(4)
                ->schema([
                    self::castInt(
                        TextInput::make('login_max_attempts')
                            ->label(__('settings.security.fields.login_max_attempts'))
                            ->helperText(__('settings.security.help.login_max_attempts'))
                            ->numeric()->integer()->minValue(1)->maxValue(100)->required(),
                    ),
                    self::castInt(
                        TextInput::make('login_lockout_minutes')
                            ->label(__('settings.security.fields.login_lockout_minutes'))
                            ->numeric()->integer()->minValue(1)->maxValue(1440)->required(),
                    ),
                    self::castInt(
                        TextInput::make('login_ip_max_attempts')
                            ->label(__('settings.security.fields.login_ip_max_attempts'))
                            ->helperText(__('settings.security.help.login_ip_max_attempts'))
                            ->numeric()->integer()->minValue(1)->maxValue(1000)->required(),
                    ),
                    self::castInt(
                        TextInput::make('login_ip_lockout_minutes')
                            ->label(__('settings.security.fields.login_ip_lockout_minutes'))
                            ->numeric()->integer()->minValue(1)->maxValue(1440)->required(),
                    ),
                ]),

            // F-3.25.15-002
            Section::make(__('settings.security.sections.sessions'))
                ->description(__('settings.security.sections.sessions_description'))
                ->columns(2)
                ->schema([
                    self::castInt(
                        TextInput::make('session_lifetime_minutes')
                            ->label(__('settings.security.fields.session_lifetime_minutes'))
                            ->numeric()->integer()->minValue(1)->maxValue(43200)->required(),
                    ),
                    self::castInt(
                        TextInput::make('session_idle_timeout_minutes')
                            ->label(__('settings.security.fields.session_idle_timeout_minutes'))
                            ->helperText(__('settings.security.help.session_idle_timeout_minutes'))
                            ->numeric()->integer()->minValue(0)->maxValue(43200)->required(),
                    ),
                ]),

            // F-3.25.15-003
            Section::make(__('settings.security.sections.passwords'))
                ->description(__('settings.security.sections.passwords_description'))
                ->columns(3)
                ->schema([
                    self::castInt(
                        TextInput::make('password_min_length')
                            ->label(__('settings.security.fields.password_min_length'))
                            ->numeric()->integer()->minValue(6)->maxValue(128)->required(),
                    ),
                    self::castInt(
                        TextInput::make('password_expiry_days')
                            ->label(__('settings.security.fields.password_expiry_days'))
                            ->helperText(__('settings.security.help.password_expiry_days'))
                            ->numeric()->integer()->minValue(0)->maxValue(3650)->required(),
                    ),
                    self::castInt(
                        TextInput::make('password_reuse_prevention')
                            ->label(__('settings.security.fields.password_reuse_prevention'))
                            ->helperText(__('settings.security.help.password_reuse_prevention'))
                            ->numeric()->integer()->minValue(0)->maxValue(24)->required(),
                    ),
                    Toggle::make('password_require_mixed_case')
                        ->label(__('settings.security.fields.password_require_mixed_case')),
                    Toggle::make('password_require_numbers')
                        ->label(__('settings.security.fields.password_require_numbers')),
                    Toggle::make('password_require_symbols')
                        ->label(__('settings.security.fields.password_require_symbols')),
                ]),

            // F-3.25.15-004
            Section::make(__('settings.security.sections.transport'))
                ->description(__('settings.security.sections.transport_description'))
                ->columns(3)
                ->schema([
                    Toggle::make('force_https')
                        ->label(__('settings.security.fields.force_https'))
                        ->helperText(__('settings.security.help.force_https')),
                    Toggle::make('hsts_enabled')
                        ->label(__('settings.security.fields.hsts_enabled')),
                    self::castInt(
                        TextInput::make('hsts_max_age')
                            ->label(__('settings.security.fields.hsts_max_age'))
                            ->numeric()->integer()->minValue(0)->maxValue(63072000)->required(),
                    ),
                    Toggle::make('hsts_include_subdomains')
                        ->label(__('settings.security.fields.hsts_include_subdomains')),
                    Toggle::make('hsts_preload')
                        ->label(__('settings.security.fields.hsts_preload'))
                        ->helperText(__('settings.security.help.hsts_preload')),
                ]),

            // F-3.25.15-005
            Section::make(__('settings.security.sections.admin_ips'))
                ->description(__('settings.security.sections.admin_ips_description'))
                ->schema([
                    TagsInput::make('admin_allowed_ips')
                        ->label(__('settings.security.fields.admin_allowed_ips'))
                        ->helperText(__('settings.security.help.admin_allowed_ips'))
                        ->placeholder('203.0.113.4')
                        ->reorderable(false),
                ]),

            // F-3.25.15-006
            Section::make(__('settings.security.sections.uploads'))
                ->description(__('settings.security.sections.uploads_description'))
                ->columns(2)
                ->schema([
                    TagsInput::make('upload_allowed_extensions')
                        ->label(__('settings.security.fields.upload_allowed_extensions'))
                        ->helperText(__('settings.security.help.upload_allowed_extensions'))
                        ->columnSpanFull()
                        ->reorderable(false),
                    self::castInt(
                        TextInput::make('upload_max_size_mb')
                            ->label(__('settings.security.fields.upload_max_size_mb'))
                            ->numeric()->integer()->minValue(0)->maxValue(2048)->required(),
                    ),
                    Toggle::make('upload_scan_enabled')
                        ->label(__('settings.security.fields.upload_scan_enabled'))
                        ->helperText(__('settings.security.help.upload_scan_enabled')),
                    Select::make('upload_scanner_driver')
                        ->label(__('settings.security.fields.upload_scanner_driver'))
                        ->options([
                            'null' => __('settings.security.scanners.null'),
                            'clamav' => __('settings.security.scanners.clamav'),
                        ])
                        ->required(),
                    TextInput::make('upload_scanner_host')
                        ->label(__('settings.security.fields.upload_scanner_host'))
                        ->helperText(__('settings.security.help.upload_scanner_host'))
                        ->maxLength(191)
                        ->required(),
                    self::castInt(
                        TextInput::make('upload_scanner_port')
                            ->label(__('settings.security.fields.upload_scanner_port'))
                            ->numeric()->integer()->minValue(1)->maxValue(65535)->required(),
                    ),
                ]),

            // F-3.25.15-007
            Section::make(__('settings.security.sections.audit'))
                ->description(__('settings.security.sections.audit_description'))
                ->schema([
                    self::castInt(
                        TextInput::make('audit_log_retention_days')
                            ->label(__('settings.security.fields.audit_log_retention_days'))
                            ->helperText(__('settings.security.help.audit_log_retention_days'))
                            ->numeric()->integer()->minValue(0)->maxValue(3650)->required(),
                    ),
                ]),
        ];
    }
}
