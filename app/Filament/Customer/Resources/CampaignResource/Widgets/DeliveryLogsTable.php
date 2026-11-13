<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\CampaignResource\Widgets;

use App\Models\CampaignDeliveryLog;
use App\Models\DeliveryServer;
use App\Services\Reports\CampaignReportService;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Delivery logs tab (spec §2.3.8 F-2.3.8-010): per-subscriber send status,
 * delivery server used, message ID, error text and retry count —
 * searchable + filterable by status/server.
 */
class DeliveryLogsTable extends ReportTableWidget
{
    protected function tab(): string
    {
        return 'delivery';
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('reports.tabs.delivery'))
            ->query(fn (): Builder => app(CampaignReportService::class)->deliveryLogsQuery($this->getCampaign()))
            ->poll($this->pollInterval())
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('subscriber.email')
                    ->label(__('reports.columns.subscriber'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('reports.columns.status'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        CampaignDeliveryLog::STATUS_SENT => 'success',
                        CampaignDeliveryLog::STATUS_FAILED => 'danger',
                        CampaignDeliveryLog::STATUS_RETRYING => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('deliveryServer.name')
                    ->label(__('reports.columns.server'))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('message_id')
                    ->label(__('reports.columns.message_id'))
                    ->limit(30)
                    ->tooltip(fn (CampaignDeliveryLog $record): ?string => $record->message_id)
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('response')
                    ->label(__('reports.columns.response'))
                    ->limit(50)
                    ->tooltip(fn (CampaignDeliveryLog $record): ?string => $record->response)
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('retries')
                    ->label(__('reports.columns.retries'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('reports.columns.date'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('reports.columns.status'))
                    ->options([
                        CampaignDeliveryLog::STATUS_SENT => __('reports.delivery_statuses.sent'),
                        CampaignDeliveryLog::STATUS_FAILED => __('reports.delivery_statuses.failed'),
                        CampaignDeliveryLog::STATUS_RETRYING => __('reports.delivery_statuses.retrying'),
                        CampaignDeliveryLog::STATUS_SKIPPED => __('reports.delivery_statuses.skipped'),
                    ]),
                Tables\Filters\SelectFilter::make('delivery_server_id')
                    ->label(__('reports.columns.server'))
                    // Only servers that actually sent this campaign — names of
                    // shared system servers are visible by design (they sent it).
                    ->options(fn (): array => DeliveryServer::withoutTenancy()
                        ->whereIn('id', CampaignDeliveryLog::query()
                            ->where('campaign_id', $this->getCampaign()->id)
                            ->whereNotNull('delivery_server_id')
                            ->distinct()
                            ->pluck('delivery_server_id'))
                        ->pluck('name', 'id')
                        ->all()),
            ])
            ->headerActions([$this->exportAction()])
            ->paginated([10, 25, 50]);
    }
}
