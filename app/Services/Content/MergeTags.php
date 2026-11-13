<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\Campaign;
use App\Models\EmailList;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Services\Ecommerce\EcommerceMerge;
use App\Services\Lists\FieldValueService;
use App\Services\Lists\PublicPageRenderer;
use App\Services\Settings\SettingsReader;
use App\Services\Surveys\SurveyLinkResolver;
use App\Settings\CampaignSettings;
use App\Support\Geo\Countries;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Canonical campaign-grade merge-tag engine (spec §4.1, WP-13). Tag syntax is
 * `{TAG}` (D-22 — the spec's `[TAG]` bracket style was normalised to braces
 * across the codebase). Supersedes the sampler-grade
 * App\Services\Templates\MergeTags as the tag registry/option source; that
 * class remains for template-module sample rendering only.
 *
 * Grammar:
 *   {TAG}                        plain substitution
 *   {TAG|fallback}               default value when the tag resolves empty (F-4.1-007)
 *   {TAG:uppercase}              modifier: uppercase | lowercase | ucfirst (mb-safe)
 *   {TAG:ucfirst|fallback}       modifier + default combined
 *   {RANDOM_CONTENT:a|b|c}       deterministic pick per seed (F-4.1-008)
 *   {FETCH_URL:https://...}      remote include, 5-min cache, fail-empty (F-4.1-009)
 *
 * `{FNAME|Friend}` never collides with spintax (D-55): merge tags render
 * BEFORE the spintax pass and consume every known-tag braced run; spintax
 * groups (`{Hi|Hello}`) start with a lowercase run and are left untouched.
 *
 * `[IF <TAG> <op> '<value>']…[ELSE]…[ENDIF]` conditional blocks (F-4.1-010,
 * = F-2.3.3-008) ARE implemented: App\Services\Content\ConditionalContent runs
 * first inside render() below, so an untaken branch never reaches tag
 * substitution. See that class for the operator grammar.
 */
class MergeTags
{
    public const MODIFIERS = ['uppercase', 'lowercase', 'ucfirst'];

    /**
     * Canonical registry (D-22): every globally valid tag. List-field tags
     * (FNAME-style, per list) are added by knownTags(). The 20 seeded
     * CampaignSettings::KNOWN_TEMPLATE_TAGS stay subject to the §3.25.3
     * enable/disable list; the additional §4.1 tags below are always on.
     *
     * @var list<string>
     */
    public const TAGS = [
        // Subscriber (F-4.1-001) — plus per-list field tags via knownTags()
        'EMAIL',
        'SUBSCRIBER_EMAIL',
        'SUBSCRIBER_NAME',
        'SUBSCRIBER_FIRST_NAME',
        'SUBSCRIBER_LAST_NAME',
        'SUBSCRIBER_UID',
        'UID',
        'STATUS',
        'SOURCE',
        'IP',
        'SUBSCRIBE_DATE',
        'CONFIRM_DATE',
        'UNSUBSCRIBE_DATE',
        // List (F-4.1-002)
        'LIST_NAME',
        'LIST_UID',
        'LIST_DESCRIPTION',
        'LIST_FROM_NAME',
        'LIST_FROM_EMAIL',
        'LIST_SUBSCRIBE_URL',
        // Company (F-4.1-003)
        'COMPANY_NAME',
        'COMPANY_ADDRESS_1',
        'COMPANY_ADDRESS_2',
        'COMPANY_CITY',
        'COMPANY_ZONE',
        'COMPANY_COUNTRY',
        'COMPANY_ZIP',
        'COMPANY_PHONE',
        'COMPANY_WEBSITE',
        'COMPANY_FULL_ADDRESS',
        // Campaign (F-4.1-004)
        'CAMPAIGN_NAME',
        'CAMPAIGN_SUBJECT',
        'CAMPAIGN_UID',
        'CAMPAIGN_SEND_AT',
        // URLs (F-4.1-005)
        'UNSUBSCRIBE_URL',
        'DIRECT_UNSUBSCRIBE_URL',
        'UPDATE_PROFILE_URL',
        'WEB_VERSION_URL',
        'FORWARD_URL',
        'FORWARD_FRIEND_URL',
        'CONFIRM_URL',
        // Date/time (F-4.1-006)
        'CURRENT_YEAR',
        'CURRENT_MONTH',
        'CURRENT_DAY',
        'CURRENT_DATE',
        // Special forms (F-4.1-008/009) — matched with their argument syntax
        'RANDOM_CONTENT',
        'FETCH_URL',
    ];

    public function __construct(
        private readonly FieldValueService $fieldValues,
        private readonly PublicPageRenderer $pages,
    ) {}

    /**
     * Every tag valid for content targeting $list: the canonical registry
     * plus the list's custom-field tags. Powers the tag-inserter UI and the
     * broken-tag scanner.
     *
     * @return list<string>
     */
    public function knownTags(?EmailList $list = null): array
    {
        $tags = self::TAGS;

        if ($list !== null) {
            $tags = array_merge(
                $tags,
                ListField::withoutTenancy()->where('list_id', $list->id)->pluck('tag')->all(),
            );
        }

        return array_values(array_unique($tags));
    }

    /**
     * Real per-recipient values for a campaign send. Any of the three may be
     * null — tags without context resolve to '' (URLs to '#').
     *
     * @return array<string, string>
     */
    public function values(?Campaign $campaign = null, ?EmailList $list = null, ?Subscriber $subscriber = null): array
    {
        $values = $this->dateValues();

        if ($campaign !== null) {
            $values += $this->campaignValues($campaign);
        }

        if ($list !== null) {
            $values += $this->listValues($list);
        }

        if ($subscriber !== null) {
            $values += $this->subscriberValues($subscriber);
        }

        // WP-45 (§2.12 F-2.12-008): coupon + store tags. Placed last so a
        // list custom field named COUPON_CODE still wins, exactly like every
        // other reserved-vs-custom collision in this method.
        if (class_exists(EcommerceMerge::class)) {
            $values += app(EcommerceMerge::class)->values($campaign, $subscriber);
        }

        return $values
            + $this->urlValues($campaign, $list, $subscriber)
            + $this->contextValues($campaign, $list, $subscriber, preview: false);
    }

    /**
     * WP-46 (§2.3.3 F-2.3.3-009): the render CONTEXT smart content needs —
     * which tenant is sending, to whom, and whether this is a preview. The
     * keys start with `__` so the `{[A-Z][A-Z0-9_]*}` tag pattern can never
     * emit them and unknownTags() can never report them.
     *
     * @return array<string, string>
     */
    private function contextValues(
        ?Campaign $campaign,
        ?EmailList $list,
        ?Subscriber $subscriber,
        bool $preview,
    ): array {
        $customerId = $campaign?->customer_id ?? $list?->customer_id ?? $subscriber?->customer_id;

        $values = [];

        if ($customerId !== null) {
            $values[ConditionalContent::CONTEXT_CUSTOMER] = (string) $customerId;
        }

        if ($subscriber?->id !== null) {
            $values[ConditionalContent::CONTEXT_SUBSCRIBER] = (string) $subscriber->id;
        }

        if ($preview) {
            $values[ConditionalContent::CONTEXT_PREVIEW] = '1';
        }

        return $values;
    }

    /**
     * Sample values for previews / test sends / web view — real campaign and
     * list data merged over the configured sample subscriber.
     *
     * @return array<string, string>
     */
    public function sampleValues(?Campaign $campaign = null, ?EmailList $list = null): array
    {
        $values = array_map(
            static fn ($value): string => (string) $value,
            (array) config('emp-templates.sample_data', []),
        ) + $this->dateValues();

        if ($campaign !== null) {
            $values = $this->campaignValues($campaign) + $values;
        }

        if ($list !== null) {
            $values = $this->listValues($list) + $values;
        }

        return $values
            + $this->urlValues($campaign, $list, null)
            + $this->contextValues($campaign, $list, null, preview: true);
    }

    /**
     * Render content against a value map: FETCH_URL, RANDOM_CONTENT, then
     * tag substitution with modifiers + default fallbacks. Unknown tags are
     * left untouched (the scanner reports them). $seed makes RANDOM_CONTENT
     * deterministic per recipient (empty seed = random, for previews).
     */
    public function render(string $content, array $values, string $seed = ''): string
    {
        if ($content === '') {
            return '';
        }

        // WP-35 (§2.3.3 F-2.3.3-008): conditional blocks resolve FIRST, so a
        // branch that was not taken never reaches tag substitution — and every
        // consumer of this method (send path, web view, test send, preview)
        // gets the feature without its own wiring.
        $content = app(ConditionalContent::class)->render($content, $values);

        $content = $this->renderSurveyUrls($content, $values);

        // WP-45 (§2.12 F-2.12-003/004/005/008): the dynamic ecommerce blocks
        // and the {COUPON_CODE:<batch_uid>} argument form. Both are
        // str_contains-guarded inside EcommerceMerge, so content without them
        // pays nothing — the same posture as renderSurveyUrls above. Doing it
        // HERE rather than in the send path is what gives the automation
        // email, the transactional message, the preview and the web view the
        // same blocks with no extra wiring.
        if (class_exists(EcommerceMerge::class)) {
            $ecommerce = app(EcommerceMerge::class);
            $content = $ecommerce->renderCouponTags($content, $values);
            $content = $ecommerce->renderBlocks($content, $values);
        }

        $content = $this->renderFetchUrls($content);
        $content = $this->renderRandomContent($content, $seed);

        return (string) preg_replace_callback(
            '/\{([A-Z][A-Z0-9_]*)(?::(uppercase|lowercase|ucfirst))?(?:\|([^{}]*))?\}/u',
            function (array $match) use ($values): string {
                $tag = $match[1];

                if (! array_key_exists($tag, $values)) {
                    return $match[0]; // unknown — leave for the scanner
                }

                $value = (string) $values[$tag];

                if ($value === '' && isset($match[3]) && $match[3] !== '') {
                    $value = $match[3]; // default fallback (F-4.1-007)
                }

                return $this->applyModifier($value, $match[2] ?? '');
            },
            $content,
        );
    }

    /** Convenience wrapper: values() + render(). */
    public function renderFor(
        string $content,
        ?Campaign $campaign = null,
        ?EmailList $list = null,
        ?Subscriber $subscriber = null,
        string $seed = '',
    ): string {
        return $this->render($content, $this->values($campaign, $list, $subscriber), $seed);
    }

    /**
     * Broken/unknown-tag scan (F-4.1-012): tag-shaped braced runs whose name
     * is neither an enabled seeded tag, a canonical §4.1 tag, a reserved
     * schema tag nor a field tag of the target list. Feeds CampaignPreflight.
     *
     * @return list<string>
     */
    public function unknownTags(string $content, ?EmailList $list = null): array
    {
        preg_match_all('/\{([A-Z][A-Z0-9_]*)(?::[^{}]*)?(?:\|[^{}]*)?\}/u', $content, $matches);

        $used = array_values(array_unique($matches[1] ?? []));

        if ($used === []) {
            return [];
        }

        // Seeded tags stay subject to the §3.25.3 enable list; §4.1 extras
        // are always available.
        $known = array_merge(
            SettingsReader::campaign()->enabledTemplateTags(),
            array_diff(self::TAGS, CampaignSettings::KNOWN_TEMPLATE_TAGS),
            ListField::RESERVED_TAGS,
            $list !== null
                ? ListField::withoutTenancy()->where('list_id', $list->id)->pluck('tag')->all()
                : [],
        );

        return array_values(array_diff($used, $known));
    }

    /* ---------------------------------------------------------------- *
     *  Value groups.
     * ---------------------------------------------------------------- */

    /** @return array<string, string> */
    private function dateValues(): array
    {
        $now = Carbon::now();

        return [
            'CURRENT_YEAR' => $now->format('Y'),
            'CURRENT_MONTH' => $now->format('m'),
            'CURRENT_DAY' => $now->format('d'),
            'CURRENT_DATE' => $now->format('Y-m-d'),
        ];
    }

    /** @return array<string, string> */
    private function campaignValues(Campaign $campaign): array
    {
        return [
            'CAMPAIGN_NAME' => (string) $campaign->name,
            'CAMPAIGN_SUBJECT' => (string) $campaign->subject,
            'CAMPAIGN_UID' => (string) $campaign->uid,
            'CAMPAIGN_SEND_AT' => $campaign->send_at?->format('Y-m-d H:i') ?? '',
        ];
    }

    /** @return array<string, string> */
    private function listValues(EmailList $list): array
    {
        return [
            'LIST_NAME' => (string) $list->name,
            'LIST_UID' => (string) $list->uid,
            'LIST_DESCRIPTION' => (string) $list->description,
            'LIST_FROM_NAME' => (string) $list->from_name,
            'LIST_FROM_EMAIL' => (string) $list->from_email,
            'COMPANY_NAME' => (string) $list->company_name,
            'COMPANY_ADDRESS_1' => (string) $list->company_address_1,
            'COMPANY_ADDRESS_2' => (string) $list->company_address_2,
            'COMPANY_CITY' => (string) $list->company_city,
            'COMPANY_ZONE' => (string) $list->company_zone,
            'COMPANY_COUNTRY' => $this->countryName($list->company_country),
            'COMPANY_ZIP' => (string) $list->company_zip,
            'COMPANY_PHONE' => (string) $list->company_phone,
            'COMPANY_WEBSITE' => (string) $list->company_website,
            'COMPANY_FULL_ADDRESS' => $this->pages->companyFullAddress($list),
        ];
    }

    /** @return array<string, string> */
    private function subscriberValues(Subscriber $subscriber): array
    {
        $values = [
            'EMAIL' => (string) $subscriber->email,
            'SUBSCRIBER_EMAIL' => (string) $subscriber->email,
            'SUBSCRIBER_UID' => (string) $subscriber->uid,
            'UID' => (string) $subscriber->uid,
            'STATUS' => $subscriber->status->value,
            'SOURCE' => $subscriber->source->value,
            'IP' => (string) $subscriber->ip,
            'SUBSCRIBE_DATE' => $subscriber->subscribed_at?->format('Y-m-d') ?? '',
            'CONFIRM_DATE' => $subscriber->confirmed_at?->format('Y-m-d') ?? '',
            'UNSUBSCRIBE_DATE' => $subscriber->unsubscribed_at?->format('Y-m-d') ?? '',
        ];

        // Custom field tags ({FNAME}-style, F-4.1-001). Every field of the
        // list resolves — missing values fall back to the field default,
        // then ''.
        $fields = ListField::withoutTenancy()->where('list_id', $subscriber->list_id)->get();
        $byTag = [];

        foreach ($fields as $field) {
            $raw = $this->fieldValues->raw($subscriber, $field);
            $byTag[$field->tag] = $this->stringable(
                $raw !== null && $raw !== ''
                    ? $this->fieldValues->fromStorage($field, $raw)
                    : (string) $field->default_value,
            );
        }

        $values += $byTag;

        $first = $byTag['FNAME'] ?? '';
        $last = $byTag['LNAME'] ?? '';

        $values['SUBSCRIBER_FIRST_NAME'] = $first;
        $values['SUBSCRIBER_LAST_NAME'] = $last;
        $values['SUBSCRIBER_NAME'] = trim($first.' '.$last);

        return $values;
    }

    /**
     * URL tags resolve to the real WP-09 public routes. Personal URLs need a
     * subscriber; without one they neutralise to '#'. DIRECT_UNSUBSCRIBE_URL
     * maps to the same tracked endpoint as UNSUBSCRIBE_URL — no dedicated
     * one-step route exists yet (WP-13 decision; route files are frozen).
     * FORWARD_URL / FORWARD_FRIEND_URL resolve to the WP-35 forward page
     * (§1.6 F-1.6-002) once both a campaign and a subscriber are known —
     * a forward with nobody to attribute it to stays '#'.
     *
     * @return array<string, string>
     */
    private function urlValues(?Campaign $campaign, ?EmailList $list, ?Subscriber $subscriber): array
    {
        $urls = [
            'UNSUBSCRIBE_URL' => '#',
            'DIRECT_UNSUBSCRIBE_URL' => '#',
            'UPDATE_PROFILE_URL' => '#',
            'WEB_VERSION_URL' => '#',
            'LIST_SUBSCRIBE_URL' => '#',
            'FORWARD_URL' => '#',
            'FORWARD_FRIEND_URL' => '#',
            'CONFIRM_URL' => '#',
        ];

        if ($campaign !== null) {
            $urls['WEB_VERSION_URL'] = route('campaigns.public.web_view', ['campaign_uid' => $campaign->uid]);
        }

        if ($list !== null) {
            $urls['LIST_SUBSCRIBE_URL'] = route('lists.public.subscribe', ['list_uid' => $list->uid]);
        }

        if ($subscriber !== null) {
            if ($campaign !== null) {
                $tracked = route('campaigns.track.unsubscribe', [
                    'campaign_uid' => $campaign->uid,
                    'subscriber_uid' => $subscriber->uid,
                ]);
                $urls['UNSUBSCRIBE_URL'] = $tracked;
                $urls['DIRECT_UNSUBSCRIBE_URL'] = $tracked;
            } elseif ($list !== null) {
                $plain = route('lists.public.unsubscribe', [
                    'list_uid' => $list->uid,
                    'subscriber_uid' => $subscriber->uid,
                ]);
                $urls['UNSUBSCRIBE_URL'] = $plain;
                $urls['DIRECT_UNSUBSCRIBE_URL'] = $plain;
            }

            if ($list !== null) {
                $urls['UPDATE_PROFILE_URL'] = route('lists.public.update_profile', [
                    'list_uid' => $list->uid,
                    'subscriber_uid' => $subscriber->uid,
                ]);
            }

            // WP-35 (§1.6 F-1.6-002 / §1.7 F-1.7-004): the forward-to-a-friend
            // page. Both tags point at the same tracked endpoint — MailWizz
            // ships FORWARD_URL as the alias of FORWARD_FRIEND_URL and there is
            // exactly one forward page to link to.
            if ($campaign !== null) {
                $forward = route('campaigns.track.forward', [
                    'campaign_uid' => $campaign->uid,
                    'subscriber_uid' => $subscriber->uid,
                ]);
                $urls['FORWARD_FRIEND_URL'] = $forward;
                $urls['FORWARD_URL'] = $forward;
            }
        }

        return $urls;
    }

    /* ---------------------------------------------------------------- *
     *  Special forms.
     * ---------------------------------------------------------------- */

    /**
     * {SURVEY_URL:<survey_uid>} — WP-31 (F-2.8-008). Delegated wholesale to
     * the surveys module; the str_contains guard means content without the
     * tag pays nothing (no query, no autoload). The recipient is recovered
     * from the already-resolved SUBSCRIBER_UID value so the link comes back
     * identified (F-1.9-002).
     *
     * @param  array<string, string>  $values
     */
    private function renderSurveyUrls(string $content, array $values): string
    {
        if (! class_exists(SurveyLinkResolver::class) || ! str_contains($content, '{'.SurveyLinkResolver::TAG.':')) {
            return $content;
        }

        $uid = trim((string) ($values['SUBSCRIBER_UID'] ?? ''));

        $subscriber = $uid === ''
            ? null
            : Subscriber::withoutTenancy()->where('uid', $uid)->first();

        return app(SurveyLinkResolver::class)->render($content, $subscriber);
    }

    /** {FETCH_URL:https://...} — Http fetch, 5-min cache, fail-empty (F-4.1-009). */
    private function renderFetchUrls(string $content): string
    {
        if (! str_contains($content, '{FETCH_URL:')) {
            return $content;
        }

        return (string) preg_replace_callback(
            '/\{FETCH_URL:([^{}\s]+)\}/u',
            fn (array $match): string => $this->fetchUrl($match[1]),
            $content,
        );
    }

    private function fetchUrl(string $url): string
    {
        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            return '';
        }

        $key = 'merge-tags:fetch:'.sha1($url);
        $cached = Cache::get($key);

        if (is_string($cached)) {
            return $cached;
        }

        try {
            $response = Http::timeout((int) config('emp-sending.merge_tags.fetch_timeout_seconds', 5))->get($url);

            if (! $response->successful()) {
                return '';
            }

            $body = $response->body();
            Cache::put($key, $body, (int) config('emp-sending.merge_tags.fetch_cache_seconds', 300));

            return $body;
        } catch (Throwable) {
            return ''; // timeout / DNS / connection — fail empty
        }
    }

    /** {RANDOM_CONTENT:a|b|c} — deterministic per seed, like spintax (D-55). */
    private function renderRandomContent(string $content, string $seed): string
    {
        if (! str_contains($content, '{RANDOM_CONTENT:')) {
            return $content;
        }

        $index = 0;

        return (string) preg_replace_callback(
            '/\{RANDOM_CONTENT:([^{}]*)\}/u',
            function (array $match) use ($seed, &$index): string {
                $options = explode('|', $match[1]);
                $count = count($options);
                $position = $index++;

                if ($count === 1) {
                    return $options[0];
                }

                if ($seed === '') {
                    return $options[random_int(0, $count - 1)];
                }

                $hash = hash('xxh128', $seed.'#random-'.$position);

                return $options[(int) (hexdec(substr($hash, 0, 13)) % $count)];
            },
            $content,
        );
    }

    private function applyModifier(string $value, string $modifier): string
    {
        return match ($modifier) {
            'uppercase' => mb_strtoupper($value, 'UTF-8'),
            'lowercase' => mb_strtolower($value, 'UTF-8'),
            'ucfirst' => mb_strtoupper(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8').mb_substr($value, 1, null, 'UTF-8'),
            default => $value,
        };
    }

    private function countryName(?string $code): string
    {
        if ($code === null || trim($code) === '') {
            return '';
        }

        return Countries::all()[strtoupper($code)] ?? $code;
    }

    private function stringable(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(fn (mixed $v): string => $this->stringable($v), $value));
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}
