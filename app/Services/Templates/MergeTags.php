<?php

declare(strict_types=1);

namespace App\Services\Templates;

use App\Settings\CampaignSettings;
use Illuminate\Support\Carbon;

/**
 * Merge-tag helpers for the templates module (spec §2.5.4 F-2.5.4-002,
 * §2.5.5 F-2.5.5-005). Tag syntax is {TAG_NAME}. The canonical tag registry
 * belongs to WP-13 (D-22); this reads the seeded list from
 * CampaignSettings::KNOWN_TEMPLATE_TAGS and never redefines it.
 */
class MergeTags
{
    /** @return list<string> */
    public function tags(): array
    {
        return CampaignSettings::KNOWN_TEMPLATE_TAGS;
    }

    /**
     * Sample subscriber data for previews/test sends (F-2.5.5-005).
     *
     * @return array<string, string>
     */
    public function sampleData(): array
    {
        $now = Carbon::now();

        return array_merge(config('emp-templates.sample_data', []), [
            'CURRENT_YEAR' => $now->format('Y'),
            'CURRENT_MONTH' => $now->format('m'),
            'CURRENT_DAY' => $now->format('d'),
        ]);
    }

    /**
     * Replace {TAG} occurrences with the given data (unknown tags left as-is).
     *
     * @param  array<string, string>  $data
     */
    public function render(?string $html, array $data): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        $replacements = [];

        foreach ($data as $tag => $value) {
            $replacements['{'.$tag.'}'] = $value;
        }

        return strtr($html, $replacements);
    }

    public function renderSample(?string $html): string
    {
        return $this->render($html, $this->sampleData());
    }
}
