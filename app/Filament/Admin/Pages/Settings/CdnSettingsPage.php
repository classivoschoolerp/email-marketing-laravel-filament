<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Settings;

use App\Settings\CdnSettings;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Illuminate\Contracts\Support\Htmlable;

/**
 * CDN settings page (spec §3.25.9, rows F-3.25.9-001 … 002).
 */
class CdnSettingsPage extends BaseSettingsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationGroup = 'System Health';

    protected static ?string $slug = 'settings/cdn';

    protected static ?int $navigationSort = 9;

    protected static function settingsClass(): string
    {
        return CdnSettings::class;
    }

    public function getTitle(): string|Htmlable
    {
        return __('settings.cdn.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('settings.cdn.navigation_label');
    }

    /**
     * @return array<int, Component>
     */
    protected function schema(): array
    {
        return [
            Section::make(__('settings.cdn.sections.general'))
                ->description(__('settings.cdn.sections.general_description'))
                ->columns(2)
                ->schema([
                    Toggle::make('enabled')
                        ->label(__('settings.cdn.fields.enabled'))
                        ->helperText(__('settings.cdn.help.enabled')),
                    self::castNullableString(
                        TextInput::make('base_url')
                            ->label(__('settings.cdn.fields.base_url'))
                            ->helperText(__('settings.cdn.help.base_url'))
                            ->url()
                            ->placeholder('https://cdn.example.com')
                            ->maxLength(255),
                    ),
                    self::castNullableString(
                        TextInput::make('subdomain')
                            ->label(__('settings.cdn.fields.subdomain'))
                            ->helperText(__('settings.cdn.help.subdomain'))
                            ->placeholder('cdn')
                            ->maxLength(63)
                            ->alphaDash(),
                    ),
                    Toggle::make('use_for_assets')
                        ->label(__('settings.cdn.fields.use_for_assets'))
                        ->helperText(__('settings.cdn.help.use_for_assets')),
                    Toggle::make('use_for_email_images')
                        ->label(__('settings.cdn.fields.use_for_email_images'))
                        ->helperText(__('settings.cdn.help.use_for_email_images')),
                ]),

            Section::make(__('settings.cdn.sections.provider'))
                ->description(__('settings.cdn.sections.provider_description'))
                ->columns(2)
                ->schema([
                    Select::make('provider')
                        ->label(__('settings.cdn.fields.provider'))
                        ->options([
                            CdnSettings::PROVIDER_CUSTOM => __('settings.cdn.options.custom'),
                            CdnSettings::PROVIDER_S3_CLOUDFRONT => __('settings.cdn.options.s3-cloudfront'),
                            CdnSettings::PROVIDER_BUNNY => __('settings.cdn.options.bunny'),
                        ])
                        ->in(CdnSettings::PROVIDERS)
                        ->live()
                        ->required()
                        ->columnSpanFull(),

                    // S3 / CloudFront (F-3.25.9-002)
                    self::castNullableString(
                        TextInput::make('s3_bucket')
                            ->label(__('settings.cdn.fields.s3_bucket'))
                            ->maxLength(255)
                            ->visible(self::providerIs(CdnSettings::PROVIDER_S3_CLOUDFRONT)),
                    ),
                    self::castNullableString(
                        TextInput::make('s3_region')
                            ->label(__('settings.cdn.fields.s3_region'))
                            ->placeholder('eu-central-1')
                            ->maxLength(64)
                            ->visible(self::providerIs(CdnSettings::PROVIDER_S3_CLOUDFRONT)),
                    ),
                    self::castNullableString(
                        TextInput::make('s3_access_key_id')
                            ->label(__('settings.cdn.fields.s3_access_key_id'))
                            ->maxLength(255)
                            ->visible(self::providerIs(CdnSettings::PROVIDER_S3_CLOUDFRONT)),
                    ),
                    self::castNullableString(
                        TextInput::make('s3_secret_access_key')
                            ->label(__('settings.cdn.fields.s3_secret_access_key'))
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->visible(self::providerIs(CdnSettings::PROVIDER_S3_CLOUDFRONT)),
                    ),
                    self::castNullableString(
                        TextInput::make('cloudfront_distribution_id')
                            ->label(__('settings.cdn.fields.cloudfront_distribution_id'))
                            ->maxLength(64)
                            ->visible(self::providerIs(CdnSettings::PROVIDER_S3_CLOUDFRONT)),
                    ),

                    // Bunny (F-3.25.9-002)
                    self::castNullableString(
                        TextInput::make('bunny_storage_zone')
                            ->label(__('settings.cdn.fields.bunny_storage_zone'))
                            ->maxLength(255)
                            ->visible(self::providerIs(CdnSettings::PROVIDER_BUNNY)),
                    ),
                    self::castNullableString(
                        TextInput::make('bunny_api_key')
                            ->label(__('settings.cdn.fields.bunny_api_key'))
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->visible(self::providerIs(CdnSettings::PROVIDER_BUNNY)),
                    ),
                    self::castNullableString(
                        TextInput::make('bunny_pull_zone')
                            ->label(__('settings.cdn.fields.bunny_pull_zone'))
                            ->maxLength(255)
                            ->visible(self::providerIs(CdnSettings::PROVIDER_BUNNY)),
                    ),
                ]),
        ];
    }

    protected static function providerIs(string $provider): \Closure
    {
        return fn (Get $get): bool => $get('provider') === $provider;
    }
}
