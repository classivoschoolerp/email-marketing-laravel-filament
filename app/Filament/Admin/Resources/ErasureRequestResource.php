<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\ErasureRequestResource\Pages\ListErasureRequests;
use App\Models\ErasureRequest;
use App\Services\Privacy\ErasureProcessor;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Admin queue of public right-to-erasure requests (spec §3.25.16
 * F-3.25.16-003, §4.5 F-4.5-001 — WP-22).
 *
 * Rows are created by the public flow and confirmed by the subscriber from
 * the emailed signed link; an administrator reviews and executes. "Erase
 * now" dispatches the existing GdprEraseSubscriberJob (D-31 anonymise in
 * place) for every subscriber row carrying that address, across all lists
 * and tenants. Nothing is creatable or editable here.
 */
class ErasureRequestResource extends Resource
{
    protected static ?string $model = ErasureRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?int $navigationSort = 41;

    public static function getNavigationGroup(): ?string
    {
        return __('privacy.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('privacy.erasure.admin.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('privacy.erasure.admin.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('privacy.erasure.admin.plural_label');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('email')
                    ->label(__('privacy.erasure.admin.email'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('privacy.erasure.admin.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ErasureRequest::statusOptions()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        ErasureRequest::STATUS_COMPLETED => 'success',
                        ErasureRequest::STATUS_CONFIRMED => 'warning',
                        ErasureRequest::STATUS_REJECTED => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('erased_count')
                    ->label(__('privacy.erasure.admin.erased_count'))
                    ->numeric(),
                Tables\Columns\TextColumn::make('ip')
                    ->label(__('privacy.erasure.admin.ip'))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('confirmed_at')
                    ->label(__('privacy.erasure.admin.confirmed_at'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('privacy.erasure.admin.requested_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('privacy.erasure.admin.status'))
                    ->options(ErasureRequest::statusOptions()),
            ])
            ->actions([
                Tables\Actions\Action::make('process')
                    ->label(__('privacy.erasure.admin.process'))
                    ->icon('heroicon-o-check-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ErasureRequest $record): bool => $record->isProcessable())
                    ->action(function (ErasureRequest $record): void {
                        app(ErasureProcessor::class)->process($record);

                        Notification::make()
                            ->title(__('privacy.erasure.admin.processed'))
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('reject')
                    ->label(__('privacy.erasure.admin.reject'))
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (ErasureRequest $record): bool => $record->status !== ErasureRequest::STATUS_COMPLETED
                        && $record->status !== ErasureRequest::STATUS_REJECTED)
                    ->action(function (ErasureRequest $record): void {
                        app(ErasureProcessor::class)->reject($record);

                        Notification::make()
                            ->title(__('privacy.erasure.admin.rejected'))
                            ->success()
                            ->send();
                    }),
            ]);
    }

    /** @return array<string, mixed> */
    public static function getPages(): array
    {
        return [
            'index' => ListErasureRequests::route('/'),
        ];
    }
}
