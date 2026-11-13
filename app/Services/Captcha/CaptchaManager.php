<?php

declare(strict_types=1);

namespace App\Services\Captcha;

use InvalidArgumentException;

/**
 * Resolves the configured captcha driver (spec §1.2 F-1.2-003). Config-driven:
 * config('emp.captcha.driver') ∈ null|recaptcha-v2|recaptcha-v3|hcaptcha.
 */
class CaptchaManager
{
    /** @var array<string, class-string<CaptchaDriver>> */
    protected static array $drivers = [
        'null' => NullCaptcha::class,
        'recaptcha-v2' => RecaptchaV2::class,
        'recaptcha-v3' => RecaptchaV3::class,
        'hcaptcha' => HCaptcha::class,
    ];

    /** Register/override a driver (driver registry pattern, docs/ARCHITECTURE.md rule 2). */
    public static function extend(string $id, string $class): void
    {
        static::$drivers[$id] = $class;
    }

    public static function driver(?string $id = null): CaptchaDriver
    {
        $id ??= (string) config('emp.captcha.driver', 'null');

        $class = static::$drivers[$id] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException("Unknown captcha driver [{$id}].");
        }

        return app($class);
    }
}
