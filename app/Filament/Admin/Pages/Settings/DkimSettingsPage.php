<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Settings;

use App\Services\Dkim\GenerateDkimKeypair;
use App\Services\Settings\SettingsReader;
use App\Settings\DkimSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * SPF/DKIM settings page (spec §3.25.10, rows F-3.25.10-001 … 003).
 */
class DkimSettingsPage extends BaseSettingsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-finger-print';
    protected static ?string $navigationGroup = 'Sending Infrastructure';

    protected static ?string $slug = 'settings/dkim';

    protected static ?int $navigationSort = 10;

    protected static function settingsClass(): string
    {
        return DkimSettings::class;
    }

    public function getTitle(): string|Htmlable
    {
        return __('settings.dkim.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('settings.dkim.navigation_label');
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateKeypair')
                ->label(__('settings.dkim.actions.generate'))
                ->icon('heroicon-o-key')
                ->requiresConfirmation()
                ->modalHeading(__('settings.dkim.actions.generate_heading'))
                ->modalDescription(__('settings.dkim.actions.generate_confirm'))
                ->action(function (GenerateDkimKeypair $generator): void {
                    $pair = $generator();

                    $settings = app(DkimSettings::class);
                    $settings->private_key = $pair['private_key'];
                    $settings->public_key = $pair['public_key'];
                    $settings->save();

                    Notification::make()
                        ->success()
                        ->title(__('settings.dkim.actions.generated'))
                        ->send();
                }),
        ];
    }

    /**
     * @return array<int, Component>
     */
    protected function schema(): array
    {
        return [
            Section::make(__('settings.dkim.sections.dkim'))
                ->description(__('settings.dkim.sections.dkim_description'))
                ->columns(3)
                ->schema([
                    Toggle::make('enabled')
                        ->label(__('settings.dkim.fields.enabled'))
                        ->helperText(__('settings.dkim.help.enabled')),
                    TextInput::make('selector')
                        ->label(__('settings.dkim.fields.selector'))
                        ->helperText(__('settings.dkim.help.selector'))
                        ->required()
                        ->alphaDash()
                        ->maxLength(63),
                    Toggle::make('force_global_signing')
                        ->label(__('settings.dkim.fields.force_global_signing'))
                        ->helperText(__('settings.dkim.help.force_global_signing')),
                ]),

            Section::make(__('settings.dkim.sections.dns'))
                ->description(__('settings.dkim.sections.dns_description'))
                ->columns(1)
                ->schema([
                    Placeholder::make('keypair_status')
                        ->label(__('settings.dkim.fields.keypair_status'))
                        ->content(fn (): string => SettingsReader::dkim()->keypairConfigured()
                            ? __('settings.dkim.status.keypair_present')
                            : __('settings.dkim.status.keypair_missing')),
                    Placeholder::make('dkim_txt_host')
                        ->label(__('settings.dkim.fields.dkim_txt_host'))
                        ->content(fn (): string => SettingsReader::dkim()->txtRecordHost())
                        ->visible(fn (): bool => SettingsReader::dkim()->keypairConfigured()),
                    Placeholder::make('dkim_txt_value')
                        ->label(__('settings.dkim.fields.dkim_txt_value'))
                        ->content(fn (): Htmlable => new HtmlString(
                            '<code style="word-break: break-all;">'
                            .e((string) SettingsReader::dkim()->txtRecordValue())
                            .'</code>'
                        ))
                        ->visible(fn (): bool => SettingsReader::dkim()->keypairConfigured()),
                    Placeholder::make('spf_recommendation')
                        ->label(__('settings.dkim.fields.spf_recommendation'))
                        ->helperText(__('settings.dkim.help.spf_recommendation'))
                        ->content(fn (): Htmlable => new HtmlString(
                            '<code>'.e(SettingsReader::dkim()->spfRecommendation()).'</code>'
                        )),
                ]),
        ];
    }
}
