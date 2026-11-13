<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignTag;

/**
 * Free-form campaign tags with autocomplete (spec §2.3.2 F-2.3.2-004,
 * §2.3.10 F-2.3.10-002, filtered by §2.3.1 F-2.3.1-004).
 *
 * Tags are tenant-scoped rows, so `options()` can answer "every tag this
 * customer has ever used" from an index — which is what autocomplete needs
 * and a JSON column cannot give without a table scan.
 */
class CampaignTagger
{
    public const MAX_PER_CAMPAIGN = 20;

    /**
     * Replace a campaign's tags with the given names, creating any that are
     * new. Names are trimmed, capped and folded to one tag per slug.
     *
     * @param  list<string>  $names
     * @return list<int> the tag ids now attached
     */
    public function sync(Campaign $campaign, array $names): array
    {
        if ($campaign->customer_id === null) {
            return [];
        }

        $ids = [];

        foreach (array_slice($this->clean($names), 0, self::MAX_PER_CAMPAIGN) as $name) {
            $tag = CampaignTag::withoutTenancy()->firstOrCreate(
                ['customer_id' => $campaign->customer_id, 'slug' => CampaignTag::slugFor($name)],
                ['name' => $name],
            );

            $ids[] = (int) $tag->id;
        }

        $campaign->tags()->sync($ids);
        $campaign->unsetRelation('tags');

        return $ids;
    }

    /**
     * Autocomplete options for one tenant.
     *
     * @return array<string, string> name => name (the value IS the name so the
     *                               field round-trips without an id lookup)
     */
    public function options(?int $customerId): array
    {
        if ($customerId === null) {
            return [];
        }

        return CampaignTag::withoutTenancy()
            ->where('customer_id', $customerId)
            ->orderBy('name')
            ->pluck('name', 'name')
            ->all();
    }

    /** @return list<string> the campaign's tag names */
    public function namesFor(Campaign $campaign): array
    {
        if ($campaign->id === null) {
            return [];
        }

        return $campaign->tags()->orderBy('name')->pluck('name')->all();
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function clean(array $names): array
    {
        $out = [];

        foreach ($names as $name) {
            $name = trim((string) $name);
            $name = (string) preg_replace('/\s+/u', ' ', $name);
            $name = mb_substr($name, 0, 64);

            if ($name === '') {
                continue;
            }

            // First spelling wins: "Newsletter" then "newsletter" is ONE tag,
            // named the way the customer first wrote it.
            $out[CampaignTag::slugFor($name)] ??= $name;
        }

        return array_values($out);
    }
}
