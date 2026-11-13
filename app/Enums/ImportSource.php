<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where an import run reads its rows from (spec §2.2.7). `esp` is the P2
 * connector family (Mailchimp, Brevo, Constant Contact, Klaviyo,
 * F-2.2.7-006); which vendor a run uses is `options.esp.driver`, resolved
 * through App\Services\Imports\Esp\EspImporterRegistry, so a new ESP never
 * needs another enum case.
 */
enum ImportSource: string
{
    case Csv = 'csv';
    case Paste = 'paste';
    case Url = 'url';
    case List = 'list';
    case Database = 'database';
    case Esp = 'esp';

    public function label(): string
    {
        return __('imports.source.'.$this->value);
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
