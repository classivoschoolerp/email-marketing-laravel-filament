<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CampaignOverviewResource\Pages;

use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Filament\Admin\Resources\CampaignOverviewResource;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * Global campaigns index (spec §3.7 F-3.7-001, WP-18): all campaigns with
 * separate Regular / Autoresponder / Recurring tabs plus the
 * flagged-content review tab (F-3.7-007).
 */
class ListCampaignOverviews extends ListRecords
{
    protected static string $resource = CampaignOverviewResource::class;

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make(__('dashboards.campaigns_overview.tabs.all'))];

        foreach (CampaignType::cases() as $type) {
            $tabs[$type->value] = Tab::make(__('dashboards.campaigns_overview.tabs.'.$type->value))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('type', $type->value));
        }

        // F-3.7-004 — the approval queue. Badged so an operator sees the
        // backlog without opening the tab; the badge is the only place in
        // this screen that counts rows, so it stays a single COUNT query.
        $pending = CampaignOverviewResource::pendingApprovalCount();

        $tabs['approval'] = Tab::make(__('dashboards.campaigns_overview.tabs.approval'))
            ->badge($pending > 0 ? (string) $pending : null)
            ->badgeColor('warning')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->where('status', CampaignStatus::PendingApproval->value));

        $tabs['flagged'] = Tab::make(__('dashboards.campaigns_overview.tabs.flagged'))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->whereIn('id', CampaignOverviewResource::flaggedCampaignIds()));

        return $tabs;
    }
}
