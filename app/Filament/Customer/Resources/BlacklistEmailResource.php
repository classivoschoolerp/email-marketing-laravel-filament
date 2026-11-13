<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources;

use App\Filament\Customer\Resources\BlacklistEmailResource\Pages;
use App\Models\Customer;
use App\Models\GlobalBlacklistEmail;
use App\Services\Settings\SettingsReader;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Customer blacklist (spec §2.9.10, WP-16): a customer-scoped view over
 * global_blacklist_emails. In per-customer isolation mode
 * (BlacklistSettings, F-3.25.5-004) the customer sees only their own rows;
 * in shared mode every row is visible read-only and only the customer's own
 * rows are removable (GlobalBlacklistEmailPolicy). Gated by the D-42 group
 * key `blacklist.manage`. All mutations happen on the List page (add /
 * bulk paste / queued CSV import / queued export / remove).
 */
class BlacklistEmailResource extends Resource
{
    protected static ?string $model = GlobalBlacklistEmail::class;

    protected static ?string $navigationIcon = 'heroicon-o-no-symbol';

    protected static ?string $slug = 'blacklist';

    protected static ?int $navigationSort = 60;

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
        return __('blacklist.resource.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('blacklist.nav_group');
    }

    /**
     * Tenancy by hand — GlobalBlacklistEmail is deliberately unscoped, so
     * the §2.9.10 view applies the shared/isolated semantics itself.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $user = Filament::auth()->user();

        if (! $user instanceof Customer) {
            return $query->whereRaw('1 = 0');
        }

        if (! SettingsReader::blacklist()->sharedAcrossCustomers()) {
            $query->where('customer_id', $user->id);
        }

        return $query;
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
                    ->formatStateUsing(fn (string $state): string => static::sourceLabel($state)),
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
                        array_map(fn (string $s): string => __('blacklist.sources.'.$s), GlobalBlacklistEmail::SOURCES),
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
                // D-28: bulk actions run no per-record policies — drop rows
                // the acting customer may not delete (foreign rows in shared
                // mode) before deleting.
                Tables\Actions\DeleteBulkAction::make()
                    ->label(__('blacklist.actions.remove_selected'))
                    ->using(function (Collection $records): void {
                        $user = Filament::auth()->user();

                        $records
                            ->filter(fn (GlobalBlacklistEmail $record): bool => $user instanceof Customer
                                && $record->customer_id === $user->id)
                            ->each(fn (GlobalBlacklistEmail $record) => $record->delete());
                    }),
            ]);
    }

    /** Label a source value, falling back to the raw string (free column). */
    public static function sourceLabel(string $source): string
    {
        $key = 'blacklist.sources.'.$source;
        $label = __($key);

        return $label === $key ? $source : $label;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBlacklistEmails::route('/'),
        ];
    }
}
