<?php

declare(strict_types=1);

namespace App\Filament\Customer\Pages;

use App\Enums\LoginResult;
use App\Models\Customer;
use App\Models\LoginLog;
use App\Services\Auth\TwoFactorService;
use App\Services\Security\AccountIpAllowlist;
use App\Services\Security\SessionManager;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Hash;

/**
 * Security page (spec §2.15.2): TOTP 2FA enable/disable with QR + hashed
 * recovery codes, active session list + revoke, and login history.
 */
class Security extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static string $view = 'filament.customer.pages.security';

    protected static ?string $slug = 'security';

    protected static ?int $navigationSort = 91;

    public ?string $pendingSecret = null;

    public string $confirmationCode = '';

    public string $currentPassword = '';

    /** @var list<string> shown exactly once after (re)generation */
    public array $freshRecoveryCodes = [];

    /** @var list<string> F-2.15.2-004 — single IPs and CIDR ranges */
    public array $ipAllowlist = [];

    public function mount(): void
    {
        $this->ipAllowlist = app(AccountIpAllowlist::class)->entriesFor($this->customer());
    }

    public static function getNavigationGroup(): ?string
    {
        return __('auth-module.profile.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('auth-module.security.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('auth-module.security.title');
    }

    // ------------------------------------------------------------- 2FA (F-2.15.2-001)

    public function startTwoFactorSetup(TwoFactorService $twoFactor): void
    {
        if ($this->customer()->twofa_enabled) {
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

        $this->freshRecoveryCodes = $twoFactor->enable($this->customer(), $this->pendingSecret);
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

        $twoFactor->disable($this->customer());
        $this->currentPassword = '';
        $this->freshRecoveryCodes = [];

        Notification::make()
            ->title(__('auth-module.twofa.disabled'))
            ->success()
            ->send();
    }

    public function regenerateRecoveryCodes(TwoFactorService $twoFactor): void
    {
        if (! $this->customer()->twofa_enabled || ! $this->checkCurrentPassword()) {
            return;
        }

        $this->freshRecoveryCodes = $twoFactor->generateRecoveryCodes($this->customer());
        $this->currentPassword = '';

        Notification::make()
            ->title(__('auth-module.twofa.regenerated'))
            ->success()
            ->send();
    }

    public function getQrCodeSvg(): ?string
    {
        return $this->pendingSecret !== null
            ? app(TwoFactorService::class)->qrCodeSvg($this->customer(), $this->pendingSecret)
            : null;
    }

    // ------------------------------------------------- Active sessions (F-2.15.2-002)

    /** @return list<array{id: string, ip: ?string, user_agent: ?string, last_activity: CarbonImmutable, current: bool}> */
    public function getSessions(): array
    {
        return app(SessionManager::class)->forCustomer($this->customer(), session()->getId());
    }

    public function sessionsAvailable(): bool
    {
        return app(SessionManager::class)->usesDatabaseDriver();
    }

    public function revokeSession(string $sessionId): void
    {
        $revoked = app(SessionManager::class)->revoke($this->customer(), $sessionId);

        Notification::make()
            ->title($revoked
                ? __('auth-module.security.session_revoked')
                : __('auth-module.security.session_not_found'))
            ->{$revoked ? 'success' : 'danger'}()
            ->send();
    }

    // --------------------------------------------------- Login history (F-2.15.2-003)

    public function table(Table $table): Table
    {
        $customer = $this->customer();

        return $table
            ->query(
                LoginLog::query()
                    ->where('guard', 'customer')
                    ->where('model_type', $customer->getMorphClass())
                    ->where('model_id', $customer->getKey())
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
                TextColumn::make('location')
                    ->label(__('auth-module.security.history_location'))
                    ->placeholder('—'),
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

    // ------------------------------------------------- IP allowlist (F-2.15.2-004)

    /**
     * Saving is guarded twice on purpose: the current password, because this
     * is a lockout control a hijacked session could weaponise, and the
     * would-you-lock-yourself-out check, because the one address an
     * allowlist reliably excludes is the author's.
     */
    public function saveIpAllowlist(AccountIpAllowlist $allowlist): void
    {
        if (! $this->checkCurrentPassword()) {
            return;
        }

        $entries = $allowlist->normalize($this->ipAllowlist);
        $allowlist->assertWouldNotLockOut($entries, request()->ip());

        $customer = $this->customer();
        $customer->forceFill(['ip_allowlist' => $entries === [] ? null : $entries])->save();

        $this->ipAllowlist = $entries;
        $this->currentPassword = '';

        Notification::make()
            ->title($entries === []
                ? __('auth-module.ip_allowlist.cleared')
                : __('auth-module.ip_allowlist.saved'))
            ->success()
            ->send();
    }

    public function currentIp(): string
    {
        return (string) (request()->ip() ?? '—');
    }

    public function customer(): Customer
    {
        /** @var Customer $customer */
        $customer = Filament::auth()->user();

        return $customer;
    }

    private function checkCurrentPassword(): bool
    {
        $this->validate(
            ['currentPassword' => ['required', 'string']],
            attributes: ['currentPassword' => __('auth-module.twofa.current_password')],
        );

        if (! Hash::check($this->currentPassword, $this->customer()->password)) {
            $this->addError('currentPassword', __('auth-module.twofa.invalid_password'));

            return false;
        }

        return true;
    }
}
