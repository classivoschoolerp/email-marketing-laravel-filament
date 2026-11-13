<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Mailbox provider a seed address belongs to (spec §2.9.9 F-2.9.9-004, WP-46).
 *
 * The provider is what makes a placement report useful: "82% inbox" across a
 * mixed seed list says nothing, while "Gmail 40% / Outlook 100%" points
 * straight at the filter that is unhappy. Kept as a small closed set — an
 * unrecognised host is `Other`, never a silently invented category.
 */
enum SeedProvider: string
{
    case Gmail = 'gmail';
    case Outlook = 'outlook';
    case Yahoo = 'yahoo';
    case Aol = 'aol';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Gmail => __('deliverability.placement.providers.gmail'),
            self::Outlook => __('deliverability.placement.providers.outlook'),
            self::Yahoo => __('deliverability.placement.providers.yahoo'),
            self::Aol => __('deliverability.placement.providers.aol'),
            self::Other => __('deliverability.placement.providers.other'),
        };
    }

    /** Best-effort guess from the address domain; never throws. */
    public static function guess(string $email): self
    {
        $at = strrpos($email, '@');
        $domain = $at === false ? '' : mb_strtolower(substr($email, $at + 1));

        return match (true) {
            str_contains($domain, 'gmail.') || str_contains($domain, 'googlemail.') => self::Gmail,
            str_contains($domain, 'outlook.')
                || str_contains($domain, 'hotmail.')
                || str_contains($domain, 'live.')
                || str_contains($domain, 'msn.') => self::Outlook,
            str_contains($domain, 'yahoo.') || str_contains($domain, 'ymail.') => self::Yahoo,
            str_contains($domain, 'aol.') => self::Aol,
            default => self::Other,
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
