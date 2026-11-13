<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\CampaignResource\Widgets;

use App\Services\Reports\CampaignReportService;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Unsubscribes tab (spec §2.3.8 F-2.3.8-008): subscriber, date, IP and the
 * stored unsubscribe reason where present (subscribers.unsubscribe_reason).
 */
class UnsubscribesTable extends ReportTableWidget
{
    protected function tab(): string
    {
        return 'unsubscribes';
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('reports.tabs.unsubscribes'))
            ->query(fn (): Builder => app(CampaignReportService::class)->unsubscribesQuery($this->getCampaign()))
            ->poll($this->pollInterval())
            ->defaultSort('campaign_track_unsubscribes.created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('subscriber_email')
                    ->label(__('reports.columns.subscriber'))
                    ->state(fn ($record): string => (string) ($record->subscriber_email ?? $record->subscriber_uid)),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('reports.columns.date'))
                    ->dateTime(),
                Tables\Columns\TextColumn::make('ip')
                    ->label(__('reports.columns.ip'))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('unsubscribe_reason')
                    ->label(__('reports.columns.reason'))
                    ->placeholder('—'),
            ])
            ->headerActions([$this->exportAction()])
            ->paginated([10, 25, 50]);
    }
}
