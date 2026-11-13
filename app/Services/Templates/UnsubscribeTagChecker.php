<?php

declare(strict_types=1);

namespace App\Services\Templates;

/**
 * Required-unsubscribe-tag check (spec §2.5.5 F-2.5.5-006). A template passes
 * when it contains {UNSUBSCRIBE_URL} or {DIRECT_UNSUBSCRIBE_URL} (the tags
 * seeded by CampaignSettings::KNOWN_TEMPLATE_TAGS, D-22). Behaviour on
 * failure follows config('emp-templates.unsubscribe_check'):
 *   warn  → template saved, missing_unsubscribe_tag flag set + UI warning
 *   block → save rejected with a validation error
 */
class UnsubscribeTagChecker
{
    public const MODE_WARN = 'warn';

    public const MODE_BLOCK = 'block';

    public const TAGS = ['UNSUBSCRIBE_URL', 'DIRECT_UNSUBSCRIBE_URL'];

    public function passes(?string $html): bool
    {
        if ($html === null || $html === '') {
            return true; // empty drafts are not flagged
        }

        foreach (self::TAGS as $tag) {
            if (str_contains($html, '{'.$tag.'}')) {
                return true;
            }
        }

        return false;
    }

    public function mode(): string
    {
        $mode = (string) config('emp-templates.unsubscribe_check', self::MODE_WARN);

        return $mode === self::MODE_BLOCK ? self::MODE_BLOCK : self::MODE_WARN;
    }

    public function blocks(?string $html): bool
    {
        return ! $this->passes($html) && $this->mode() === self::MODE_BLOCK;
    }
}
