<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * SMTP connection security (spec §2.9.2 F-2.9.2-025: none/tcp/ssl/tls).
 * "none"/"tcp" are both plain-text sockets ("tcp" kept as a distinct spec
 * value); "ssl" is implicit SMTPS (usually port 465); "tls" is STARTTLS.
 */
enum ServerProtocol: string
{
    case None = 'none';
    case Tcp = 'tcp';
    case Ssl = 'ssl';
    case Tls = 'tls';

    public function usesImplicitTls(): bool
    {
        return $this === self::Ssl;
    }

    public function label(): string
    {
        return match ($this) {
            self::None => __('sending.protocol.none'),
            self::Tcp => __('sending.protocol.tcp'),
            self::Ssl => __('sending.protocol.ssl'),
            self::Tls => __('sending.protocol.tls'),
        };
    }
}
