<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\Announcement;
use App\Models\Customer;

/**
 * F-4.3-003 — push an operator announcement into every customer's
 * notification bell (WP-39).
 *
 * Reuses WP-18's `announcements` table rather than inventing a second
 * message model: the announcement keeps driving the dismissible dashboard
 * banner exactly as before, and broadcasting simply mirrors it into the
 * bell so a customer who never opens the dashboard still sees it.
 *
 * One-shot by construction: `broadcast_at` is stamped here and the admin
 * action hides itself once set, so a mis-click cannot notify the whole
 * install twice.
 *
 * Customers are streamed in chunks — an install with 50 000 tenants must
 * not build 50 000 models in memory.
 */
class AnnouncementBroadcaster
{
    public function __construct(private readonly CustomerNotifier $notifier) {}

    /** @return int customers reached */
    public function broadcast(Announcement $announcement): int
    {
        if ($announcement->wasBroadcast()) {
            return 0;
        }

        $status = match ($announcement->level) {
            Announcement::LEVEL_WARNING => 'warning',
            Announcement::LEVEL_DANGER => 'danger',
            default => 'info',
        };

        $sent = 0;

        Customer::query()
            ->withoutGlobalScopes()
            ->eachById(function (Customer $customer) use ($announcement, $status, &$sent): void {
                if ($this->notifier->announce(
                    $customer,
                    (string) $announcement->title,
                    (string) $announcement->body,
                    status: $status,
                    icon: 'heroicon-o-megaphone',
                )) {
                    $sent++;
                }
            }, 500);

        $announcement->forceFill([
            'broadcast_at' => now(),
            'broadcast_count' => $sent,
        ])->save();

        return $sent;
    }
}
