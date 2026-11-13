<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Models\Customer;
use App\Models\SubUser;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Throwable;

/**
 * §4.3 in-app notifications + the §2.15.3 delivery gate (WP-39).
 *
 * One place decides whether a customer hears about an event, so a
 * preference the customer switched off actually suppresses delivery
 * instead of merely hiding a checkbox:
 *
 *  - inApp()      writes a Filament database notification (the bell on the
 *                 customer panel, F-4.3-001) when the in-app channel is on;
 *  - allowsEmail() is what every existing Mail::to(...)->queue(...) sender
 *                 asks before queueing, so the same toggle governs both.
 *
 * Long-running jobs (import, export, campaign send — F-4.3-002) call
 * inApp() at their completion point; Filament's polling bell turns that
 * into the toast the moment it lands.
 */
class CustomerNotifier
{
    public function __construct(private readonly NotificationPreferences $preferences) {}

    /**
     * F-4.3-001/002 — deliver an in-app notification, honouring §2.15.3.
     *
     * @param  array<int, array{label: string, url: string}>  $actions
     * @return bool whether it was actually delivered
     */
    public function inApp(
        Customer $customer,
        NotificationEvent $event,
        string $title,
        ?string $body = null,
        string $status = 'info',
        ?string $icon = null,
        array $actions = [],
        ?SubUser $subUser = null,
    ): bool {
        if (! $this->preferences->allows($customer, $event, NotificationChannel::InApp, $subUser)) {
            return false;
        }

        return $this->push($customer, $title, $body, $status, $icon, $actions);
    }

    /**
     * Ungated in-app delivery for messages that are not one of the
     * §2.15.3 events — currently only the F-4.3-003 admin broadcast, which
     * is an operator announcement rather than an account event and so has
     * no per-event toggle to consult.
     */
    public function announce(
        Customer $customer,
        string $title,
        ?string $body = null,
        string $status = 'info',
        ?string $icon = null,
        array $actions = [],
    ): bool {
        return $this->push($customer, $title, $body, $status, $icon, $actions);
    }

    /** §2.15.3 gate for the existing email senders. */
    public function allowsEmail(Customer $customer, NotificationEvent $event, ?SubUser $subUser = null): bool
    {
        return $this->preferences->allows($customer, $event, NotificationChannel::Email, $subUser);
    }

    /**
     * @param  array<int, array{label: string, url: string}>  $actions
     */
    private function push(
        Customer $customer,
        string $title,
        ?string $body,
        string $status,
        ?string $icon,
        array $actions,
    ): bool {
        try {
            $notification = Notification::make()
                ->title($title)
                ->status(in_array($status, ['success', 'warning', 'danger', 'info'], true) ? $status : 'info');

            if ($body !== null && $body !== '') {
                $notification->body($body);
            }

            if ($icon !== null) {
                $notification->icon($icon);
            }

            $built = [];

            foreach ($actions as $action) {
                if (! isset($action['label'], $action['url'])) {
                    continue;
                }

                $built[] = Action::make(md5($action['url']))
                    ->label($action['label'])
                    ->url($action['url'])
                    ->markAsRead();
            }

            if ($built !== []) {
                $notification->actions($built);
            }

            $notification->sendToDatabase($customer);

            return true;
        } catch (Throwable $exception) {
            // A notification must never be able to fail the job that raised
            // it — an import that finished has still finished.
            report($exception);

            return false;
        }
    }
}
