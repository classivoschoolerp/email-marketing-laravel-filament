<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ListOverviewResource\RelationManagers;

use App\Enums\SubscriberStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only subscriber drill on the §3.6 list detail (F-3.6-002, WP-18):
 * searchable, status-filterable, no mutations — subscriber management stays
 * in the customer module.
 */
class SubscribersRelationManager extends RelationManager
{
    protected static string $relationship = 'subscribers';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('dashboards.lists_overview.subscribers.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('email')
                    ->label(__('dashboards.lists_overview.subscribers.email'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('dashboards.lists_overview.subscribers.status'))
                    ->badge()
                    ->formatStateUsing(fn (SubscriberStatus $state): string => $state->label())
                    ->color(fn (SubscriberStatus $state): string => $state->color()),
                Tables\Columns\TextColumn::make('subscribed_at')
                    ->label(__('dashboards.lists_overview.subscribers.subscribed_at'))
                    ->dateTime()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('dashboards.lists_overview.subscribers.created'))
                    ->dateTime(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('dashboards.lists_overview.subscribers.status'))
                    ->options(SubscriberStatus::options()),
            ])
            ->paginated([10, 25, 50]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
