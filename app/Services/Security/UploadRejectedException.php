<?php

declare(strict_types=1);

namespace App\Services\Security;

use RuntimeException;

/**
 * Thrown by {@see UploadGuard::validate()} when a file fails the §3.25.15
 * upload policy. Carries every failure message so a form can surface them.
 */
final class UploadRejectedException extends RuntimeException
{
    /** @param array<int, string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct($errors[0] ?? 'Upload rejected.');
    }
}
