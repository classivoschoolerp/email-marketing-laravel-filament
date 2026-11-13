<?php

declare(strict_types=1);

namespace App\Services\Lists;

use App\Enums\ListPageType;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Support\Geo\Countries;
use Illuminate\Support\Facades\URL;

/**
 * Renders the effective content of a public list page or list email (spec
 * §1.5): resolves it through ListPageResolver's 3-layer merge, substitutes
 * the subscriber/list/company merge tags, and provides the auto-injected
 * CAN-SPAM/GDPR company footer (F-1.5-014) plus context-safe custom CSS/JS
 * (F-1.5-017).
 *
 * Substituted values are HTML-escaped; only the customer-authored
 * company_footer is rendered raw (it is page content, same trust level as
 * the page body).
 */
class PublicPageRenderer
{
    public function __construct(private readonly ListPageResolver $resolver) {}

    /** Effective page/email HTML with merge tags substituted. */
    public function html(EmailList $list, ListPageType $type, ?Subscriber $subscriber = null): string
    {
        return $this->substitute($this->resolver->content($list, $type), $list, $subscriber);
    }

    /** Effective email subject with merge tags substituted (email types). */
    public function subject(EmailList $list, ListPageType $type, ?Subscriber $subscriber = null): ?string
    {
        $subject = $this->resolver->subject($list, $type);

        return $subject === null ? null : $this->substitute($subject, $list, $subscriber);
    }

    /** Replace every known merge tag with its (escaped) value. */
    public function substitute(string $content, ?EmailList $list, ?Subscriber $subscriber = null): string
    {
        return strtr($content, $this->tagValues($list, $subscriber));
    }

    /**
     * The merge-tag value map (see ListPageResolver::mergeTags()). Tags whose
     * subject is missing (no subscriber / no list) resolve to ''.
     *
     * @return array<string, string>
     */
    public function tagValues(?EmailList $list, ?Subscriber $subscriber = null): array
    {
        $values = [
            '[SUBSCRIBER_EMAIL]' => e((string) ($subscriber->email ?? '')),
            '[SUBSCRIBER_UID]' => e((string) ($subscriber->uid ?? '')),
            '[CONFIRM_URL]' => '',
            '[UNSUBSCRIBE_URL]' => '',
            '[UPDATE_PROFILE_URL]' => '',
            '[PREFERENCES_URL]' => '',
            '[RESUBSCRIBE_URL]' => '',
            '[LIST_NAME]' => e((string) ($list->name ?? '')),
            '[LIST_UID]' => e((string) ($list->uid ?? '')),
            '[LIST_FROM_NAME]' => e((string) ($list->from_name ?? '')),
            '[LIST_FROM_EMAIL]' => e((string) ($list->from_email ?? '')),
            '[COMPANY_NAME]' => e((string) ($list->company_name ?? '')),
            '[COMPANY_FULL_ADDRESS]' => e($list === null ? '' : $this->companyFullAddress($list)),
            '[COMPANY_PHONE]' => e((string) ($list->company_phone ?? '')),
            '[COMPANY_WEBSITE]' => e((string) ($list->company_website ?? '')),
        ];

        if ($list !== null) {
            // Without a known subscriber (list-level content) the only place
            // to resubscribe is the public subscribe form.
            $values['[RESUBSCRIBE_URL]'] = e(route('lists.public.subscribe', ['list_uid' => $list->uid]));
        }

        if ($list !== null && $subscriber !== null) {
            $values['[CONFIRM_URL]'] = e($this->confirmUrl($list, $subscriber));
            $values['[UNSUBSCRIBE_URL]'] = e(route('lists.public.unsubscribe', [
                'list_uid' => $list->uid, 'subscriber_uid' => $subscriber->uid,
            ]));
            $values['[UPDATE_PROFILE_URL]'] = e(route('lists.public.update_profile', [
                'list_uid' => $list->uid, 'subscriber_uid' => $subscriber->uid,
            ]));
            // F-1.5-012/013 (WP-42): the person is known, so both links can
            // be their own — the preference center and the one-click
            // resubscribe page instead of a blank subscribe form.
            $values['[PREFERENCES_URL]'] = e(route('lists.public.preferences', [
                'list_uid' => $list->uid, 'subscriber_uid' => $subscriber->uid,
            ]));
            $values['[RESUBSCRIBE_URL]'] = e(route('lists.public.resubscribe', [
                'list_uid' => $list->uid, 'subscriber_uid' => $subscriber->uid,
            ]));
        }

        return $values;
    }

    /**
     * Signed double opt-in confirmation URL (F-1.5-004). Honors the list's
     * optin_confirm_expiry_hours; 0 = no expiry (D-23).
     */
    public function confirmUrl(EmailList $list, Subscriber $subscriber): string
    {
        $parameters = ['list_uid' => $list->uid, 'subscriber_uid' => $subscriber->uid];

        if ($list->optin_confirm_expiry_hours > 0) {
            return URL::temporarySignedRoute(
                'lists.public.confirm',
                now()->addHours($list->optin_confirm_expiry_hours),
                $parameters,
            );
        }

        return URL::signedRoute('lists.public.confirm', $parameters);
    }

    /**
     * CAN-SPAM/GDPR footer auto-injected on every list page and list email
     * (F-1.5-014), built from the list's Company tab (F-2.2.2-012/013).
     */
    public function footer(EmailList $list): string
    {
        $lines = [];

        $identity = array_filter([
            $list->company_name,
            $this->companyFullAddress($list),
        ], fn (?string $v): bool => $v !== null && trim($v) !== '');

        if ($identity !== []) {
            $lines[] = '<p>'.implode(' &middot; ', array_map(e(...), $identity)).'</p>';
        }

        $contact = array_filter([
            $list->company_phone,
            $list->company_website,
        ], fn (?string $v): bool => $v !== null && trim($v) !== '');

        if ($contact !== []) {
            $lines[] = '<p>'.implode(' &middot; ', array_map(e(...), $contact)).'</p>';
        }

        // Customer-authored footer (may contain markup — same trust level as
        // the page content itself).
        if ($list->company_footer !== null && trim((string) $list->company_footer) !== '') {
            $lines[] = $this->substitute((string) $list->company_footer, $list);
        }

        if ($lines === []) {
            return '';
        }

        return '<footer class="emp-company-footer">'.implode('', $lines).'</footer>';
    }

    /** Single-line company postal address from the list's Company tab. */
    public function companyFullAddress(EmailList $list): string
    {
        $country = null;

        if ($list->company_country !== null && $list->company_country !== '') {
            $country = Countries::all()[strtoupper((string) $list->company_country)] ?? $list->company_country;
        }

        $parts = array_filter([
            $list->company_address_1,
            $list->company_address_2,
            trim(implode(' ', array_filter([$list->company_zip, $list->company_city]))),
            $list->company_zone,
            $country,
        ], fn (?string $v): bool => $v !== null && trim($v) !== '');

        return implode(', ', $parts);
    }

    /**
     * Custom CSS made safe for inlining inside a <style> block: the only way
     * to escape that context is a closing tag, so `</style` sequences are
     * stripped (F-1.5-017).
     */
    public function safeCss(?string $css): ?string
    {
        if ($css === null || trim($css) === '') {
            return null;
        }

        return preg_replace('~<\s*/\s*style~i', '', $css);
    }

    /** Same contract as safeCss() for inline <script> blocks. */
    public function safeJs(?string $js): ?string
    {
        if ($js === null || trim($js) === '') {
            return null;
        }

        return preg_replace('~<\s*/\s*script~i', '', $js);
    }
}
