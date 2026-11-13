<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\LandingPage;

/**
 * Landing-page lifecycle (spec §2.6 F-2.6-012).
 *
 * Only a page that is LIVE renders publicly; everything else answers 404, so
 * a link shared before launch (or after a takedown) leaks nothing.
 *
 * `Scheduled` is deliberately promoted at RENDER time by
 * {@see LandingPage::isLive()} rather than by a cron job: the
 * go-live moment is then exact to the second and does not depend on a
 * scheduler tick having run — the page is simply not public until
 * `scheduled_at` has passed.
 */
enum LandingPageStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Unpublished = 'unpublished';

    public function label(): string
    {
        return __('landing.status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Published => 'success',
            self::Scheduled => 'info',
            self::Unpublished => 'warning',
            self::Draft => 'gray',
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
