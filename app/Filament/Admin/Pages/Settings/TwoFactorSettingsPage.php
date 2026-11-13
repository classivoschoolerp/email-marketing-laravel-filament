<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Settings;

use App\Settings\TwoFactorSettings;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Two-factor authentication settings page (spec §3.25.6, rows
 * F-3.25.6-001 … 003). The force-toggles are enforced by
 * {@see \App\Http\Middleware\Enforce2fa}.
 */
class TwoFactorSettingsPage extends BaseSettingsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Users & Access';

    protected static ?string $slug = 'settings/2fa';

    protected static ?int $navigationSort = 6;

    protected static function settingsClass(): string
    {
        return TwoFactorSettings::class;
    }

    public function getTitle(): string|Htmlable
    {
        return __('settings.twofa.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('settings.twofa.navigation_label');
    }

    /**
     * @return array<int, Component>
     */
    protected function schema(): array
    {
        return [
            Section::make(__('settings.twofa.sections.enforcement'))
                ->description(__('settings.twofa.sections.enforcement_description'))
                ->columns(3)
                ->schema([
                    Toggle::make('enabled')
                        ->label(__('settings.twofa.fields.enabled'))
                        ->helperText(__('settings.twofa.help.enabled')),
                    Toggle::make('force_admins')
                        ->label(__('settings.twofa.fields.force_admins'))
                        ->helperText(__('settings.twofa.help.force_admins')),
                    Toggle::make('force_customers')
                        ->label(__('settings.twofa.fields.force_customers'))
                        ->helperText(__('settings.twofa.help.force_customers')),
                ]),

            Section::make(__('settings.twofa.sections.totp'))
                ->description(__('settings.twofa.sections.totp_description'))
                ->columns(3)
                ->schema([
                    self::castNullableString(
                        TextInput::make('issuer')
                            ->label(__('settings.twofa.fields.issuer'))
                            ->helperText(__('settings.twofa.help.issuer'))
                            ->maxLength(255),
                    ),
                    self::castInt(
                        TextInput::make('code_validity_window')
                            ->label(__('settings.twofa.fields.code_validity_window'))
                            ->helperText(__('settings.twofa.help.code_validity_window'))
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(10)
                            ->required(),
                    ),
                    self::castInt(
                        TextInput::make('recovery_code_count')
                            ->label(__('settings.twofa.fields.recovery_code_count'))
                            ->helperText(__('settings.twofa.help.recovery_code_count'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(24)
                            ->required(),
                    ),
                ]),
        ];
    }
}
