<?php

declare(strict_types=1);

namespace App\Services\Captcha;

/**
 * Captcha driver contract (spec §1.2 F-1.2-003) — driver registry pattern per
 * docs/ARCHITECTURE.md rule 2: new providers register here, never in core flows.
 */
interface CaptchaDriver
{
    /** Registry id, e.g. "recaptcha-v2". */
    public function id(): string;

    /** Whether this driver actively challenges (NullCaptcha returns false). */
    public function enabled(): bool;

    /** Name of the POSTed response field, e.g. "g-recaptcha-response". */
    public function fieldName(): string;

    /** Public site key for widget rendering (null when not applicable). */
    public function siteKey(): ?string;

    /** Provider JS to include on the form page (null when not applicable). */
    public function scriptUrl(): ?string;

    /** Server-side verification of the widget response token. */
    public function verify(?string $token, ?string $ip): bool;
}
