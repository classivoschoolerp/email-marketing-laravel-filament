<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Models\Campaign;
use App\Models\EmailList;
use Illuminate\Support\Carbon;

/**
 * CASL + country-specific consent rules (spec §4.5 F-4.5-003, WP-42).
 *
 * The single authority on "which anti-spam law governs this list, and what
 * does it oblige us to do". Two consumers, both existing choke points, so a
 * rule can never be honoured on one surface and ignored on another:
 *
 *   CampaignPreflight   blocks scheduling a campaign whose list runs single
 *                       opt-in under an express-consent regime, or whose
 *                       list carries no sender identification where the
 *                       regime demands it (F-4.5-003, sibling of the
 *                       F-4.5-002 CAN-SPAM address gate);
 *   CampaignRecipients  drops recipients whose IMPLIED consent has aged out
 *                       of the regime's window, at the same choke point the
 *                       suppression lists and frequency cap use — so the
 *                       count preview, the send wave and the API agree.
 *
 * WHICH COUNTRY: the regime is derived from the target list's declared
 * company country (`lists.company_country`) — the jurisdiction whose postal
 * address already goes in the footer under F-4.5-002. Deriving it per
 * RECIPIENT was rejected: subscribers carry no country column, and the only
 * other source is a GeoIP lookup that D-10 says is unavailable without a
 * MaxMind database, so a per-recipient rule would silently degrade to "no
 * rule at all" on most installs. A per-list explicit override (for a sender
 * in one country mailing an audience in another) is not implemented; the
 * declared company country is the sole input today.
 *
 * WHAT COUNTS AS EXPRESS CONSENT: `subscribers.confirm_ip`, the IP recorded
 * when the recipient clicked their own double opt-in link (F-4.5-004
 * "double opt-in with IP + timestamp records"). It is written in exactly one
 * place — the public confirm-subscription route — so it is the only column
 * that cannot be produced by an import, an API call or an operator typing a
 * row in. `confirmed_at` deliberately is NOT the test: Subscriber::creating
 * stamps it on ANY row created as confirmed, so it is a lifecycle timestamp,
 * not proof of anything. Everything without a confirm_ip is implied consent,
 * dated from `subscribed_at`.
 */
class ConsentRules
{
    public const DEFAULT_KEY = 'default';

    /** The rule governing a country code (null/unknown => the default). */
    public function forCountry(?string $countryCode): ConsentRule
    {
        $code = strtoupper(trim((string) $countryCode));

        $key = $code === ''
            ? self::DEFAULT_KEY
            : (string) (config('emp-consent.countries.'.$code) ?? self::DEFAULT_KEY);

        return $this->rule($key);
    }

    public function forList(EmailList $list): ConsentRule
    {
        return $this->forCountry($list->company_country);
    }

    /** The rule governing a campaign, via its target list. */
    public function forCampaign(Campaign $campaign): ConsentRule
    {
        $list = $campaign->list ?? ($campaign->email_list_id !== null
            ? EmailList::withoutTenancy()->find($campaign->email_list_id)
            : null);

        return $list instanceof EmailList
            ? $this->forList($list)
            : $this->rule(self::DEFAULT_KEY);
    }

    /** Is enforcement switched on for this install? */
    public function enforced(): bool
    {
        return (bool) config('emp-consent.enforce', false);
    }

    /** A named rule, falling back to the default when the key is unknown. */
    public function rule(string $key): ConsentRule
    {
        $config = config('emp-consent.rules.'.$key);

        if (! is_array($config)) {
            $key = self::DEFAULT_KEY;
            $config = (array) config('emp-consent.rules.'.self::DEFAULT_KEY, []);
        }

        return ConsentRule::fromConfig($key, $config);
    }

    /**
     * The instant before which an IMPLIED-consent subscriber has aged out
     * under this rule, or null when implied consent never expires here.
     */
    public function impliedConsentCutoff(ConsentRule $rule): ?Carbon
    {
        if ($rule->impliedConsentDays === null) {
            return null;
        }

        return Carbon::now()->subDays($rule->impliedConsentDays);
    }
}
