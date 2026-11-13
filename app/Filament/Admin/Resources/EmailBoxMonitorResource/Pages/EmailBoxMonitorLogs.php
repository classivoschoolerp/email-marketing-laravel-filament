<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\EmailBoxMonitorResource\Pages;

use App\Enums\MonitorAction;
use App\Filament\Admin\Resources\EmailBoxMonitorResource;
use App\Models\EmailBoxMonitor;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Monitor processing log (spec §2.9.6 F-2.9.6-005): every examined message,
 * matched or not, with the action taken and its result.
 */
class EmailBoxMonitorLogs extends ManageRelatedRecords
{
    protected static string $resource = EmailBoxMonitorResource::class;

    protected static string $relationship = 'logs';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    public static function getNavigationLabel(): string
    {
        return __('infra.monitors.logs.title');
    }

    public function getTitle(): string|Htmlable
    {
        /** @var EmailBoxMonitor $monitor */
        $monitor = $this->getRecord();

        return __('infra.monitors.logs.heading', ['name' => $monitor->name]);
    }

    public function getSubheading(): string|Htmlable|null
    {
        /** @var EmailBoxMonitor $monitor */
        $monitor = $this->getRecord();

        return __('infra.logs.last_run', [
            'time' => $monitor->last_run_at?->toDayDateTimeString() ?? __('sending.labels.never'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('infra.monitors.logs.columns.received'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('from_email')
                    ->label(__('infra.monitors.logs.columns.from'))
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('subject')
                    ->label(__('infra.monitors.logs.columns.subject'))
                    ->limit(60)
                    ->placeholder('—'),
                Tables\Columns\IconColumn::make('matched')
                    ->label(__('infra.monitors.logs.columns.matched'))
                    ->boolean(),
                Tables\Columns\TextColumn::make('action')
                    ->label(__('infra.monitors.logs.columns.action'))
                    ->badge()
                    ->formatStateUsing(fn (?MonitorAction $state): string => $state?->label() ?? '—')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('result')
                    ->label(__('infra.monitors.logs.columns.result'))
                    ->limit(80)
                    ->wrap(),
                Tables\Columns\TextColumn::make('subscriber_id')
                    ->label(__('infra.monitors.logs.columns.subscriber'))
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('matched')
                    ->label(__('infra.monitors.logs.columns.matched')),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100]);
    }
}
