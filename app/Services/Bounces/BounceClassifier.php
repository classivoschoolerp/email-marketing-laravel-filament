<?php

declare(strict_types=1);

namespace App\Services\Bounces;

use App\Enums\BounceType;
use App\Models\BounceRule;
use Illuminate\Support\Facades\Log;

/**
 * Classifies a parsed bounce hard|soft|internal via the bounce_rules table
 * (spec §2.9.4 F-2.9.4-006). Rules run in sort_order (internal rules ship
 * first so auto-replies and delay warnings never harm subscribers); the
 * first enabled match wins. Messages matching NO rule are classified
 * internal — the conservative choice: they are logged but no subscriber
 * action is taken on unrecognisable chatter.
 */
class BounceClassifier
{
    public function classify(ParsedBounce $bounce): BounceType
    {
        $haystack = $bounce->classificationHaystack();

        foreach (BounceRule::query()->enabled()->ordered()->get() as $rule) {
            $pattern = '#'.str_replace('#', '\#', (string) $rule->pattern).'#im';

            $match = @preg_match($pattern, $haystack);

            if ($match === false) {
                Log::warning('Skipping invalid bounce rule pattern.', [
                    'bounce_rule_id' => $rule->id,
                    'pattern' => $rule->pattern,
                ]);

                continue;
            }

            if ($match === 1) {
                return $rule->type;
            }
        }

        return BounceType::Internal;
    }
}
