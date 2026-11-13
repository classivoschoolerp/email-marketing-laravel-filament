<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Settings;

use App\Services\Geo\GeoProviderRegistry;
use App\Services\Geo\IpLocationResolver;
use App\Services\Geo\MaxMindDatabaseUpdater;
use App\Services\Geo\Providers\MaxMindDbProvider;
use App\Settings\LocationSettings;
use App\Support\Files;
use Filament\Actions\Action;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Location services settings (spec §3.23 F-3.23-001/002/003, D-10):
 * provider select, encrypted credentials, MaxMind database upload +
 * auto-update, and the live test-lookup tool.
 */
class LocationSettingsPage extends BaseSettingsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-map-pin';
    protected static ?string $navigationGroup = 'Localization';

    protected static ?string $slug = 'settings/location';

    protected static ?int $navigationSort = 14;

    protected static string $view = 'filament.admin.pages.settings.location-settings';

    /** F-3.23-003 test-lookup tool state. */
    public string $testIp = '';

    /** @var array<string, mixed>|null */
    public ?array $testResult = null;

    public ?string $testError = null;

    protected static function settingsClass(): string
    {
        return LocationSettings::class;
    }

    public function getTitle(): string|Htmlable
    {
        return __('settings.location.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('settings.location.navigation_label');
    }

    /**
     * @return array<int, Component>
     */
    protected function schema(): array
    {
        return [
            Section::make(__('settings.location.sections.provider'))
                ->description(__('settings.location.sections.provider_description'))
                ->columns(2)
                ->schema([
                    Select::make('provider')
                        ->label(__('settings.location.fields.provider'))
                        ->options(fn (): array => app(GeoProviderRegistry::class)->options())
                        ->in(fn (): array => app(GeoProviderRegistry::class)->keys())
                        ->live()
                        ->required()
                        ->columnSpanFull(),

                    self::castInt(
                        TextInput::make('cache_ttl_minutes')
                            ->label(__('settings.location.fields.cache_ttl_minutes'))
                            ->helperText(__('settings.location.help.cache_ttl_minutes'))
                            ->numeric()->integer()->minValue(0)->maxValue(43200)
                            ->required(),
                    ),

                    Toggle::make('enrich_tracking')
                        ->label(__('settings.location.fields.enrich_tracking'))
                        ->helperText(__('settings.location.help.enrich_tracking')),

                    Toggle::make('enrich_login_logs')
                        ->label(__('settings.location.fields.enrich_login_logs'))
                        ->helperText(__('settings.location.help.enrich_login_logs')),
                ]),

            Section::make(__('settings.location.sections.maxmind'))
                ->description(__('settings.location.sections.maxmind_description'))
                ->columns(2)
                ->visible(self::providerIs(LocationSettings::PROVIDER_MAXMIND))
                ->schema([
                    Select::make('maxmind_edition')
                        ->label(__('settings.location.fields.maxmind_edition'))
                        ->options(array_combine(
                            LocationSettings::MAXMIND_EDITIONS,
                            LocationSettings::MAXMIND_EDITIONS,
                        ))
                        ->in(LocationSettings::MAXMIND_EDITIONS)
                        ->required(),

                    self::castNullableString(
                        TextInput::make('maxmind_license_key')
                            ->label(__('settings.location.fields.maxmind_license_key'))
                            ->helperText(__('settings.location.help.maxmind_license_key'))
                            ->password()
                            ->revealable()
                            ->maxLength(255),
                    ),

                    FileUpload::make('maxmind_database_path')
                        ->label(__('settings.location.fields.maxmind_database_path'))
                        ->helperText(__('settings.location.help.maxmind_database_path'))
                        ->disk('local')
                        ->directory((string) config('emp-ops.geo.database_directory', 'geoip'))
                        ->visibility('private')
                        ->maxSize((int) config('emp-ops.geo.max_upload_kb', 204800))
                        ->rules([self::mmdbExtensionRule()])
                        ->columnSpanFull(),

                    Toggle::make('maxmind_auto_update')
                        ->label(__('settings.location.fields.maxmind_auto_update'))
                        ->helperText(__('settings.location.help.maxmind_auto_update')),
                ]),

            Section::make(__('settings.location.sections.hosted'))
                ->description(__('settings.location.sections.hosted_description'))
                ->columns(2)
                ->schema([
                    self::castNullableString(
                        TextInput::make('ipapi_key')
                            ->label(__('settings.location.fields.ipapi_key'))
                            ->helperText(__('settings.location.help.ipapi_key'))
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->visible(self::providerIs(LocationSettings::PROVIDER_IP_API)),
                    ),
                    self::castNullableString(
                        TextInput::make('ipinfo_token')
                            ->label(__('settings.location.fields.ipinfo_token'))
                            ->helperText(__('settings.location.help.ipinfo_token'))
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->visible(self::providerIs(LocationSettings::PROVIDER_IPINFO)),
                    ),
                ])
                ->visible(fn (Get $get): bool => in_array(
                    $get('provider'),
                    [LocationSettings::PROVIDER_IP_API, LocationSettings::PROVIDER_IPINFO],
                    true,
                )),
        ];
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('updateMaxmind')
                ->label(__('settings.location.actions.update_database'))
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->visible(fn (): bool => app(LocationSettings::class)->provider === LocationSettings::PROVIDER_MAXMIND)
                ->action(function (): void {
                    try {
                        $path = app(MaxMindDatabaseUpdater::class)->update();
                    } catch (Throwable $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();

                        return;
                    }

                    $this->form->fill(app(LocationSettings::class)->toArray());

                    Notification::make()
                        ->success()
                        ->title(__('ops.geo.database_updated', ['path' => $path]))
                        ->send();
                }),
        ];
    }

    /** F-3.23-003 — resolve an address with the configured provider. */
    public function runTestLookup(): void
    {
        $this->testResult = null;
        $this->testError = null;

        $ip = trim($this->testIp);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $this->testError = __('settings.location.errors.invalid_ip');

            return;
        }

        try {
            $location = app(IpLocationResolver::class)->lookup($ip);
        } catch (Throwable $exception) {
            $this->testError = $exception->getMessage();

            return;
        }

        if ($location === null) {
            $this->testError = __('settings.location.errors.no_result');

            return;
        }

        $this->testResult = $location->toArray();
    }

    /** Uploaded-database facts for the view (path, size, edition, build date). */
    public function databaseStatus(): ?array
    {
        $provider = app(MaxMindDbProvider::class);
        $path = $provider->databasePath();

        if ($path === null) {
            return null;
        }

        $exists = Files::accessible($path) && is_file($path);

        return [
            'path' => $path,
            'exists' => $exists,
            'size' => $exists ? (int) filesize($path) : 0,
            'info' => $exists ? $provider->databaseInfo() : null,
            'uploaded_at' => app(LocationSettings::class)->maxmind_database_uploaded_at,
            'updated_at' => app(LocationSettings::class)->maxmind_updated_at,
        ];
    }

    public function providerConfigured(): bool
    {
        return app(IpLocationResolver::class)->enabled();
    }

    /**
     * Only `.mmdb` uploads are accepted — MaxMind archives and CSV exports
     * are rejected with a clear message instead of a cryptic reader error.
     */
    protected static function mmdbExtensionRule(): \Closure
    {
        return static function (): \Closure {
            return static function (string $attribute, mixed $value, \Closure $fail): void {
                $name = is_object($value) && method_exists($value, 'getClientOriginalName')
                    ? (string) $value->getClientOriginalName()
                    : (string) $value;

                if ($name !== '' && ! str_ends_with(strtolower($name), '.mmdb')) {
                    $fail(__('settings.location.errors.mmdb_extension'));
                }
            };
        };
    }

    protected static function providerIs(string $provider): \Closure
    {
        return fn (Get $get): bool => $get('provider') === $provider;
    }

    /** Delete a stale uploaded database (used by the view's remove action). */
    public function deleteDatabase(): void
    {
        $settings = app(LocationSettings::class);
        $path = $settings->maxmind_database_path;

        if ($path !== null && $path !== '') {
            Storage::disk('local')->delete($path);
        }

        $settings->maxmind_database_path = null;
        $settings->save();

        $this->form->fill($settings->toArray());

        Notification::make()->success()->title(__('settings.location.messages.database_removed'))->send();
    }
}
