<?php

declare(strict_types=1);

namespace App\Services\SystemEmails;

/**
 * The resolved subject + HTML body of one §3.15 system email (WP-20).
 */
final class RenderedSystemEmail
{
    public function __construct(
        public readonly string $subject,
        public readonly string $html,
    ) {}
}
