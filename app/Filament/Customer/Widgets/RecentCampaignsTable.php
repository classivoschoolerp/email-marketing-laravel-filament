<?php

declare(strict_types=1);

namespace App\Filament\Customer\Widgets;

use App\Enums\CampaignStatus;
use App\Filament\Customer\Resources\CampaignResource;
use App\Models\Campaign;
use App\Models\CampaignStatsDaily;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Recent campaigns with mini stats (spec §2.1 F-2.1-005, WP-18): the five
 * newest campaigns, delivered counter from the WP-13 columns, opens/clicks
 * from the campaign_stats_daily rollup, linking through to the §2.3.8
 * report. Tenant-scoped via CustomerScope.
 */
class RecentCampaignsTable extends TableWidget
{
    protected static ?int $sort = 50;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('dashboards.customer.recent_campaigns.heading'))
            ->query(Campaign::query()->latest()->limit(5))
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('dashboards.customer.recent_campaigns.name'))
                    ->weight('semibold'),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('dashboards.customer.recent_campaigns.status'))
                    ->badge()
                    ->formatStateUsing(fn (CampaignStatus $state): string => $state->label())
                    ->color(fn (CampaignStatus $state): string => $state->color()),
                Tables\Columns\TextColumn::make('delivery_sent')
                    ->label(__('dashboards.customer.recent_campaigns.delivered'))
                    ->formatStateUsing(fn (Campaign $record): string => number_format((int) $record->delivery_sent)),
                Tables\Columns\TextColumn::make('opens')
                    ->label(__('dashboards.customer.recent_campaigns.opens'))
                    ->state(fn (Campaign $record): string => number_format(
                        (int) CampaignStatsDaily::query()->where('campaign_id', $record->id)->sum('opens'),
                    )),
                Tables\Columns\TextColumn::make('clicks')
                    ->label(__('dashboards.customer.recent_campaigns.clicks'))
                    ->state(fn (Campaign $record): string => number_format(
                        (int) CampaignStatsDaily::query()->where('campaign_id', $record->id)->sum('clicks'),
                    )),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('dashboards.customer.recent_campaigns.created'))
                    ->dateTime(),
            ])
            ->actions([
                Tables\Actions\Action::make('report')
                    ->label(__('dashboards.customer.recent_campaigns.report'))
                    ->icon('heroicon-m-chart-bar')
                    ->url(fn (Campaign $record): string => CampaignResource::getUrl('report', ['record' => $record])),
            ]);
    }
}
