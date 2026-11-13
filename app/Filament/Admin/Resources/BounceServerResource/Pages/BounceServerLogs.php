<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BounceServerResource\Pages;

use App\Enums\BounceType;
use App\Filament\Admin\Resources\BounceServerResource;
use App\Models\BounceServer;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Per-server bounce processing log (spec §2.9.4 F-2.9.4-009, WP-15): the
 * campaign_bounce_logs rows this server produced, newest first, with the
 * last-run timestamp in the subheading.
 */
class BounceServerLogs extends ManageRelatedRecords
{
    protected static string $resource = BounceServerResource::class;

    protected static string $relationship = 'logs';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    public static function getNavigationLabel(): string
    {
        return __('infra.logs.bounce_title');
    }

    public function getTitle(): string|Htmlable
    {
        /** @var BounceServer $server */
        $server = $this->getRecord();

        return __('infra.logs.bounce_heading', ['name' => $server->name]);
    }

    public function getSubheading(): string|Htmlable|null
    {
        /** @var BounceServer $server */
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
                Tables\Columns\TextColumn::make('bounce_type')
                    ->label(__('infra.logs.columns.type'))
                    ->badge()
                    ->formatStateUsing(fn (BounceType $state): string => $state->label())
                    ->color(fn (BounceType $state): string => $state->color()),
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
            ->filters([
                Tables\Filters\SelectFilter::make('bounce_type')
                    ->label(__('infra.logs.columns.type'))
                    ->options(BounceType::options()),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100]);
    }
}
