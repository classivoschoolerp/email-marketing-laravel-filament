<?php

declare(strict_types=1);

namespace App\Services\Captcha;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google reCAPTCHA v2 checkbox (spec §1.2 F-1.2-003).
 */
class RecaptchaV2 implements CaptchaDriver
{
    protected const VERIFY_ENDPOINT = 'https://www.google.com/recaptcha/api/siteverify';

    public function id(): string
    {
        return 'recaptcha-v2';
    }

    public function enabled(): bool
    {
        return filled(config('emp.captcha.site_key')) && filled(config('emp.captcha.secret_key'));
    }

    public function fieldName(): string
    {
        return 'g-recaptcha-response';
    }

    public function siteKey(): ?string
    {
        return config('emp.captcha.site_key');
    }

    public function scriptUrl(): ?string
    {
        return 'https://www.google.com/recaptcha/api.js';
    }

    public function verify(?string $token, ?string $ip): bool
    {
        if (blank($token)) {
            return false;
        }

        try {
            $response = Http::asForm()->post(static::VERIFY_ENDPOINT, [
                'secret' => config('emp.captcha.secret_key'),
                'response' => $token,
                'remoteip' => $ip,
            ]);
        } catch (Throwable) {
            return false; // fail closed on provider outage
        }

        return $response->successful() && $this->passes($response->json() ?? []);
    }

    /** @param array<string, mixed> $payload */
    protected function passes(array $payload): bool
    {
        return ($payload['success'] ?? false) === true;
    }
}
