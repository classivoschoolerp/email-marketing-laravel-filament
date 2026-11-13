<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources;

use App\Filament\Customer\Resources\ConsentEventResource\Pages\ListConsentEvents;
use App\Models\ConsentEvent;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only consent audit trail for the customer panel (spec §4.5
 * F-4.5-008, WP-22). Tenant-scoped by the ConsentEvent model's global scope
 * — a customer only ever sees consent changes of its own subscribers.
 *
 * Append-only by design: no create, no edit, no delete. Retention is
 * enforced centrally by `logs:prune` using the §3.25.15 audit window.
 */
class ConsentEventResource extends Resource
{
    protected static ?string $model = ConsentEvent::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $slug = 'consent-events';

    protected static ?int $navigationSort = 62;

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

    public static function table(Table $table): Table
    {
        return $table
            ->columns(self::columns())
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('source')
                    ->label(__('privacy.consent_events.source'))
                    ->options(self::sourceOptions()),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    /** Shared column set — the admin resource renders the same trail. */
    public static function columns(): array
    {
        return [
            Tables\Columns\TextColumn::make('created_at')
                ->label(__('privacy.consent_events.created_at'))
                ->dateTime()
                ->sortable(),
            Tables\Columns\TextColumn::make('subscriber.email')
                ->label(__('privacy.consent_events.subscriber'))
                ->searchable()
                ->placeholder('—'),
            Tables\Columns\TextColumn::make('field')
                ->label(__('privacy.consent_events.field'))
                ->searchable(),
            Tables\Columns\TextColumn::make('old_value')
                ->label(__('privacy.consent_events.old_value'))
                ->formatStateUsing(fn (?string $state): string => ConsentEvent::valueLabel($state)),
            Tables\Columns\TextColumn::make('new_value')
                ->label(__('privacy.consent_events.new_value'))
                ->badge()
                ->formatStateUsing(fn (?string $state): string => ConsentEvent::valueLabel($state))
                ->color(fn (?string $state): string => $state === '1' ? 'success' : 'danger'),
            Tables\Columns\TextColumn::make('source')
                ->label(__('privacy.consent_events.source'))
                ->formatStateUsing(fn (string $state): string => self::sourceOptions()[$state] ?? $state),
            Tables\Columns\TextColumn::make('ip')
                ->label(__('privacy.consent_events.ip'))
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('user_agent')
                ->label(__('privacy.consent_events.user_agent'))
                ->limit(48)
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    /** @return array<string, string> */
    public static function sourceOptions(): array
    {
        return [
            ConsentEvent::SOURCE_PUBLIC => __('privacy.consent_events.sources.public'),
            ConsentEvent::SOURCE_CUSTOMER => __('privacy.consent_events.sources.customer'),
            ConsentEvent::SOURCE_ADMIN => __('privacy.consent_events.sources.admin'),
            ConsentEvent::SOURCE_API => __('privacy.consent_events.sources.api'),
            ConsentEvent::SOURCE_SYSTEM => __('privacy.consent_events.sources.system'),
        ];
    }

    /** @return array<string, mixed> */
    public static function getPages(): array
    {
        return [
            'index' => ListConsentEvents::route('/'),
        ];
    }
}
