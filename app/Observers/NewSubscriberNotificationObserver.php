<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\NotificationEvent;
use App\Enums\SubscriberSource;
use App\Mail\NewSubscriberMail;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Services\Notifications\CustomerNotifier;
use Illuminate\Support\Facades\Mail;

/**
 * §2.15.3 "new subscriber" notification (WP-39).
 *
 * Two gates, both deliberate:
 *
 *  - the LIST must have `subscribe_notify` on. Those columns have existed
 *    since WP-06/07 and the list editor writes them, but nothing read them;
 *    this is the reader.
 *  - the source must be a real-time join (form / API / manual). An import
 *    of 50 000 rows must never produce 50 000 notifications, and an
 *    automation moving people between lists is not "a new subscriber" in
 *    the sense the account cares about.
 *
 * On top of that the customer's own §2.15.3 preference decides the channel;
 * the event defaults to OFF for both channels precisely because it is the
 * noisiest one in the set.
 */
class NewSubscriberNotificationObserver
{
    /** Sources that represent a genuine, one-at-a-time join. */
    private const REALTIME_SOURCES = [
        SubscriberSource::Form,
        SubscriberSource::Api,
        SubscriberSource::Manual,
    ];

    public function __construct(private readonly CustomerNotifier $notifier) {}

    public function created(Subscriber $subscriber): void
    {
        if (! in_array($subscriber->source, self::REALTIME_SOURCES, true)) {
            return;
        }

        $list = EmailList::withoutTenancy()->find($subscriber->list_id);

        if ($list === null || ! $list->subscribe_notify) {
            return;
        }

        $customer = Customer::query()->withoutGlobalScopes()->find($subscriber->customer_id);

        if ($customer === null) {
            return;
        }

        $this->notifier->inApp(
            $customer,
            NotificationEvent::NewSubscriber,
            __('notifications.in_app.new_subscriber.title'),
            __('notifications.in_app.new_subscriber.body', [
                'email' => (string) $subscriber->email,
                'list' => (string) $list->name,
            ]),
            icon: 'heroicon-o-user-plus',
        );

        if (! $this->notifier->allowsEmail($customer, NotificationEvent::NewSubscriber)) {
            return;
        }

        $recipients = $this->recipients($list, $customer);

        if ($recipients === []) {
            return;
        }

        Mail::to($recipients)->queue(new NewSubscriberMail(
            (string) $list->name,
            (string) $subscriber->email,
        ));
    }

    /**
     * Addresses configured on the list, falling back to the account owner.
     *
     * @return list<string>
     */
    private function recipients(EmailList $list, Customer $customer): array
    {
        $raw = (string) ($list->subscribe_notify_emails ?? '');
        $addresses = [];

        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $candidate) {
            $email = mb_strtolower(trim((string) $candidate));

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $addresses[$email] = $email;
            }
        }

        if ($addresses === [] && trim((string) $customer->email) !== '') {
            $addresses[(string) $customer->email] = (string) $customer->email;
        }

        return array_values($addresses);
    }
}
