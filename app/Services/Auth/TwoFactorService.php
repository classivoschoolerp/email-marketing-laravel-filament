<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Customer;
use App\Models\CustomerRecoveryCode;
use App\Models\User;
use App\Models\UserRecoveryCode;
use App\Notifications\Auth\RecoveryCodesRegeneratedNotification;
use App\Settings\TwoFactorSettings;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Throwable;

/**
 * TOTP two-factor authentication (spec §1.3 F-1.3-003, §2.15.2 F-2.15.2-001):
 * secret generation, QR provisioning (SVG via bacon-qr-code), code
 * verification and hashed-at-rest recovery codes.
 *
 * WP-03 (D-35): the same engine now serves admin users — every account
 * parameter is Customer|User; both models share the twofa_secret (encrypted)
 * + twofa_enabled columns, recovery codes live in customer_recovery_codes /
 * user_recovery_codes respectively. The public API for customers is
 * unchanged.
 *
 * Engine parameters (issuer, validity window, recovery-code count) come from
 * the §3.25.6 settings group (WP-05); the config('emp.twofa') stub values act
 * as the pre-migration fallback, per the note in config/emp.php.
 */
class TwoFactorService
{
    public function __construct(private readonly Google2FA $engine) {}

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey();
    }

    public function qrCodeSvg(Customer|User $account, string $secret): string
    {
        $uri = $this->engine->getQRCodeUrl(
            $this->issuer(),
            $account->email,
            $secret,
        );

        $renderer = new ImageRenderer(new RendererStyle(240), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($uri);
    }

    public function verifyCode(string $secret, string $code): bool
    {
        return (bool) $this->engine->verifyKey($secret, $code, $this->window());
    }

    /**
     * Turns 2FA on for the account and returns the plaintext recovery codes —
     * the only moment they exist unhashed.
     *
     * @return list<string>
     */
    public function enable(Customer|User $account, string $secret): array
    {
        $account->forceFill([
            'twofa_secret' => $secret, // encrypted cast
            'twofa_enabled' => true,
        ])->save();

        return $this->generateRecoveryCodes($account);
    }

    public function disable(Customer|User $account): void
    {
        DB::transaction(function () use ($account): void {
            $account->forceFill([
                'twofa_secret' => null,
                'twofa_enabled' => false,
            ])->save();

            $this->recoveryCodeQuery($account)->delete();
        });
    }

    /**
     * Replaces all recovery codes; returns the new plaintext codes.
     *
     * @return list<string>
     */
    public function generateRecoveryCodes(Customer|User $account): array
    {
        $codes = DB::transaction(function () use ($account): array {
            $this->recoveryCodeQuery($account)->delete();

            $codes = [];

            for ($i = 0; $i < $this->recoveryCodeCount(); $i++) {
                $code = Str::upper(Str::random(5).'-'.Str::random(5));
                $codes[] = $code;

                $this->recoveryCodeQuery($account)->create([
                    $this->ownerColumn($account) => $account->getKey(),
                    'code_hash' => Hash::make($code),
                ]);
            }

            return $codes;
        });

        // 2FA security notice (spec §3.15 F-3.15-005, WP-20): the previous
        // codes just stopped working — tell the account owner. The codes
        // themselves are never emailed.
        $account->notify(new RecoveryCodesRegeneratedNotification);

        return $codes;
    }

    /**
     * Validates and consumes a recovery code (single use).
     */
    public function verifyRecoveryCode(Customer|User $account, string $code): bool
    {
        $candidates = $this->recoveryCodeQuery($account)
            ->whereNull('used_at')
            ->get();

        foreach ($candidates as $candidate) {
            if (Hash::check($code, $candidate->code_hash)) {
                $candidate->forceFill(['used_at' => now()])->save();

                return true;
            }
        }

        return false;
    }

    /**
     * Challenge entry point: accepts either a TOTP code or a recovery code.
     */
    public function verify(Customer|User $account, string $code): bool
    {
        $secret = $account->twofa_secret;

        if (is_string($secret) && $secret !== '' && $this->verifyCode($secret, $code)) {
            return true;
        }

        return $this->verifyRecoveryCode($account, $code);
    }

    /** Scoped query over the account's recovery-code table. */
    private function recoveryCodeQuery(Customer|User $account): Builder
    {
        return $account instanceof Customer
            ? CustomerRecoveryCode::query()->where('customer_id', $account->getKey())
            : UserRecoveryCode::query()->where('user_id', $account->getKey());
    }

    private function ownerColumn(Customer|User $account): string
    {
        return $account instanceof Customer ? 'customer_id' : 'user_id';
    }

    // ---------------------------------------- §3.25.6 settings (WP-05, F-3.25.6-003)

    private function issuer(): string
    {
        try {
            $issuer = app(TwoFactorSettings::class)->issuer;
        } catch (Throwable) {
            $issuer = null; // settings not migrated yet
        }

        return $issuer ?? (string) config('app.name');
    }

    private function window(): int
    {
        try {
            return max(0, app(TwoFactorSettings::class)->code_validity_window);
        } catch (Throwable) {
            return (int) config('emp.twofa.window', 1);
        }
    }

    private function recoveryCodeCount(): int
    {
        try {
            return max(1, app(TwoFactorSettings::class)->recovery_code_count);
        } catch (Throwable) {
            return (int) config('emp.twofa.recovery_codes', 10);
        }
    }
}
