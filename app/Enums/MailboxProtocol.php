<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Mailbox access protocol for bounce / FBL servers (spec §2.9.4 F-2.9.4-001).
 */
enum MailboxProtocol: string
{
    case Pop3 = 'pop3';
    case Imap = 'imap';

    public function label(): string
    {
        return match ($this) {
            self::Pop3 => __('infra.mailbox.protocol.pop3'),
            self::Imap => __('infra.mailbox.protocol.imap'),
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
