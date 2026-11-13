<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Enums\CampaignType;
use App\Enums\OptInType;
use App\Models\Campaign;
use App\Models\SendingDomain;
use App\Services\Content\AccessibilityChecker;
use App\Services\Content\ConditionalContent;
use App\Services\Content\MergeTags;
use App\Services\Privacy\ConsentRules;
use App\Services\Settings\SettingsReader;
use App\Services\Templates\UnsubscribeTagChecker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Pre-flight checklist (spec §2.3.5 F-2.3.5-008): unsubscribe tag present,
 * subject non-empty, from set, target list chosen, schedule valid for the
 * campaign type, no broken merge tags, blacklisted-word scan. Error-level
 * failures block Schedule/Send; warning-level ones (flag-mode word hits)
 * surface but do not block.
 *
 * WP-27B closed the last item: from-domain authentication against the WP-15
 * `sending_domains` table (see {@see fromDomainCheck()}).
 */
class CampaignPreflight
{
    public const LEVEL_ERROR = 'error';

    public const LEVEL_WARNING = 'warning';

    public function __construct(
        private readonly UnsubscribeTagChecker $unsubscribeCheck,
        private readonly RecurringSchedule $recurring,
        private readonly BlacklistedWordScanner $words,
        private readonly MergeTags $mergeTags,
    ) {}

    /**
     * @return list<array{key: string, passed: bool, level: string, label: string, detail: ?string}>
     */
    public function checks(Campaign $campaign): array
    {
        $checks = [];

        $hasContent = $campaign->content_html !== null && trim((string) $campaign->content_html) !== '';

        $checks[] = $this->check('content', $hasContent);

        $checks[] = $this->check(
            'unsubscribe_tag',
            $hasContent && $this->unsubscribeCheck->passes($campaign->content_html),
        );

        $checks[] = $this->check('subject', trim((string) $campaign->subject) !== '');

        $checks[] = $this->check(
            'from',
            trim((string) $campaign->from_name) !== '' && $this->fromEmailValid($campaign->from_email),
        );

        $checks[] = $this->check('list', $campaign->email_list_id !== null);

        $checks[] = $this->scheduleCheck($campaign);

        $unknown = $this->unknownMergeTags($campaign);
        $checks[] = $this->check(
            'merge_tags',
            $unknown === [],
            detail: $unknown === [] ? null : implode(', ', array_map(fn (string $tag): string => '{'.$tag.'}', $unknown)),
        );

        // Images without alt text — accessibility warning (F-2.3.5-008).
        $missingAlt = $this->imagesWithoutAlt((string) $campaign->content_html);
        $checks[] = $this->check(
            'image_alt',
            $missingAlt === 0,
            level: self::LEVEL_WARNING,
            detail: $missingAlt > 0 ? (string) $missingAlt : null,
        );

        // WP-46 (§2.3.5 F-2.3.5-007): the full accessibility pass — alt text,
        // link text, heading order, layout tables, font size and real WCAG
        // contrast. It stays a WARNING on purpose: `image_alt` above is the
        // §2.3.5-008 checklist item and already speaks for the alt-text half,
        // and blocking a send on a 4.4:1 contrast ratio would be this build
        // deciding a design question for the customer. What it must never do
        // is stay silent, so the detail carries the exact tally.
        $accessibility = $this->accessibilityFindings($campaign);

        if ($accessibility !== null) {
            $checks[] = $accessibility;
        }

        $hits = array_values(array_unique(array_merge(
            $this->words->subjectMatches($campaign->subject),
            $this->words->contentMatches($campaign->content_html),
        )));
        $checks[] = $this->check(
            'blacklisted_words',
            $hits === [],
            level: $this->words->blocks() ? self::LEVEL_ERROR : self::LEVEL_WARNING,
            detail: $hits === [] ? null : implode(', ', $hits),
        );

        // WP-16 (§2.9.12 F-2.9.12-010): the ONE verification check — block
        // sending when the target list was not verified within N days
        // (config emp-verification.preflight.max_age_days; 0 = off, D-23).
        $maxAgeDays = (int) config('emp-verification.preflight.max_age_days', 0);

        if ($maxAgeDays > 0) {
            $checks[] = $this->check(
                'list_verified',
                $this->listVerifiedWithin($campaign, $maxAgeDays),
                detail: (string) $maxAgeDays,
            );
        }

        // WP-22 (§4.5 F-4.5-002): the ONE CAN-SPAM check — a campaign may not
        // be scheduled when its list carries no physical postal address, so
        // the auto-injected footer can never go out empty. Setting-gated
        // (privacy.canspam_enforce_address, default off) exactly like the
        // WP-16 verification gate above.
        if ($this->canSpamEnforced()) {
            $checks[] = $this->check(
                'company_address',
                $this->listHasPostalAddress($campaign),
            );
        }

        // WP-42 (§4.5 F-4.5-003): CASL + country-specific consent rules. The
        // law is chosen from the target list's declared company country; the
        // two obligations that can be checked BEFORE a send is attempted are
        // gathered here, and both carry the law's name in `detail` so an
        // operator can see which regime is speaking.
        $consentRules = app(ConsentRules::class);

        if ($consentRules->enforced() && $campaign->email_list_id !== null) {
            $rule = $consentRules->forCampaign($campaign);
            $list = $campaign->list()->withoutGlobalScopes()->first();

            if ($rule->requiresExpressConsent) {
                $checks[] = $this->check(
                    'consent_opt_in',
                    $list !== null && $list->opt_in === OptInType::Double,
                    detail: $rule->law,
                );
            }

            if ($rule->requiresSenderIdentification) {
                $checks[] = $this->check(
                    'consent_sender_identification',
                    $list !== null
                        && trim((string) $list->company_name) !== ''
                        && $this->listHasPostalAddress($campaign),
                    detail: $rule->law,
                );
            }
        }

        // WP-35 (§2.3.6): a campaign flagged as an A/B test must actually BE
        // one before it can be scheduled — 2..8 variants, a chosen variable
        // and a sane test percentage. Without this the send path would fall
        // back to a plain send and the customer would never learn their test
        // did not run (AbTestManager::sendableVariants returns []).
        if ($campaign->ab_enabled) {
            $problems = app(AbTest\AbTestManager::class)->problems($campaign);

            $checks[] = $this->check(
                'ab_test',
                $problems === [],
                detail: $problems === [] ? null : implode(' · ', $problems),
            );
        }

        // WP-35 (§2.3.3 F-2.3.3-008): an [IF] with no [ENDIF] silently
        // swallows the rest of the email at render time — warn before sending.
        $conditionals = app(ConditionalContent::class);
        $content = (string) $campaign->content_html;

        if ($conditionals->has($content)) {
            $checks[] = $this->check('conditional_blocks', $conditionals->isBalanced($content));
        }

        // WP-15/WP-27B (§2.3.5 F-2.3.5-008 "from-domain authenticated"): the
        // last checklist item. The domain part of the From address must be
        // covered by a sending domain whose status is Verified — which per
        // D-66 means DKIM + SPF + DMARC all resolved (MX optional).
        $fromDomainCheck = $this->fromDomainCheck($campaign);

        if ($fromDomainCheck !== null) {
            $checks[] = $fromDomainCheck;
        }

        return $checks;
    }

    /* ================================================================ *
     *  From-domain authentication (F-2.3.5-008, WP-15 sending_domains)
     * ================================================================ */

    /**
     * The from-domain check, or NULL when it does not apply.
     *
     * Three honest skips, in order:
     *   1. the gate is off (config, see {@see fromDomainEnforced()});
     *   2. the From address has no resolvable literal domain — it is empty,
     *      malformed or fully merge-tag driven ({FROM_EMAIL}), in which case
     *      the separate `from` check already speaks and the domain is not
     *      knowable until send time;
     *   3. the install has no sending domain at all in scope for this tenant
     *      — the WP-15 feature is simply not in use here, and failing every
     *      campaign over an unused feature would be a lie, not a check.
     * Everything else is a real pass/fail.
     *
     * @return array{key: string, passed: bool, level: string, label: string, detail: ?string}|null
     */
    private function fromDomainCheck(Campaign $campaign): ?array
    {
        if (! $this->fromDomainEnforced()) {
            return null;
        }

        $domain = $this->fromDomain($campaign);

        if ($domain === null) {
            return null;
        }

        $domains = $this->sendingDomainsFor($campaign);

        if ($domains->isEmpty()) {
            return null;
        }

        return $this->check(
            'from_domain',
            $this->domainAuthenticatedBy($domain, $domains),
            detail: $domain,
        );
    }

    /**
     * §2.3.5 enforcement toggle. DEFAULT OFF, deliberately and for the same
     * reason as the WP-16 verification gate (`max_age_days` = 0) and the
     * WP-22 CAN-SPAM gate (`canspam_enforce_address` = false): verification
     * needs real public DNS (D-16/D-66), so a default-on gate would hard-block
     * scheduling on every install that has not yet published DKIM/SPF/DMARC —
     * including this repo's own dev/test environment. Operators who run their
     * own authenticated domains flip it on with
     * EMP_CAMPAIGNS_REQUIRE_AUTH_FROM_DOMAIN=true.
     */
    private function fromDomainEnforced(): bool
    {
        return (bool) config('emp-campaigns.preflight.require_authenticated_from_domain', false);
    }

    /**
     * Literal domain part of the From address, lowercased; null when it is
     * absent, malformed or merge-tag driven (resolved only at send time).
     */
    public function fromDomain(Campaign $campaign): ?string
    {
        $email = trim((string) $campaign->from_email);

        if ($email === '' || ! str_contains($email, '@')) {
            return null;
        }

        $domain = mb_strtolower(trim(Str::afterLast($email, '@')));

        if ($domain === '' || str_contains($domain, '{') || ! str_contains($domain, '.')) {
            return null;
        }

        return $domain;
    }

    /**
     * Sending domains visible to the campaign's tenant: its own rows plus the
     * operator's global rows (customer_id NULL, §3.13.5). Tenancy is dropped
     * on purpose — preflight runs from queued/console contexts too — and
     * re-applied explicitly by the customer_id predicate.
     *
     * @return Collection<int, SendingDomain>
     */
    private function sendingDomainsFor(Campaign $campaign): Collection
    {
        return SendingDomain::withoutGlobalScopes()
            ->where(function ($query) use ($campaign): void {
                $query->whereNull('customer_id');

                if ($campaign->customer_id !== null) {
                    $query->orWhere('customer_id', $campaign->customer_id);
                }
            })
            ->get(['id', 'customer_id', 'name', 'status']);
    }

    /**
     * True when one VERIFIED sending domain covers the From domain. Exact
     * match, or the relaxed-alignment case every ESP accepts: a subdomain of
     * a verified organisational domain (news.acme.com under acme.com), which
     * is exactly what DKIM d= / SPF relaxed alignment allows.
     *
     * @param  Collection<int, SendingDomain>  $domains
     */
    private function domainAuthenticatedBy(string $fromDomain, Collection $domains): bool
    {
        foreach ($domains as $sendingDomain) {
            // D-66: status Verified is set only when DKIM + SPF + DMARC all
            // pass (SendingDomainVerifier), so this single test is the
            // three-record test.
            if (! $sendingDomain->isVerified()) {
                continue;
            }

            $name = mb_strtolower(trim((string) $sendingDomain->name));

            if ($name === '') {
                continue;
            }

            if ($fromDomain === $name || str_ends_with($fromDomain, '.'.$name)) {
                return true;
            }
        }

        return false;
    }

    /** §4.5 CAN-SPAM enforcement toggle; false before the settings migration. */
    private function canSpamEnforced(): bool
    {
        try {
            return SettingsReader::privacy()->canSpamEnforceAddress();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * True when the campaign's list has a usable postal address: a street
     * line plus at least one of city/zip — the CAN-SPAM minimum.
     */
    private function listHasPostalAddress(Campaign $campaign): bool
    {
        if ($campaign->email_list_id === null) {
            return false;
        }

        $list = $campaign->list()->withoutGlobalScopes()->first();

        if ($list === null) {
            return false;
        }

        $street = trim((string) $list->company_address_1);
        $locality = trim((string) $list->company_city).trim((string) $list->company_zip);

        return $street !== '' && $locality !== '';
    }

    /**
     * WP-16: true when the campaign's list has a completed verification run
     * (verification_jobs) that finished within the last N days.
     */
    private function listVerifiedWithin(Campaign $campaign, int $days): bool
    {
        if ($campaign->email_list_id === null) {
            return false;
        }

        return \App\Models\VerificationJob::withoutTenancy()
            ->where('list_id', $campaign->email_list_id)
            ->where('status', \App\Models\VerificationJob::STATUS_COMPLETED)
            ->where('finished_at', '>=', Carbon::now()->subDays($days))
            ->exists();
    }

    /** All error-level checks pass — the campaign may be scheduled/sent. */
    public function passes(Campaign $campaign): bool
    {
        foreach ($this->checks($campaign) as $check) {
            if ($check['level'] === self::LEVEL_ERROR && ! $check['passed']) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> error messages of failing error-level checks */
    public function failures(Campaign $campaign): array
    {
        $messages = [];

        foreach ($this->checks($campaign) as $check) {
            if ($check['level'] === self::LEVEL_ERROR && ! $check['passed']) {
                $messages[] = $check['label'].($check['detail'] !== null ? ' — '.$check['detail'] : '');
            }
        }

        return $messages;
    }

    /**
     * Merge tags present in subject/content that the WP-13 canonical engine
     * does not recognise for the target list (F-4.1-012): neither enabled
     * seeded tags (§3.25.3), §4.1 registry tags, reserved schema tags nor
     * field tags of the list. Modifier/default forms ({FNAME|Friend}) are
     * scanned too; lowercase spintax groups never match the tag pattern.
     *
     * @return list<string>
     */
    public function unknownMergeTags(Campaign $campaign): array
    {
        $text = (string) $campaign->subject.' '.(string) $campaign->content_html;

        return $this->mergeTags->unknownTags(
            $text,
            $campaign->email_list_id !== null
                ? $campaign->list()->withoutGlobalScopes()->first()
                : null,
        );
    }

    private function scheduleCheck(Campaign $campaign): array
    {
        $passed = match ($campaign->type) {
            CampaignType::Regular => $campaign->send_immediately
                || ($campaign->send_at !== null && $this->sendAtSatisfiesOffset($campaign->send_at)),
            CampaignType::Autoresponder => $campaign->ar_trigger !== null
                && $campaign->ar_delay_value !== null
                && $campaign->ar_delay_unit !== null,
            CampaignType::Recurring => $this->recurring->nextRunAt($campaign) !== null,
        };

        return $this->check('schedule', $passed);
    }

    private function sendAtSatisfiesOffset(Carbon $sendAt): bool
    {
        $offset = SettingsReader::campaign()->sendAtMinOffsetMinutes();

        // D-23: 0 = disabled — any future (or past ⇒ send asap) date is fine.
        if ($offset <= 0) {
            return true;
        }

        return $sendAt->greaterThanOrEqualTo(Carbon::now()->addMinutes($offset));
    }

    /**
     * WP-46 (§2.3.5 F-2.3.5-007). NULL when the campaign has no content yet —
     * the `content` check already speaks and an empty body has no findings.
     *
     * `passed` counts ERROR-level findings only (missing alt, unreadable link,
     * sub-AA contrast). Warnings and notices are reported in the detail but do
     * not fail the row, so a customer is told about every notice without being
     * blocked by one. {@see AccessibilityChecker} documents each rule.
     *
     * @return array{key: string, passed: bool, level: string, label: string, detail: ?string}|null
     */
    private function accessibilityFindings(Campaign $campaign): ?array
    {
        $html = (string) $campaign->content_html;

        if (trim($html) === '') {
            return null;
        }

        $checker = app(AccessibilityChecker::class);
        $summary = $checker->summary($checker->check($html));

        $detail = array_sum($summary) === 0
            ? null
            : __('accessibility.summary', [
                'errors' => $summary[AccessibilityChecker::SEVERITY_ERROR],
                'warnings' => $summary[AccessibilityChecker::SEVERITY_WARNING],
                'notices' => $summary[AccessibilityChecker::SEVERITY_NOTICE],
            ]);

        return $this->check(
            'accessibility',
            $summary[AccessibilityChecker::SEVERITY_ERROR] === 0,
            level: self::LEVEL_WARNING,
            detail: $detail,
        );
    }

    /** Number of <img> tags lacking a non-empty alt attribute. */
    private function imagesWithoutAlt(string $html): int
    {
        if ($html === '' || stripos($html, '<img') === false) {
            return 0;
        }

        preg_match_all('/<img\b[^>]*>/iu', $html, $images);

        $missing = 0;

        foreach ($images[0] as $tag) {
            if (! preg_match('/\balt\s*=\s*["\'][^"\']+["\']/iu', $tag)) {
                $missing++;
            }
        }

        return $missing;
    }

    /** From-emails may themselves carry merge tags (F-2.3.2-007). */
    private function fromEmailValid(?string $email): bool
    {
        $email = trim((string) $email);

        if ($email === '') {
            return false;
        }

        if (preg_match('/^\{[A-Z][A-Z0-9_]*\}$/u', $email) === 1) {
            return true; // fully tag-driven, resolved at send time
        }

        $resolved = (string) preg_replace('/\{[A-Z][A-Z0-9_]*\}/u', 'example.com', $email);

        return filter_var($resolved, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * @return array{key: string, passed: bool, level: string, label: string, detail: ?string}
     */
    private function check(string $key, bool $passed, string $level = self::LEVEL_ERROR, ?string $detail = null): array
    {
        return [
            'key' => $key,
            'passed' => $passed,
            'level' => $level,
            'label' => __('campaigns.preflight.'.$key),
            'detail' => $detail,
        ];
    }
}
