<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\Captcha\CaptchaManager;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates the captcha widget response through the configured driver
 * (spec §1.2 F-1.2-003). A disabled driver (NullCaptcha) always passes.
 */
class Captcha implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $driver = CaptchaManager::driver();

        if (! $driver->enabled()) {
            return;
        }

        if (! $driver->verify(is_string($value) ? $value : null, request()->ip())) {
            $fail(__('auth-module.register.captcha_failed'));
        }
    }
}
