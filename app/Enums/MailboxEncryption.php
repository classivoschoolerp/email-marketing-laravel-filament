<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Transport security for the bounce / FBL mailbox connection (spec §2.9.4
 * F-2.9.4-001). `Tls` means STARTTLS (POP3 STLS); `Ssl` is implicit TLS on
 * connect (pop3s/imaps).
 */
enum MailboxEncryption: string
{
    case None = 'none';
    case Ssl = 'ssl';
    case Tls = 'tls';

    public function label(): string
    {
        return match ($this) {
            self::None => __('infra.mailbox.encryption.none'),
            self::Ssl => __('infra.mailbox.encryption.ssl'),
            self::Tls => __('infra.mailbox.encryption.tls'),
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
