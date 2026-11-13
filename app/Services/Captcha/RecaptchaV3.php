<?php

declare(strict_types=1);

namespace App\Services\Captcha;

/**
 * Google reCAPTCHA v3 — score-based, same verify endpoint as v2 plus a
 * minimum-score threshold (spec §1.2 F-1.2-003).
 */
class RecaptchaV3 extends RecaptchaV2
{
    public function id(): string
    {
        return 'recaptcha-v3';
    }

    public function scriptUrl(): ?string
    {
        return 'https://www.google.com/recaptcha/api.js?render='.$this->siteKey();
    }

    /** @param array<string, mixed> $payload */
    protected function passes(array $payload): bool
    {
        if (($payload['success'] ?? false) !== true) {
            return false;
        }

        $score = (float) ($payload['score'] ?? 0.0);

        return $score >= (float) config('emp.captcha.min_score', 0.5);
    }
}
