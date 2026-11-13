<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\EmailListResource\Widgets;

use App\Enums\SubscriberStatus;
use App\Models\EmailList;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Status counter cards on the list overview (spec F-2.2.3-001): confirmed,
 * unconfirmed, unsubscribed, blacklisted, moved, bounced with hard/soft split.
 */
class ListStatusOverview extends StatsOverviewWidget
{
    public ?EmailList $record = null;

    protected static ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 3;
    }

    /** @return array<int, Stat> */
    protected function getStats(): array
    {
        if ($this->record === null) {
            return [];
        }

        $counts = $this->record->statusCounts();
        $bounces = $this->record->bounceTypeCounts();

        $stats = [];

        foreach (SubscriberStatus::cases() as $status) {
            $stat = Stat::make($status->label(), number_format($counts[$status->value]));

            if ($status === SubscriberStatus::Bounced) {
                $stat->description(__('lists.overview.bounce_split', [
                    'hard' => number_format($bounces['hard']),
                    'soft' => number_format($bounces['soft']),
                ]));
            }

            $stats[] = $stat;
        }

        return $stats;
    }
}
