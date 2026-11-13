<?php

declare(strict_types=1);

namespace App\Services\Captcha;

/**
 * Default no-op driver: no widget, verification always passes (spec §1.2
 * F-1.2-003 — captcha is opt-in via config('emp.captcha.driver')).
 */
class NullCaptcha implements CaptchaDriver
{
    public function id(): string
    {
        return 'null';
    }

    public function enabled(): bool
    {
        return false;
    }

    public function fieldName(): string
    {
        return 'captcha-response';
    }

    public function siteKey(): ?string
    {
        return null;
    }

    public function scriptUrl(): ?string
    {
        return null;
    }

    public function verify(?string $token, ?string $ip): bool
    {
        return true;
    }
}
