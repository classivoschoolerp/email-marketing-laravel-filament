<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\CampaignResource\Widgets;

use App\Services\Reports\CampaignReportService;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Opens tab (spec §2.3.8 F-2.3.8-004): per-subscriber open list — times
 * opened, first/last open, IP, device — exportable as queued CSV.
 */
class OpensTable extends ReportTableWidget
{
    protected function tab(): string
    {
        return 'opens';
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('reports.tabs.opens'))
            ->query(fn (): Builder => app(CampaignReportService::class)->opensQuery($this->getCampaign()))
            ->poll($this->pollInterval())
            ->defaultSort('last_open_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('subscriber_email')
                    ->label(__('reports.columns.subscriber'))
                    ->state(fn ($record): string => (string) ($record->subscriber_email ?? $record->subscriber_uid)),
                Tables\Columns\TextColumn::make('times_opened')
                    ->label(__('reports.columns.times_opened'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('first_open_at')
                    ->label(__('reports.columns.first_open'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_open_at')
                    ->label(__('reports.columns.last_open'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('ip')
                    ->label(__('reports.columns.ip'))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('device')
                    ->label(__('reports.columns.device'))
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
            ])
            ->headerActions([$this->exportAction()])
            ->paginated([10, 25, 50]);
    }
}
