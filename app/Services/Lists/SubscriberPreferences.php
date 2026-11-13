<?php

declare(strict_types=1);

namespace App\Services\Lists;

use App\Enums\OptInType;
use App\Enums\SendFrequency;
use App\Enums\SubscriberSource;
use App\Enums\SubscriberStatus;
use App\Models\ConsentEvent;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Observers\ConsentEventObserver;
use App\Services\Blacklist\BlacklistMatcher;
use App\Support\Privacy\IpAnonymizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;
use TypeError;
use ValueError;

/**
 * The engine behind the two public preference pages of spec §1.5:
 * the preference center (F-1.5-012) and the resubscribe page (F-1.5-013).
 *
 * TOPICS. This platform has no separate interest/category entity — in a
 * list-per-topic ESP the customer's own lists ARE the topics, so "choose
 * topics instead of unsubscribing" means joining/leaving the customer's
 * public lists from one page. Only lists of the SAME customer that are
 * `visible` and active are ever offered or accepted, so a uid from another
 * tenant can neither be shown nor selected (docs/ARCHITECTURE.md rule 3 / D-05).
 * Every join and leave goes through the Subscriber model so the list's
 * subscriber-action cascades fire (F-2.2.2-015..018), joins respect the
 * target list's opt-in mode (double → unconfirmed + confirmation email) and
 * the blacklist (D-74), and each membership change is written to the §4.5
 * consent audit trail because it IS a consent change.
 *
 * FREQUENCY. Stored on `subscribers.send_frequency` /
 * `subscribers.frequency_paused_until` (see SendFrequency) and HONOURED at
 * send time by App\Services\Campaigns\CampaignRecipients::applySubscriberFrequency(),
 * the per-subscriber twin of that class's applyFrequencyCap(): a live pause
 * drops the recipient outright, and `weekly`/`monthly` drop anyone who
 * already received a campaign from this customer inside their own window.
 * Because it lives at the same choke point as suppression and the sender's
 * own cap, the wizard's count preview, the send wave and the API can never
 * disagree about who is getting an email.
 */
class SubscriberPreferences
{
    /** Recorded on the subscriber when a topic is dropped here (F-1.5-012). */
    public const LEAVE_REASON = 'preference_center';

    public function __construct(
        private readonly ListMailer $mailer,
        private readonly BlacklistMatcher $blacklist,
    ) {}

    /* ================================================================ *
     *  F-1.5-012 — preference center
     * ================================================================ */

    /**
     * The topics offered on the preference center: every public list of the
     * SAME customer, with the subscriber's current membership state.
     *
     * @return list<array{uid: string, name: string, description: ?string, subscribed: bool, current: bool}>
     */
    public function topics(EmailList $list, Subscriber $subscriber): array
    {
        $rows = $this->membershipRows($list, $subscriber);
        $topics = [];

        foreach ($this->topicLists($list) as $topic) {
            $row = $rows[$topic->id] ?? null;

            $topics[] = [
                'uid' => (string) $topic->uid,
                'name' => (string) $topic->name,
                'description' => $topic->description,
                'subscribed' => $row !== null && $row->status->isActive(),
                'current' => $topic->id === $list->id,
            ];
        }

        return $topics;
    }

    /**
     * Apply the chosen topic set. `$selectedUids` comes straight from the
     * form: unknown and cross-tenant uids are simply not in the offered set
     * and are therefore ignored, never joined.
     *
     * @param  list<string>  $selectedUids
     */
    public function applyTopics(EmailList $list, Subscriber $subscriber, array $selectedUids, string $ip): void
    {
        $rows = $this->membershipRows($list, $subscriber);
        $wanted = array_flip(array_map('strval', $selectedUids));

        foreach ($this->topicLists($list) as $topic) {
            $row = $rows[$topic->id] ?? null;
            $isMember = $row !== null && $row->status->isActive();
            $shouldBeMember = isset($wanted[(string) $topic->uid]);

            if ($isMember === $shouldBeMember) {
                continue;
            }

            if ($shouldBeMember) {
                $this->joinTopic($topic, $subscriber->email, $row, $ip);

                continue;
            }

            $this->leaveTopic($topic, $row, $ip);
        }
    }

    /** Store the send-frequency preference (F-1.5-012). */
    public function saveFrequency(Subscriber $subscriber, SendFrequency $frequency, ?int $pauseDays): void
    {
        $subscriber->send_frequency = $frequency;
        $subscriber->frequency_paused_until = $frequency->isPause()
            ? now()->addDays(max(1, (int) $pauseDays))
            : null;

        $subscriber->save();
    }

    /* ================================================================ *
     *  F-1.5-013 — resubscribe
     * ================================================================ */

    /**
     * Put an unsubscribed person back to the right status for the list's
     * opt-in mode and record a FRESH consent proof (§4.5 F-4.5-004: new
     * subscribe IP + timestamp). Returns the status actually reached so the
     * caller knows which page to show.
     */
    public function resubscribe(EmailList $list, Subscriber $subscriber, string $ip): SubscriberStatus
    {
        $status = $list->opt_in === OptInType::Double
            ? SubscriberStatus::Unconfirmed
            : SubscriberStatus::Confirmed;

        $wasMember = $subscriber->status->isActive();

        $subscriber->status = $status;
        // §4.5 F-4.5-004: a resubscribe is a NEW consent, so the proof pair
        // (timestamp + IP) is rewritten, not kept from the first signup.
        // WP-22 (§3.25.16 F-3.25.16-005): anonymised at capture.
        $subscriber->subscribed_at = now();
        $subscriber->subscribe_ip = IpAnonymizer::maybe($ip);

        if ($status === SubscriberStatus::Confirmed) {
            $subscriber->confirmed_at = now();
        } else {
            // Double opt-in: the express-consent proof is only written by the
            // signed confirm route, so it starts empty again.
            $subscriber->confirmed_at = null;
            $subscriber->confirm_ip = null;
        }

        $subscriber->save();

        if ($status === SubscriberStatus::Unconfirmed) {
            $this->mailer->sendSubscribeConfirmation($list, $subscriber);
        } else {
            $this->mailer->sendWelcome($list, $subscriber);
        }

        if (! $wasMember) {
            $this->recordConsent($subscriber, $list, '0', '1', $ip);
        }

        return $status;
    }

    /**
     * An address that must never be (re)subscribed from a public page: on
     * the global blacklist (D-74 — BlacklistMatcher is the single authority)
     * or flagged blacklisted on the row itself.
     */
    public function blocked(EmailList $list, string $email, ?Subscriber $subscriber = null): bool
    {
        if ($subscriber !== null && $subscriber->status === SubscriberStatus::Blacklisted) {
            return true;
        }

        return $this->blacklist->matches($email, $list->customer_id);
    }

    /* ================================================================ *
     *  internals
     * ================================================================ */

    /**
     * The customer's public lists, in a stable display order. Scoped to
     * `$list->customer_id` — this is what keeps another tenant's lists off
     * the page and out of applyTopics().
     *
     * @return Collection<int, EmailList>
     */
    private function topicLists(EmailList $list): Collection
    {
        return EmailList::withoutTenancy()
            ->where('customer_id', $list->customer_id)
            ->where('visible', true)
            ->where('status', EmailList::STATUS_ACTIVE)
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * This person's subscriber row in each of the customer's lists, keyed by
     * list id. Identity across lists is the email address, exactly as the
     * subscribe form treats a rejoin.
     *
     * @return Collection<int, Subscriber>
     */
    private function membershipRows(EmailList $list, Subscriber $subscriber): Collection
    {
        return Subscriber::withoutTenancy()
            ->where('customer_id', $list->customer_id)
            ->where('email', $subscriber->email)
            ->get()
            ->keyBy('list_id');
    }

    /** Join (or rejoin) one topic list, honoring its opt-in mode. */
    private function joinTopic(EmailList $topic, string $email, ?Subscriber $row, string $ip): void
    {
        // A blacklisted address is never added to a new list (D-74). Leaving
        // stays possible in every case — suppression must not trap anyone.
        if ($this->blocked($topic, $email, $row)) {
            return;
        }

        $status = $topic->opt_in === OptInType::Double
            ? SubscriberStatus::Unconfirmed
            : SubscriberStatus::Confirmed;

        if ($row === null) {
            $row = Subscriber::create([
                'customer_id' => $topic->customer_id,
                'list_id' => $topic->id,
                'email' => $email,
                'status' => $status,
                'source' => SubscriberSource::Form,
                'ip' => IpAnonymizer::maybe($ip),
                'subscribe_ip' => IpAnonymizer::maybe($ip),
            ]);
        } else {
            // Transition through the model so the cascades fire.
            $row->status = $status;
            $row->subscribed_at = now();
            $row->subscribe_ip = IpAnonymizer::maybe($ip);

            if ($status === SubscriberStatus::Confirmed) {
                $row->confirmed_at = now();
            } else {
                $row->confirmed_at = null;
                $row->confirm_ip = null;
            }

            $row->save();
        }

        if ($status === SubscriberStatus::Unconfirmed) {
            $this->mailer->sendSubscribeConfirmation($topic, $row);
        } else {
            $this->mailer->sendWelcome($topic, $row);
        }

        $this->recordConsent($row, $topic, '0', '1', $ip);
    }

    /** Leave one topic list without touching the others. */
    private function leaveTopic(EmailList $topic, ?Subscriber $row, string $ip): void
    {
        if ($row === null) {
            return;
        }

        $row->status = SubscriberStatus::Unsubscribed;
        $row->unsubscribe_ip = IpAnonymizer::maybe($ip);
        $row->unsubscribe_reason = self::LEAVE_REASON;
        $row->save();

        $this->recordConsent($row, $topic, '1', '0', $ip);
    }

    /**
     * Append the membership change to the §4.5 consent audit trail
     * (F-4.5-008). `field` carries the list uid rather than its name so the
     * row survives a rename, mirroring ConsentEventObserver's merge-tag
     * convention. Never allowed to take a public write path down.
     */
    private function recordConsent(Subscriber $subscriber, EmailList $topic, ?string $old, ?string $new, string $ip): void
    {
        try {
            if (! Schema::hasTable('consent_events')) {
                return; // pre-migration (installer)
            }

            ConsentEvent::withoutTenancy()->create([
                'customer_id' => $topic->customer_id,
                'subscriber_id' => $subscriber->id,
                'list_field_id' => null,
                'field' => mb_substr('list:'.$topic->uid, 0, 64),
                'old_value' => $old,
                'new_value' => $new,
                // F-3.25.16-005: consent proof honours the anonymise-IP toggle.
                'ip' => IpAnonymizer::maybe($ip),
                'user_agent' => mb_substr((string) request()?->userAgent(), 0, 512) ?: null,
                'source' => ConsentEventObserver::source(),
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // The audit trail must never break a preference save.
        }
    }

    /** Parse a submitted frequency value, defaulting to "every send". */
    public static function frequency(mixed $value): SendFrequency
    {
        try {
            return SendFrequency::from((string) $value);
        } catch (ValueError|TypeError) {
            return SendFrequency::EverySend;
        }
    }
}
