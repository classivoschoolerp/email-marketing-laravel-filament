<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Settings;

use App\Providers\SkinServiceProvider;
use App\Services\Themes\ThemeRegistry;
use App\Settings\SkinSettings;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Customisation / skins settings page (spec §3.25.8, rows
 * F-3.25.8-001 … 004). Panel CSS/JS injection is live via
 * {@see SkinServiceProvider}.
 */
class SkinSettingsPage extends BaseSettingsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-paint-brush';

    protected static ?string $navigationGroup = 'Appearance';

    protected static ?string $slug = 'settings/skins';

    protected static ?int $navigationSort = 8;

    protected static function settingsClass(): string
    {
        return SkinSettings::class;
    }

    public function getTitle(): string|Htmlable
    {
        return __('settings.skin.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('settings.skin.navigation_label');
    }

    /**
     * @return array<int, Component>
     */
    protected function schema(): array
    {
        return [
            Section::make(__('settings.skin.sections.skins'))
                ->description(__('settings.skin.sections.skins_description'))
                ->columns(3)
                ->schema([
                    Select::make('skin_backend')
                        ->label(__('settings.skin.fields.skin_backend'))
                        ->options(self::skinOptions(SkinSettings::AREA_BACKEND))
                        ->in(self::skinSlugs(SkinSettings::AREA_BACKEND))
                        ->required(),
                    Select::make('skin_customer')
                        ->label(__('settings.skin.fields.skin_customer'))
                        ->options(self::skinOptions(SkinSettings::AREA_CUSTOMER))
                        ->in(self::skinSlugs(SkinSettings::AREA_CUSTOMER))
                        ->required(),
                    Select::make('skin_frontend')
                        ->label(__('settings.skin.fields.skin_frontend'))
                        ->options(self::skinOptions(SkinSettings::AREA_FRONTEND))
                        ->in(self::skinSlugs(SkinSettings::AREA_FRONTEND))
                        ->required(),
                ]),

            Section::make(__('settings.skin.sections.custom_css'))
                ->description(__('settings.skin.sections.custom_css_description'))
                ->columns(1)
                ->collapsed()
                ->schema([
                    self::castNullableString(
                        Textarea::make('custom_css_backend')
                            ->label(__('settings.skin.fields.custom_css_backend'))
                            ->rows(4),
                    ),
                    self::castNullableString(
                        Textarea::make('custom_css_customer')
                            ->label(__('settings.skin.fields.custom_css_customer'))
                            ->rows(4),
                    ),
                    self::castNullableString(
                        Textarea::make('custom_css_frontend')
                            ->label(__('settings.skin.fields.custom_css_frontend'))
                            ->rows(4),
                    ),
                ]),

            Section::make(__('settings.skin.sections.custom_js'))
                ->description(__('settings.skin.sections.custom_js_description'))
                ->columns(1)
                ->collapsed()
                ->schema([
                    self::castNullableString(
                        Textarea::make('custom_js_backend')
                            ->label(__('settings.skin.fields.custom_js_backend'))
                            ->rows(4),
                    ),
                    self::castNullableString(
                        Textarea::make('custom_js_customer')
                            ->label(__('settings.skin.fields.custom_js_customer'))
                            ->rows(4),
                    ),
                    self::castNullableString(
                        Textarea::make('custom_js_frontend')
                            ->label(__('settings.skin.fields.custom_js_frontend'))
                            ->rows(4),
                    ),
                ]),

            Section::make(__('settings.skin.sections.header_footer'))
                ->description(__('settings.skin.sections.header_footer_description'))
                ->columns(1)
                ->collapsed()
                ->schema([
                    self::castNullableString(
                        Textarea::make('custom_header_html')
                            ->label(__('settings.skin.fields.custom_header_html'))
                            ->rows(4),
                    ),
                    self::castNullableString(
                        Textarea::make('custom_footer_html')
                            ->label(__('settings.skin.fields.custom_footer_html'))
                            ->rows(4),
                    ),
                ]),

            Section::make(__('settings.skin.sections.white_label'))
                ->schema([
                    Toggle::make('hide_branding')
                        ->label(__('settings.skin.fields.hide_branding'))
                        ->helperText(__('settings.skin.help.hide_branding')),
                ]),
        ];
    }

    /**
     * WP-38 (§6.3): the option list now comes from the THEME REGISTRY rather
     * than the WP-05 `SkinSettings::SKINS` constant, so the seven built-in
     * palettes and any uploaded theme package are selectable here as well as
     * in the branding customiser. The legacy `default`/`classic`/`modern`
     * slugs are still registered, so a stored selection never becomes invalid.
     *
     * @return array<string, string>
     */
    protected static function skinOptions(?string $area = null): array
    {
        return app(ThemeRegistry::class)->options($area);
    }

    /**
     * @return list<string>
     */
    protected static function skinSlugs(?string $area = null): array
    {
        return $area === null
            ? app(ThemeRegistry::class)->slugs()
            : app(ThemeRegistry::class)->slugsForArea($area);
    }
}
