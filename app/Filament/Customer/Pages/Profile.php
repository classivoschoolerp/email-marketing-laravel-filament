<?php

declare(strict_types=1);

namespace App\Filament\Customer\Pages;

use App\Models\Customer;
use App\Notifications\Auth\ConfirmEmailChange;
use App\Notifications\Auth\PasswordChangedNotification;
use App\Rules\PasswordPolicy;
use App\Support\Geo\Countries;
use DateTimeZone;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\URL;

/**
 * My account / Profile (spec §2.15.1): identity, avatar, preferences, company
 * details, change-password with current-password confirmation and email
 * change with re-verification to the new address.
 */
class Profile extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-user-circle';

    protected static string $view = 'filament.customer.pages.profile';

    protected static ?string $slug = 'profile';

    protected static ?int $navigationSort = 90;

    /** @var array<string, mixed>|null */
    public ?array $profileData = [];

    /** @var array<string, mixed>|null */
    public ?array $passwordData = [];

    public static function getNavigationGroup(): ?string
    {
        return __('auth-module.profile.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('auth-module.profile.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('auth-module.profile.title');
    }

    public function mount(): void
    {
        $this->fillProfileForm();
        $this->passwordForm->fill();
    }

    /** @return array<int|string, string|Form> */
    protected function getForms(): array
    {
        return ['profileForm', 'passwordForm'];
    }

    public function profileForm(Form $form): Form
    {
        return $form
            ->schema([
                Section::make(__('auth-module.profile.section_profile'))
                    ->schema([
                        TextInput::make('first_name')
                            ->label(__('auth-module.profile.first_name'))
                            ->required()
                            ->maxLength(100),
                        TextInput::make('last_name')
                            ->label(__('auth-module.profile.last_name'))
                            ->required()
                            ->maxLength(100),
                        TextInput::make('email')
                            ->label(__('auth-module.profile.email'))
                            ->email()
                            ->required()
                            ->maxLength(191)
                            ->unique('customers', 'email', ignorable: fn (): Customer => $this->customer()),
                        TextInput::make('phone')
                            ->label(__('auth-module.profile.phone'))
                            ->tel()
                            ->maxLength(64),
                        FileUpload::make('avatar')
                            ->label(__('auth-module.profile.avatar'))
                            ->image()
                            ->avatar()
                            ->disk('public')
                            ->directory(fn (): string => 'customers/'.$this->customer()->uid)
                            ->maxSize(2048),
                    ])
                    ->columns(2),
                Section::make(__('auth-module.profile.section_preferences'))
                    ->schema([
                        Select::make('timezone')
                            ->label(__('auth-module.profile.timezone'))
                            ->options(array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers()))
                            ->searchable()
                            ->required(),
                        Select::make('locale')
                            ->label(__('auth-module.profile.language'))
                            ->options((array) config('emp.profile.locales', ['en' => 'English']))
                            ->required(),
                        Select::make('date_format')
                            ->label(__('auth-module.profile.date_format'))
                            ->options(collect((array) config('emp.profile.date_formats', ['Y-m-d']))
                                ->mapWithKeys(fn (string $format): array => [$format => Carbon::now()->format($format)])
                                ->all())
                            ->required(),
                    ])
                    ->columns(3),
                Section::make(__('auth-module.profile.section_company'))
                    ->schema([
                        TextInput::make('company')
                            ->label(__('auth-module.profile.company_name'))
                            ->maxLength(191),
                        TextInput::make('company_address')
                            ->label(__('auth-module.profile.company_address'))
                            ->maxLength(191),
                        TextInput::make('company_city')
                            ->label(__('auth-module.profile.company_city'))
                            ->maxLength(100),
                        TextInput::make('company_zone')
                            ->label(__('auth-module.profile.company_zone'))
                            ->maxLength(100),
                        Select::make('company_country')
                            ->label(__('auth-module.profile.company_country'))
                            ->options(Countries::all())
                            ->searchable(),
                        TextInput::make('company_zip')
                            ->label(__('auth-module.profile.company_zip'))
                            ->maxLength(32),
                        TextInput::make('company_website')
                            ->label(__('auth-module.profile.company_website'))
                            ->url()
                            ->maxLength(191),
                        TextInput::make('company_vat')
                            ->label(__('auth-module.profile.company_vat'))
                            ->maxLength(64),
                    ])
                    ->columns(2),
            ])
            ->statePath('profileData');
    }

    public function passwordForm(Form $form): Form
    {
        return $form
            ->schema([
                Section::make(__('auth-module.profile.section_password'))
                    ->schema([
                        TextInput::make('current_password')
                            ->label(__('auth-module.profile.current_password'))
                            ->password()
                            ->required()
                            ->rule('current_password:customer'),
                        // WP-22 SURGICAL EDIT (§3.25.15 F-3.25.15-003): the
                        // shared policy replaces the hard-coded strength rule
                        // — settings-driven complexity plus reuse prevention
                        // against this customer's password history.
                        TextInput::make('password')
                            ->label(__('auth-module.profile.new_password'))
                            ->password()
                            ->required()
                            ->rules(PasswordPolicy::rules($this->customer()))
                            ->same('password_confirmation'),
                        TextInput::make('password_confirmation')
                            ->label(__('auth-module.profile.new_password_confirmation'))
                            ->password()
                            ->required()
                            ->dehydrated(false),
                    ])
                    ->columns(1),
            ])
            ->statePath('passwordData');
    }

    public function saveProfile(): void
    {
        $customer = $this->customer();
        $state = $this->profileForm->getState();

        $newEmail = mb_strtolower(trim((string) $state['email']));
        $emailChanged = $newEmail !== mb_strtolower($customer->email);

        // F-3.26.6-003 (WP-46): remember what the VAT number was so the
        // verification is only re-run when it actually changed — a profile
        // save that touches an unrelated field must not call VIES.

        $customer->forceFill([
            'first_name' => $state['first_name'],
            'last_name' => $state['last_name'],
            'phone' => $state['phone'] ?? null,
            'avatar_path' => $state['avatar'] ?? null,
            'timezone' => $state['timezone'],
            'locale' => $state['locale'],
            'date_format' => $state['date_format'],
            'company' => $state['company'] ?? null,
            'company_address' => $state['company_address'] ?? null,
            'company_city' => $state['company_city'] ?? null,
            'company_zone' => $state['company_zone'] ?? null,
            'company_country' => $state['company_country'] ?? null,
            'company_zip' => $state['company_zip'] ?? null,
            'company_website' => $state['company_website'] ?? null,
            'company_vat' => $state['company_vat'] ?? null,
        ]);

        if ($emailChanged) {
            // Email only switches after the new address confirms (F-2.15.1-005).
            $customer->pending_email = $newEmail;

            NotificationFacade::route('mail', $newEmail)
                ->notify(new ConfirmEmailChange($this->emailChangeUrl($customer, $newEmail)));
        }

        $customer->save();

        if ($emailChanged) {
            $this->fillProfileForm();

            Notification::make()
                ->title(__('auth-module.profile.email_change_pending', ['email' => $newEmail]))
                ->info()
                ->send();
        }

        Notification::make()
            ->title(__('auth-module.profile.saved'))
            ->success()
            ->send();
    }

    public function savePassword(): void
    {
        $state = $this->passwordForm->getState(); // validates current_password:customer + strength

        $customer = $this->customer();
        $customer->forceFill(['password' => $state['password']])->save(); // hashed cast

        // Keep this session valid under AuthenticateSession's password-hash check.
        Filament::auth()->login($customer);

        $customer->notify(new PasswordChangedNotification);

        $this->passwordForm->fill();

        Notification::make()
            ->title(__('auth-module.profile.password_changed'))
            ->success()
            ->send();
    }

    public function customer(): Customer
    {
        /** @var Customer $customer */
        $customer = Filament::auth()->user();

        return $customer;
    }

    private function fillProfileForm(): void
    {
        $customer = $this->customer();

        $this->profileForm->fill([
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'avatar' => $customer->avatar_path,
            'timezone' => $customer->timezone ?? 'UTC',
            'locale' => $customer->locale ?? 'en',
            // Fresh models may not have the DB default hydrated yet.
            'date_format' => $customer->date_format
                ?? (string) collect((array) config('emp.profile.date_formats', ['Y-m-d']))->first(),
            'company' => $customer->company,
            'company_address' => $customer->company_address,
            'company_city' => $customer->company_city,
            'company_zone' => $customer->company_zone,
            'company_country' => $customer->company_country,
            'company_zip' => $customer->company_zip,
            'company_website' => $customer->company_website,
            'company_vat' => $customer->company_vat,
        ]);
    }

    private function emailChangeUrl(Customer $customer, string $newEmail): string
    {
        return URL::temporarySignedRoute(
            'email-change.confirm',
            now()->addHours((int) config('emp.email_change.ttl_hours', 48)),
            ['uid' => $customer->uid, 'hash' => sha1($newEmail)],
        );
    }
}
