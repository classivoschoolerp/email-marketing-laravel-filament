<?php

declare(strict_types=1);

namespace App\Services\Landing;

/**
 * The landing-page template gallery (spec §2.6 F-2.6-003).
 *
 * Starter designs are CODE, not database rows, deliberately: they are part of
 * the product (like `lang/en/*.php` or the §2.5.4 starter HTML), they must
 * exist on a fresh install without a seeder having run, and they must follow
 * the active locale — every string below goes through `__()`. Choosing a
 * design copies its markup onto the page, after which it is the customer's
 * document and the gallery never touches it again (the §3.14 "use this is a
 * copy, never a link" rule).
 *
 * Each design is assembled from {@see LandingBlocks}, so a block fixed in one
 * place is fixed in every starter design and in the builder at once.
 */
final class LandingTemplates
{
    public const BLANK = 'blank';

    /** @var list<string> */
    public const KEYS = [self::BLANK, 'lead-magnet', 'webinar', 'product-launch', 'waitlist', 'pricing'];

    /**
     * @return list<array{key:string, name:string, description:string, html:string}>
     */
    public static function all(): array
    {
        return array_map(
            static fn (string $key): array => [
                'key' => $key,
                'name' => __('landing.gallery.designs.'.$key.'.name'),
                'description' => __('landing.gallery.designs.'.$key.'.description'),
                'html' => self::html($key),
            ],
            self::KEYS,
        );
    }

    /** @return array<string, string> key => name, for a select. */
    public static function options(): array
    {
        $options = [];

        foreach (self::KEYS as $key) {
            $options[$key] = __('landing.gallery.designs.'.$key.'.name');
        }

        return $options;
    }

    public static function exists(?string $key): bool
    {
        return $key !== null && in_array($key, self::KEYS, true);
    }

    /** The starter markup for a design key; an unknown key yields the blank one. */
    public static function html(string $key): string
    {
        return match ($key) {
            'lead-magnet' => self::join(['hero', 'columns', 'form', 'testimonials']),
            'webinar' => self::join(['hero', 'countdown', 'form', 'faq']),
            'product-launch' => self::join(['hero', 'video', 'columns', 'form']),
            'waitlist' => self::join(['hero', 'form', 'faq']),
            'pricing' => self::join(['hero', 'pricing', 'testimonials', 'form']),
            default => self::join(['hero', 'form']),
        };
    }

    /** @param list<string> $blocks */
    private static function join(array $blocks): string
    {
        return implode("\n", array_map(
            static fn (string $id): string => LandingBlocks::html($id),
            $blocks,
        ));
    }
}
