<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\ConsentEventResource\Pages\ListConsentEvents;
use App\Filament\Customer\Resources\ConsentEventResource as CustomerConsentEvents;
use App\Models\ConsentEvent;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Install-wide, read-only consent audit trail (spec §4.5 F-4.5-008, WP-22).
 *
 * Same columns as the customer view (shared from
 * {@see CustomerConsentEvents::columns()}), but tenancy is lifted so an
 * operator can answer a regulator's question across every customer, plus a
 * customer filter. Append-only: no create, edit or delete.
 */
class ConsentEventResource extends Resource
{
    protected static ?string $model = ConsentEvent::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $slug = 'consent-events';

    protected static ?int $navigationSort = 42;

    public static function getNavigationGroup(): ?string
    {
        return __('privacy.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('privacy.consent_events.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('privacy.consent_events.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('privacy.consent_events.navigation_label');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return ConsentEvent::withoutTenancy();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(array_merge(
                CustomerConsentEvents::columns(),
                [
                    Tables\Columns\TextColumn::make('customer.email')
                        ->label(__('privacy.consent_events.subscriber'))
                        ->toggleable(isToggledHiddenByDefault: true),
                ],
            ))
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('source')
                    ->label(__('privacy.consent_events.source'))
                    ->options(CustomerConsentEvents::sourceOptions()),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    /** @return array<string, mixed> */
    public static function getPages(): array
    {
        return [
            'index' => ListConsentEvents::route('/'),
        ];
    }
}
