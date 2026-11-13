<?php

declare(strict_types=1);

namespace App\Services\Monitors;

use App\Enums\MonitorAction;
use App\Enums\SubscriberStatus;
use App\Mail\MonitorAlertMail;
use App\Models\EmailBoxMonitor;
use App\Models\EmailBoxMonitorCondition;
use App\Models\EmailList;
use App\Models\GlobalBlacklistEmail;
use App\Models\Subscriber;
use App\Services\Settings\SettingsReader;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Applies a matched condition's action (spec §2.9.6 F-2.9.6-003, WP-33).
 *
 * Subscriber transitions run THROUGH the model (never mass-update), so the
 * list's subscriber-actions cascade fires on every path — the same rule
 * BounceProcessor follows. Returns a short human-readable result string for
 * the processing log.
 */
class MonitorActionApplier
{
    public const SOURCE = 'monitor';

    /**
     * @return array{0: string, 1: Subscriber|null} [log result, subscriber touched]
     */
    public function apply(
        EmailBoxMonitor $monitor,
        EmailBoxMonitorCondition $condition,
        MonitorMessage $message,
    ): array {
        $subscriber = $condition->action->needsSubscriber()
            ? $this->findSubscriber($monitor, $message)
            : null;

        if ($condition->action->needsSubscriber() && $subscriber === null) {
            return [__('infra.monitors.results.no_subscriber'), null];
        }

        return match ($condition->action) {
            MonitorAction::Unsubscribe => [$this->unsubscribe($subscriber), $subscriber],
            MonitorAction::Blacklist => [$this->blacklist($subscriber), $subscriber],
            MonitorAction::DeleteSubscriber => [$this->deleteSubscriber($subscriber), $subscriber],
            MonitorAction::MoveToList => [$this->moveToList($condition, $subscriber), $subscriber],
            MonitorAction::NotifyAdmin => [$this->notifyAdmin($monitor, $condition, $message), null],
        };
    }

    /**
     * The sender address, resolved inside the monitor's tenancy: a global
     * monitor (customer_id NULL) may act on any tenant's subscriber; a
     * customer monitor is fenced to its owner's rows.
     */
    public function findSubscriber(EmailBoxMonitor $monitor, MonitorMessage $message): ?Subscriber
    {
        if ($message->fromEmail === null) {
            return null;
        }

        return Subscriber::withoutTenancy()
            ->where('email', $message->fromEmail)
            ->when($monitor->customer_id !== null, fn ($q) => $q->where('customer_id', $monitor->customer_id))
            ->orderByDesc('id')
            ->first();
    }

    private function unsubscribe(Subscriber $subscriber): string
    {
        $subscriber->status = SubscriberStatus::Unsubscribed;
        $subscriber->save();

        return __('infra.monitors.results.unsubscribed', ['email' => $subscriber->email]);
    }

    private function blacklist(Subscriber $subscriber): string
    {
        GlobalBlacklistEmail::query()->firstOrCreate(
            ['email' => $subscriber->email],
            [
                'reason' => __('infra.monitors.blacklist_reason'),
                'source' => self::SOURCE,
                'customer_id' => $subscriber->customer_id,
            ],
        );

        return __('infra.monitors.results.blacklisted', ['email' => $subscriber->email]);
    }

    private function deleteSubscriber(Subscriber $subscriber): string
    {
        $email = (string) $subscriber->email;
        $subscriber->delete();

        return __('infra.monitors.results.deleted', ['email' => $email]);
    }

    private function moveToList(EmailBoxMonitorCondition $condition, Subscriber $subscriber): string
    {
        $list = $condition->target_list_id !== null
            ? EmailList::withoutTenancy()->find($condition->target_list_id)
            : null;

        if ($list === null) {
            return __('infra.monitors.results.no_target_list');
        }

        // Never move a subscriber across a tenancy boundary.
        if ((int) $list->customer_id !== (int) $subscriber->customer_id) {
            return __('infra.monitors.results.cross_tenant_list');
        }

        $subscriber->list_id = $list->id;
        $subscriber->save();

        return __('infra.monitors.results.moved', ['email' => $subscriber->email, 'list' => $list->name]);
    }

    private function notifyAdmin(
        EmailBoxMonitor $monitor,
        EmailBoxMonitorCondition $condition,
        MonitorMessage $message,
    ): string {
        $recipients = $this->recipients($condition);

        if ($recipients === []) {
            return __('infra.monitors.results.no_recipient');
        }

        Mail::to($recipients)->send(new MonitorAlertMail(
            (string) $monitor->name,
            $message->subject,
            $message->fromEmail ?? $message->from,
        ));

        return __('infra.monitors.results.notified', ['count' => count($recipients)]);
    }

    /** @return list<string> */
    private function recipients(EmailBoxMonitorCondition $condition): array
    {
        $explicit = trim((string) $condition->notify_email);

        if ($explicit !== '') {
            return [$explicit];
        }

        try {
            return SettingsReader::common()->adminNotificationEmails();
        } catch (Throwable) {
            return []; // settings not migrated yet (fresh install)
        }
    }
}
