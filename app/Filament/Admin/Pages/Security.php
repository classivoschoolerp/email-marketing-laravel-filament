<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Enums\LoginResult;
use App\Models\LoginLog;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Admin Security page (spec §3.2, closes D-35): TOTP 2FA enable/disable with
 * QR + hashed recovery codes via the shared TwoFactorService, password
 * change (clears the force-password-reset flag, F-3.2-004) and the admin's
 * own login history. Mirrors the customer Security page (§2.15.2); the
 * active-session list is customer-only (SessionManager is customer-typed)
 * and intentionally not mirrored here.
 */
class Security extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Users & Access';

    protected static string $view = 'filament.admin.pages.security';

    protected static ?string $slug = 'security';

    protected static ?int $navigationSort = 95;

    public ?string $pendingSecret = null;

    public string $confirmationCode = '';

    public string $currentPassword = '';

    /** @var list<string> shown exactly once after (re)generation */
    public array $freshRecoveryCodes = [];

    // Password change (F-3.2-004 force-password-reset target)
    public string $passwordCurrent = '';

    public string $passwordNew = '';

    public string $passwordNewConfirmation = '';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.account');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.security.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('admin.security.title');
    }

    // ------------------------------------------------------------------ 2FA (D-35)

    public function startTwoFactorSetup(TwoFactorService $twoFactor): void
    {
        if ($this->admin()->twofa_enabled) {
            return;
        }

        $this->pendingSecret = $twoFactor->generateSecret();
        $this->confirmationCode = '';
        $this->freshRecoveryCodes = [];
    }

    public function cancelTwoFactorSetup(): void
    {
        $this->pendingSecret = null;
        $this->confirmationCode = '';
    }

    public function confirmTwoFactor(TwoFactorService $twoFactor): void
    {
        if ($this->pendingSecret === null) {
            return;
        }

        $this->validate(
            ['confirmationCode' => ['required', 'string', 'max:16']],
            attributes: ['confirmationCode' => __('auth-module.twofa.code')],
        );

        if (! $twoFactor->verifyCode($this->pendingSecret, trim($this->confirmationCode))) {
            $this->addError('confirmationCode', __('auth-module.twofa.invalid_code'));

            return;
        }

        $this->freshRecoveryCodes = $twoFactor->enable($this->admin(), $this->pendingSecret);
        $this->pendingSecret = null;
        $this->confirmationCode = '';

        Notification::make()
            ->title(__('auth-module.twofa.enabled'))
            ->success()
            ->send();
    }

    public function disableTwoFactor(TwoFactorService $twoFactor): void
    {
        if (! $this->checkCurrentPassword()) {
            return;
        }

        $twoFactor->disable($this->admin());
        $this->currentPassword = '';
        $this->freshRecoveryCodes = [];

        Notification::make()
            ->title(__('auth-module.twofa.disabled'))
            ->success()
            ->send();
    }

    public function regenerateRecoveryCodes(TwoFactorService $twoFactor): void
    {
        if (! $this->admin()->twofa_enabled || ! $this->checkCurrentPassword()) {
            return;
        }

        $this->freshRecoveryCodes = $twoFactor->generateRecoveryCodes($this->admin());
        $this->currentPassword = '';

        Notification::make()
            ->title(__('auth-module.twofa.regenerated'))
            ->success()
            ->send();
    }

    public function getQrCodeSvg(): ?string
    {
        return $this->pendingSecret !== null
            ? app(TwoFactorService::class)->qrCodeSvg($this->admin(), $this->pendingSecret)
            : null;
    }

    // ----------------------------------------------- Password change (F-3.2-004)

    public function changePassword(): void
    {
        $this->validate(
            [
                'passwordCurrent' => ['required', 'string'],
                // WP-22 SURGICAL EDIT (§3.25.15 F-3.25.15-003): Password::defaults()
                // is now settings-driven (ComplianceServiceProvider); the added
                // policy rule enforces reuse prevention for this admin account.
                'passwordNew' => array_merge(
                    ['required', 'string', 'confirmed:passwordNewConfirmation', Password::defaults()],
                    [new \App\Rules\PasswordPolicy($this->admin())],
                ),
            ],
            attributes: [
                'passwordCurrent' => __('admin.security.password_current'),
                'passwordNew' => __('admin.security.password_new'),
            ],
        );

        if (! Hash::check($this->passwordCurrent, $this->admin()->password)) {
            $this->addError('passwordCurrent', __('auth-module.twofa.invalid_password'));

            return;
        }

        $this->admin()->forceFill([
            'password' => $this->passwordNew, // hashed cast
            'force_password_reset' => false,
        ])->save();

        $this->reset('passwordCurrent', 'passwordNew', 'passwordNewConfirmation');

        Notification::make()
            ->title(__('admin.security.password_changed'))
            ->success()
            ->send();
    }

    public function mustChangePassword(): bool
    {
        return (bool) $this->admin()->force_password_reset;
    }

    // --------------------------------------------------------- Login history (§3.2)

    public function table(Table $table): Table
    {
        $admin = $this->admin();

        return $table
            ->query(
                LoginLog::query()
                    ->where('guard', 'admin')
                    ->where('model_type', $admin->getMorphClass())
                    ->where('model_id', $admin->getKey())
            )
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('auth-module.security.history_time'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('ip')
                    ->label(__('auth-module.security.history_ip'))
                    ->placeholder('—'),
                TextColumn::make('user_agent')
                    ->label(__('auth-module.security.history_device'))
                    ->limit(48)
                    ->placeholder(__('auth-module.security.unknown_device')),
                TextColumn::make('result')
                    ->label(__('auth-module.security.history_result'))
                    ->badge()
                    ->formatStateUsing(fn (LoginResult $state): string => $state->label())
                    ->color(fn (LoginResult $state): string => match ($state) {
                        LoginResult::Success => 'success',
                        LoginResult::Failed, LoginResult::TwoFactorFailed => 'warning',
                        LoginResult::Blocked, LoginResult::Lockout => 'danger',
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50]);
    }

    public function admin(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }

    private function checkCurrentPassword(): bool
    {
        $this->validate(
            ['currentPassword' => ['required', 'string']],
            attributes: ['currentPassword' => __('auth-module.twofa.current_password')],
        );

        if (! Hash::check($this->currentPassword, $this->admin()->password)) {
            $this->addError('currentPassword', __('auth-module.twofa.invalid_password'));

            return false;
        }

        return true;
    }
}
