<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Settings;

use App\Settings\PrivacySettings;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Privacy / GDPR settings page (spec §3.25.16, rows F-3.25.16-001 … 005,
 * plus the §4.5 portability + CAN-SPAM switches). WP-22.
 */
class PrivacySettingsPage extends BaseSettingsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-finger-print';

    protected static ?string $navigationGroup = 'Privacy & Compliance';

    protected static ?string $slug = 'settings/privacy';

    protected static ?int $navigationSort = 16;

    protected static function settingsClass(): string
    {
        return PrivacySettings::class;
    }

    public function getTitle(): string|Htmlable
    {
        return __('settings.privacy.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('settings.privacy.navigation_label');
    }

    /**
     * @return array<int, Component>
     */
    protected function schema(): array
    {
        return [
            // F-3.25.16-001
            Section::make(__('settings.privacy.sections.cookies'))
                ->description(__('settings.privacy.sections.cookies_description'))
                ->columns(2)
                ->schema([
                    Toggle::make('cookie_consent_enabled')
                        ->label(__('settings.privacy.fields.cookie_consent_enabled'))
                        ->columnSpanFull(),
                    self::castNullableString(
                        Textarea::make('cookie_consent_text')
                            ->label(__('settings.privacy.fields.cookie_consent_text'))
                            ->helperText(__('settings.privacy.help.cookie_consent_text'))
                            ->rows(3)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ),
                    self::castNullableString(
                        TextInput::make('cookie_policy_url')
                            ->label(__('settings.privacy.fields.cookie_policy_url'))
                            ->url()
                            ->maxLength(2048)
                            ->columnSpanFull(),
                    ),
                ]),

            // F-3.25.16-002
            Section::make(__('settings.privacy.sections.retention'))
                ->description(__('settings.privacy.sections.retention_description'))
                ->columns(4)
                ->schema([
                    self::castNullableInt(
                        TextInput::make('retention_delivery_log_days')
                            ->label(__('settings.privacy.fields.retention_delivery_log_days'))
                            ->numeric()->integer()->minValue(0)->maxValue(3650),
                    ),
                    self::castNullableInt(
                        TextInput::make('retention_open_days')
                            ->label(__('settings.privacy.fields.retention_open_days'))
                            ->numeric()->integer()->minValue(0)->maxValue(3650),
                    ),
                    self::castNullableInt(
                        TextInput::make('retention_click_days')
                            ->label(__('settings.privacy.fields.retention_click_days'))
                            ->numeric()->integer()->minValue(0)->maxValue(3650),
                    ),
                    self::castNullableInt(
                        TextInput::make('retention_export_days')
                            ->label(__('settings.privacy.fields.retention_export_days'))
                            ->helperText(__('settings.privacy.help.retention_export_days'))
                            ->numeric()->integer()->minValue(0)->maxValue(3650),
                    ),
                ]),

            // F-3.25.16-003 + §4.5 portability
            Section::make(__('settings.privacy.sections.rights'))
                ->description(__('settings.privacy.sections.rights_description'))
                ->columns(2)
                ->schema([
                    Toggle::make('erasure_workflow_enabled')
                        ->label(__('settings.privacy.fields.erasure_workflow_enabled'))
                        ->helperText(__('settings.privacy.help.erasure_workflow_enabled')),
                    Toggle::make('data_export_enabled')
                        ->label(__('settings.privacy.fields.data_export_enabled'))
                        ->helperText(__('settings.privacy.help.data_export_enabled')),
                ]),

            // F-3.25.16-005 + §4.5 CAN-SPAM
            Section::make(__('settings.privacy.sections.compliance'))
                ->description(__('settings.privacy.sections.compliance_description'))
                ->columns(2)
                ->schema([
                    Toggle::make('anonymize_ips')
                        ->label(__('settings.privacy.fields.anonymize_ips'))
                        ->helperText(__('settings.privacy.help.anonymize_ips')),
                    Toggle::make('canspam_enforce_address')
                        ->label(__('settings.privacy.fields.canspam_enforce_address'))
                        ->helperText(__('settings.privacy.help.canspam_enforce_address')),
                ]),

            // F-3.25.16-004 + §4.5 sub-processor list
            Section::make(__('settings.privacy.sections.legal'))
                ->description(__('settings.privacy.sections.legal_description'))
                ->schema([
                    self::castNullableString(
                        Textarea::make('dpa_text')
                            ->label(__('settings.privacy.fields.dpa_text'))
                            ->helperText(__('settings.privacy.help.dpa_text'))
                            ->rows(10),
                    ),
                    self::castNullableString(
                        Textarea::make('sub_processors_text')
                            ->label(__('settings.privacy.fields.sub_processors_text'))
                            ->helperText(__('settings.privacy.help.sub_processors_text'))
                            ->rows(6),
                    ),
                ]),
        ];
    }
}
