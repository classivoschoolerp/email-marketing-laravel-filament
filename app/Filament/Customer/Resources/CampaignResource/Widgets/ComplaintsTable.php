<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\CampaignResource\Widgets;

use App\Models\CampaignComplaintLog;
use App\Services\Reports\CampaignReportService;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Complaints tab (spec §2.3.8 F-2.3.8-009): FBL/abuse reports attributed to
 * this campaign by the WP-15 FBL processor.
 */
class ComplaintsTable extends ReportTableWidget
{
    protected function tab(): string
    {
        return 'complaints';
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('reports.tabs.complaints'))
            ->query(fn (): Builder => app(CampaignReportService::class)->complaintsQuery($this->getCampaign()))
            ->poll($this->pollInterval())
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('email')
                    ->label(__('reports.columns.subscriber'))
                    ->state(fn (CampaignComplaintLog $record): string => (string) ($record->subscriber?->email ?? $record->email ?? '—')),
                Tables\Columns\TextColumn::make('feedback_type')
                    ->label(__('reports.columns.feedback_type'))
                    ->badge()
                    ->color('warning')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('message')
                    ->label(__('reports.columns.message'))
                    ->limit(60)
                    ->tooltip(fn (CampaignComplaintLog $record): ?string => $record->message)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('reports.columns.date'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([$this->exportAction()])
            ->paginated([10, 25, 50]);
    }
}
