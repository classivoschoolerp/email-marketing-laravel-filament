<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FblServerResource\Pages;

use App\Filament\Admin\Resources\FblServerResource;
use App\Models\FblServer;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Per-server complaint log (spec §2.9.5 F-2.9.5-004, WP-15): the
 * campaign_complaint_logs rows this FBL server produced.
 */
class FblServerLogs extends ManageRelatedRecords
{
    protected static string $resource = FblServerResource::class;

    protected static string $relationship = 'logs';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    public static function getNavigationLabel(): string
    {
        return __('infra.logs.fbl_title');
    }

    public function getTitle(): string|Htmlable
    {
        /** @var FblServer $server */
        $server = $this->getRecord();

        return __('infra.logs.fbl_heading', ['name' => $server->name]);
    }

    public function getSubheading(): string|Htmlable|null
    {
        /** @var FblServer $server */
        $server = $this->getRecord();

        return __('infra.logs.last_run', [
            'time' => $server->last_run_at?->toDayDateTimeString() ?? __('sending.labels.never'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('infra.logs.columns.received'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->label(__('infra.logs.columns.email'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('feedback_type')
                    ->label(__('infra.logs.columns.feedback_type'))
                    ->badge()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('campaign_id')
                    ->label(__('infra.logs.columns.campaign'))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('subscriber_id')
                    ->label(__('infra.logs.columns.subscriber'))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('message')
                    ->label(__('infra.logs.columns.message'))
                    ->limit(80)
                    ->wrap(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100]);
    }
}
