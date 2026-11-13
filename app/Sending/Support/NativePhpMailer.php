<?php

declare(strict_types=1);

namespace App\Sending\Support;

/**
 * Production implementation of the PhpMailer seam — the real mail() call.
 */
class NativePhpMailer implements PhpMailer
{
    public function mail(string $to, string $subject, string $body, string $headers, string $params = ''): bool
    {
        if ($params !== '') {
            return mail($to, $subject, $body, $headers, $params);
        }

        return mail($to, $subject, $body, $headers);
    }
}
