<?php

declare(strict_types=1);

namespace App\Filament\Customer\Widgets;

use App\Filament\Customer\Resources\CampaignResource;
use App\Filament\Customer\Resources\EmailListResource;
use App\Models\EmailList;
use Filament\Widgets\Widget;

/**
 * Quick actions (spec §2.1 F-2.1-011, WP-18): create campaign, create list,
 * import subscribers. Import is a per-list page — the shortcut targets the
 * most recent list, falling back to the lists index when none exists yet.
 */
class QuickActionsWidget extends Widget
{
    protected static string $view = 'filament.customer.widgets.quick-actions';

    protected static bool $isLazy = false;

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    /** @return list<array{label: string, icon: string, url: string}> */
    public function actions(): array
    {
        $latestList = EmailList::query()->latest()->first();

        return [
            [
                'label' => __('dashboards.customer.quick_actions.create_campaign'),
                'icon' => 'heroicon-m-paper-airplane',
                'url' => CampaignResource::getUrl('create'),
            ],
            [
                'label' => __('dashboards.customer.quick_actions.create_list'),
                'icon' => 'heroicon-m-queue-list',
                'url' => EmailListResource::getUrl('create'),
            ],
            [
                'label' => __('dashboards.customer.quick_actions.import_subscribers'),
                'icon' => 'heroicon-m-arrow-up-tray',
                'url' => $latestList !== null
                    ? EmailListResource::getUrl('import', ['record' => $latestList])
                    : EmailListResource::getUrl('index'),
            ],
        ];
    }
}
