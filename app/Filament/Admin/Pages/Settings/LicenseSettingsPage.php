<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Settings;

use App\Services\License\LicenseService;
use App\Services\Settings\SettingsReader;
use App\Settings\LicenseSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * License settings page (spec §3.25.12, rows F-3.25.12-001 … 003, D-09).
 *
 * The update/renew flow: enter a (new) key, hit "Validate license" — the
 * stored status/type/expiry/domains refresh from the license server, and the
 * renewal link reported by the server is displayed below.
 */
class LicenseSettingsPage extends BaseSettingsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-check-badge';

    protected static ?string $navigationGroup = 'System Health';

    protected static ?string $slug = 'settings/license';

    protected static ?int $navigationSort = 13;

    protected static function settingsClass(): string
    {
        return LicenseSettings::class;
    }

    public function getTitle(): string|Htmlable
    {
        return __('settings.license.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('settings.license.navigation_label');
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('validateLicense')
                ->label(__('settings.license.actions.validate'))
                ->icon('heroicon-o-check-badge')
                ->action(function (LicenseService $service): void {
                    $state = $this->form->getState();

                    $result = $service->validateAndStore(
                        app(LicenseSettings::class),
                        $state['license_key'] ?? null,
                    );

                    Notification::make()
                        ->{$result->valid() ? 'success' : 'danger'}()
                        ->title($result->valid()
                            ? __('settings.license.messages.valid')
                            : __('settings.license.messages.check_failed'))
                        ->body($result->message)
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
            Section::make(__('settings.license.sections.key'))
                ->description(__('settings.license.sections.key_description'))
                ->schema([
                    self::castNullableString(
                        TextInput::make('license_key')
                            ->label(__('settings.license.fields.license_key'))
                            ->password()
                            ->revealable()
                            ->maxLength(255),
                    ),
                ]),

            Section::make(__('settings.license.sections.status'))
                ->description(__('settings.license.sections.status_description'))
                ->columns(2)
                ->schema([
                    Placeholder::make('license_status')
                        ->label(__('settings.license.fields.status'))
                        ->content(fn (): string => __('settings.license.statuses.'.SettingsReader::license()->status())),
                    Placeholder::make('license_type')
                        ->label(__('settings.license.fields.type'))
                        ->content(fn (): string => SettingsReader::license()->type() ?? '—'),
                    Placeholder::make('license_expires_at')
                        ->label(__('settings.license.fields.expires_at'))
                        ->content(fn (): string => SettingsReader::license()->expiresAt()
                            ?? __('settings.license.messages.never_expires')),
                    Placeholder::make('license_allowed_domains')
                        ->label(__('settings.license.fields.allowed_domains'))
                        ->content(function (): string {
                            $domains = SettingsReader::license()->allowedDomains();

                            return $domains === []
                                ? __('settings.license.messages.any_domain')
                                : implode(', ', $domains);
                        }),
                    Placeholder::make('license_last_checked_at')
                        ->label(__('settings.license.fields.last_checked_at'))
                        ->content(fn (): string => SettingsReader::license()->lastCheckedAt() ?? '—'),
                    Placeholder::make('license_renewal_url')
                        ->label(__('settings.license.fields.renewal_url'))
                        ->content(function (): Htmlable|string {
                            $url = SettingsReader::license()->renewalUrl();

                            return $url === null
                                ? '—'
                                : new HtmlString(
                                    '<a href="'.e($url).'" target="_blank" rel="noopener" class="fi-link text-primary-600">'
                                    .e(__('settings.license.messages.renew_now'))
                                    .'</a>'
                                );
                        }),
                ]),
        ];
    }
}
