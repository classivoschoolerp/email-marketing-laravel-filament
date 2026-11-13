<?php

declare(strict_types=1);

namespace App\Filament\Customer\Widgets;

use App\Models\Announcement;
use App\Models\Customer;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Collection;

/**
 * Announcements from the admin (spec §2.1 F-2.1-010, WP-18): active notices
 * inside their display window, hidden per customer once dismissed. The
 * widget renders nothing when no announcement is visible.
 */
class AnnouncementsWidget extends Widget
{
    protected static string $view = 'filament.customer.widgets.announcements';

    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    /** @return Collection<int, Announcement> */
    public function announcements(): Collection
    {
        return Announcement::query()
            ->visibleTo($this->customer())
            ->orderByDesc('created_at')
            ->get();
    }

    public function dismiss(int $announcementId): void
    {
        $announcement = Announcement::query()->activeNow()->find($announcementId);

        if ($announcement === null || ! $announcement->dismissible) {
            return;
        }

        $announcement->dismissals()->firstOrCreate(['customer_id' => $this->customer()->id]);
    }

    private function customer(): Customer
    {
        /** @var Customer $customer */
        $customer = auth('customer')->user();

        return $customer;
    }
}
