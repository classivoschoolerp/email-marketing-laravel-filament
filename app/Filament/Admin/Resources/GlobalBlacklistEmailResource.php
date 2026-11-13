<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\GlobalBlacklistEmailResource\Pages;
use App\Filament\Customer\Resources\BlacklistEmailResource;
use App\Models\GlobalBlacklistEmail;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Global email blacklist, admin side (spec §3.17, WP-16): every row —
 * exact addresses and domain-level entries — with the owning customer when
 * any (F-3.17-001). Add / bulk paste / queued CSV import / queued export /
 * delete false positives, search & filter; the rule tester surfaces the
 * BlacklistSettings regex rules (F-3.17-005) next to the row kinds. Gated
 * by the `blacklist.manage` admin permission (GlobalBlacklistEmailPolicy).
 */
class GlobalBlacklistEmailResource extends Resource
{
    protected static ?string $model = GlobalBlacklistEmail::class;

    protected static ?string $navigationIcon = 'heroicon-o-no-symbol';
    protected static ?string $navigationGroup = 'Deliverability';

    protected static ?string $slug = 'blacklist';

    protected static ?int $navigationSort = 80;

    public static function getModelLabel(): string
    {
        return __('blacklist.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('blacklist.resource.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('blacklist.resource.admin_navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('blacklist.admin_nav_group');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('customer');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('email')
                    ->label(__('blacklist.index.email'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('is_domain')
                    ->label(__('blacklist.index.type'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? __('blacklist.type.domain') : __('blacklist.type.email'))
                    ->color(fn (bool $state): string => $state ? 'warning' : 'gray'),
                Tables\Columns\TextColumn::make('reason')
                    ->label(__('blacklist.index.reason'))
                    ->searchable()
                    ->limit(50)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('source')
                    ->label(__('blacklist.index.source'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => BlacklistEmailResource::sourceLabel($state)),
                Tables\Columns\TextColumn::make('customer.email')
                    ->label(__('blacklist.index.customer'))
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('blacklist.index.date'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('source')
                    ->label(__('blacklist.index.source'))
                    ->options(array_combine(
                        GlobalBlacklistEmail::SOURCES,
                        array_map(fn (string $s): string => BlacklistEmailResource::sourceLabel($s), GlobalBlacklistEmail::SOURCES),
                    )),
                Tables\Filters\TernaryFilter::make('is_domain')
                    ->label(__('blacklist.index.type')),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make()
                    ->label(__('blacklist.actions.remove'))
                    ->successNotificationTitle(__('blacklist.notifications.removed')),
            ])
            ->bulkActions([
                // Admins holding blacklist.manage may delete any visible row
                // (false positives, F-3.17-003) — no per-record narrowing
                // needed (D-28 satisfied by the resource-level policy gate).
                Tables\Actions\DeleteBulkAction::make()
                    ->label(__('blacklist.actions.remove_selected')),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGlobalBlacklistEmails::route('/'),
        ];
    }
}
