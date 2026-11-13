<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\CampaignResource\Widgets;

use App\Models\CampaignTrackUrl;
use App\Services\Reports\CampaignReportService;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Clicks tab (spec §2.3.8 F-2.3.8-005): per-URL click counts + unique
 * clickers, with a who-clicked-what drill-down modal per URL.
 */
class ClicksTable extends ReportTableWidget
{
    protected function tab(): string
    {
        return 'clicks';
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('reports.tabs.clicks'))
            ->query(fn (): Builder => app(CampaignReportService::class)->clicksQuery($this->getCampaign()))
            ->poll($this->pollInterval())
            ->defaultSort('clicks_count', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('url')
                    ->label(__('reports.columns.url'))
                    ->limit(70)
                    ->tooltip(fn (CampaignTrackUrl $record): string => (string) $record->url)
                    ->searchable(),
                Tables\Columns\TextColumn::make('clicks_count')
                    ->label(__('reports.columns.clicks'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('unique_clickers')
                    ->label(__('reports.columns.unique_clickers'))
                    ->sortable(),
            ])
            ->actions([
                // Drill into who clicked this URL (F-2.3.8-005).
                Tables\Actions\Action::make('clickers')
                    ->label(__('reports.actions.who_clicked'))
                    ->icon('heroicon-m-users')
                    ->modalHeading(fn (CampaignTrackUrl $record): string => (string) $record->url)
                    ->modalContent(fn (CampaignTrackUrl $record) => view('filament.customer.campaigns.report-clickers', [
                        'clickers' => app(CampaignReportService::class)
                            ->clickersQuery($record)
                            ->orderByDesc('last_click_at')
                            ->limit(100)
                            ->get(),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('campaigns.actions.close')),
            ])
            ->headerActions([$this->exportAction()])
            ->paginated([10, 25, 50]);
    }
}
