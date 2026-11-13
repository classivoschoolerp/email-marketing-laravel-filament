<?php

declare(strict_types=1);

namespace App\Services\Captcha;

/**
 * hCaptcha driver (spec §1.2 F-1.2-003) — same protocol as reCAPTCHA with a
 * different endpoint/field.
 */
class HCaptcha extends RecaptchaV2
{
    protected const VERIFY_ENDPOINT = 'https://api.hcaptcha.com/siteverify';

    public function id(): string
    {
        return 'hcaptcha';
    }

    public function fieldName(): string
    {
        return 'h-captcha-response';
    }

    public function scriptUrl(): ?string
    {
        return 'https://js.hcaptcha.com/1/api.js';
    }
}
