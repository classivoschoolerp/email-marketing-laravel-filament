<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\CampaignResource\Widgets;

use App\Enums\BounceType;
use App\Models\CampaignBounceLog;
use App\Services\Reports\CampaignReportService;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Bounces tab (spec §2.3.8 F-2.3.8-007): subscriber, bounce type,
 * SMTP message and date, filterable by type.
 */
class BouncesTable extends ReportTableWidget
{
    protected function tab(): string
    {
        return 'bounces';
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('reports.tabs.bounces'))
            ->query(fn (): Builder => app(CampaignReportService::class)->bouncesQuery($this->getCampaign()))
            ->poll($this->pollInterval())
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('email')
                    ->label(__('reports.columns.subscriber'))
                    ->state(fn (CampaignBounceLog $record): string => (string) ($record->subscriber?->email ?? $record->email ?? '—')),
                Tables\Columns\TextColumn::make('bounce_type')
                    ->label(__('reports.columns.bounce_type'))
                    ->badge()
                    ->formatStateUsing(fn (BounceType $state): string => $state->label())
                    ->color(fn (BounceType $state): string => match ($state) {
                        BounceType::Hard => 'danger',
                        BounceType::Soft => 'warning',
                        BounceType::Internal => 'gray',
                    }),
                Tables\Columns\TextColumn::make('message')
                    ->label(__('reports.columns.message'))
                    ->limit(60)
                    ->tooltip(fn (CampaignBounceLog $record): ?string => $record->message)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('reports.columns.date'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('bounce_type')
                    ->label(__('reports.columns.bounce_type'))
                    ->options(BounceType::options()),
            ])
            ->headerActions([$this->exportAction()])
            ->paginated([10, 25, 50]);
    }
}
