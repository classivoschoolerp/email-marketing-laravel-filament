<?php

declare(strict_types=1);

namespace App\Services\Sending;

use App\Models\EmailList;
use App\Models\Subscriber;

/**
 * RFC 8058 one-click unsubscribe header values (spec §1.5 F-1.5-015).
 * Consumed by WP-13 when assembling campaign messages:
 *
 *   List-Unsubscribe:      <https://…/unsubscribe-one-click>[, <mailto:…>]
 *   List-Unsubscribe-Post: List-Unsubscribe=One-Click
 *
 * The https URI targets the CSRF-exempt POST endpoint; the subscriber uid in
 * the URL is the access secret (MailWizz pattern — uids are unguessable
 * UUIDs, never enumerable). A mailto fallback is appended when the list has
 * a from address, for clients that only honor mailto.
 */
final class ListUnsubscribeHeaders
{
    /** @return array{List-Unsubscribe: string, List-Unsubscribe-Post: string} */
    public function headersFor(Subscriber $subscriber): array
    {
        $list = $this->listFor($subscriber);

        $targets = ['<'.$this->url($list, $subscriber).'>'];

        if ($list->from_email !== null && trim((string) $list->from_email) !== '') {
            $targets[] = '<mailto:'.$list->from_email.'?subject=unsubscribe>';
        }

        return [
            'List-Unsubscribe' => implode(', ', $targets),
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];
    }

    /** The RFC 8058 POST target for a subscriber. */
    public function oneClickUrl(Subscriber $subscriber): string
    {
        return $this->url($this->listFor($subscriber), $subscriber);
    }

    private function url(EmailList $list, Subscriber $subscriber): string
    {
        return route('lists.public.unsubscribe.one_click', [
            'list_uid' => $list->uid,
            'subscriber_uid' => $subscriber->uid,
        ]);
    }

    private function listFor(Subscriber $subscriber): EmailList
    {
        // Works from any context (jobs, admin, public) — never tenant-bound.
        return $subscriber->list()->withoutGlobalScopes()->firstOrFail();
    }
}
