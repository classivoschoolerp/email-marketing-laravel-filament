<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Settings;

use App\Models\CustomerGroup;
use App\Settings\CustomerSettings;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\Rule;

/**
 * Customer settings page (spec §3.25.4, rows F-3.25.4-001 … F-3.25.4-010).
 */
class CustomerSettingsPage extends BaseSettingsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Customers & Billing';

    protected static ?string $slug = 'settings/customer';

    protected static ?int $navigationSort = 4;

    protected static function settingsClass(): string
    {
        return CustomerSettings::class;
    }

    public function getTitle(): string|Htmlable
    {
        return __('settings.customer.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('settings.customer.navigation_label');
    }

    /**
     * @return array<int, Component>
     */
    protected function schema(): array
    {
        return [
            Section::make(__('settings.customer.sections.registration'))
                ->columns(2)
                ->schema([
                    Toggle::make('registration_enabled')
                        ->label(__('settings.customer.fields.registration_enabled')),
                    Toggle::make('registration_requires_approval')
                        ->label(__('settings.customer.fields.registration_requires_approval'))
                        ->helperText(__('settings.customer.help.registration_requires_approval')),
                    Toggle::make('registration_requires_email_confirmation')
                        ->label(__('settings.customer.fields.registration_requires_email_confirmation')),
                    Toggle::make('welcome_email_enabled')
                        ->label(__('settings.customer.fields.welcome_email_enabled'))
                        ->helperText(__('settings.customer.help.welcome_email_enabled')),
                    self::castNullableInt(
                        Select::make('default_customer_group_id')
                            ->label(__('settings.customer.fields.default_customer_group_id'))
                            ->helperText(__('settings.customer.help.default_customer_group_id'))
                            ->options(fn (): array => CustomerGroup::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->exists(table: 'customer_groups', column: 'id')
                            ->searchable()
                            ->placeholder('—'),
                    ),
                    Toggle::make('terms_acceptance_required')
                        ->label(__('settings.customer.fields.terms_acceptance_required'))
                        ->helperText(__('settings.customer.help.terms_acceptance_required')),
                ]),

            Section::make(__('settings.customer.sections.sending_servers'))
                ->schema([
                    Toggle::make('force_system_servers_only')
                        ->label(__('settings.customer.fields.force_system_servers_only'))
                        ->helperText(__('settings.customer.help.force_system_servers_only')),
                    CheckboxList::make('allowed_sending_server_types')
                        ->label(__('settings.customer.fields.allowed_sending_server_types'))
                        ->helperText(__('settings.customer.help.allowed_sending_server_types'))
                        ->options(self::sendingServerTypeOptions())
                        ->nestedRecursiveRules([Rule::in(CustomerSettings::SENDING_SERVER_TYPES)])
                        ->columns(2)
                        ->bulkToggleable(),
                ]),

            Section::make(__('settings.customer.sections.defaults'))
                ->description(__('settings.customer.sections.defaults_description'))
                ->columns(3)
                ->schema([
                    self::castInt(
                        TextInput::make('default_max_lists')
                            ->label(__('settings.customer.fields.default_max_lists'))
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->required(),
                    ),
                    self::castInt(
                        TextInput::make('default_max_campaigns')
                            ->label(__('settings.customer.fields.default_max_campaigns'))
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->required(),
                    ),
                    self::castInt(
                        TextInput::make('default_max_subscribers')
                            ->label(__('settings.customer.fields.default_max_subscribers'))
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->required(),
                    ),
                    self::castInt(
                        TextInput::make('default_sending_quota')
                            ->label(__('settings.customer.fields.default_sending_quota'))
                            ->helperText(__('settings.customer.help.default_sending_quota'))
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->required(),
                    ),
                    Select::make('default_sending_quota_period')
                        ->label(__('settings.customer.fields.default_sending_quota_period'))
                        ->options([
                            'hourly' => __('settings.customer.options.hourly'),
                            'daily' => __('settings.customer.options.daily'),
                            'monthly' => __('settings.customer.options.monthly'),
                        ])
                        ->in(CustomerSettings::QUOTA_PERIODS)
                        ->required(),
                    self::castInt(
                        TextInput::make('default_api_rate_limit_per_minute')
                            ->label(__('settings.customer.fields.default_api_rate_limit_per_minute'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(100000)
                            ->required(),
                    ),
                    Toggle::make('default_cdn_enabled')
                        ->label(__('settings.customer.fields.default_cdn_enabled')),
                    self::castNullableString(
                        TextInput::make('default_cdn_url')
                            ->label(__('settings.customer.fields.default_cdn_url'))
                            ->helperText(__('settings.customer.help.default_cdn_url'))
                            ->url()
                            ->maxLength(255),
                    ),
                    Toggle::make('default_api_enabled')
                        ->label(__('settings.customer.fields.default_api_enabled')),
                ]),

            Section::make(__('settings.customer.sections.account'))
                ->columns(2)
                ->schema([
                    Toggle::make('self_serve_deletion_enabled')
                        ->label(__('settings.customer.fields.self_serve_deletion_enabled'))
                        ->helperText(__('settings.customer.help.self_serve_deletion_enabled')),
                    Select::make('minimum_password_strength')
                        ->label(__('settings.customer.fields.minimum_password_strength'))
                        ->options([
                            CustomerSettings::PASSWORD_STRENGTH_WEAK => __('settings.customer.options.weak'),
                            CustomerSettings::PASSWORD_STRENGTH_MEDIUM => __('settings.customer.options.medium'),
                            CustomerSettings::PASSWORD_STRENGTH_STRONG => __('settings.customer.options.strong'),
                        ])
                        ->in(CustomerSettings::PASSWORD_STRENGTHS)
                        ->required(),
                    self::castInt(
                        TextInput::make('inactive_auto_suspend_days')
                            ->label(__('settings.customer.fields.inactive_auto_suspend_days'))
                            ->helperText(__('settings.customer.help.inactive_auto_suspend_days'))
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(3650)
                            ->required(),
                    ),
                ]),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected static function sendingServerTypeOptions(): array
    {
        return collect(CustomerSettings::SENDING_SERVER_TYPES)
            ->mapWithKeys(fn (string $type): array => [$type => __('settings.customer.server_types.'.$type)])
            ->all();
    }
}
