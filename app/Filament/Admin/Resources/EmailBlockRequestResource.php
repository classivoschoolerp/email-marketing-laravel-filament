<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\BlockRequestStatus;
use App\Filament\Admin\Resources\EmailBlockRequestResource\Pages\ListEmailBlockRequests;
use App\Models\EmailBlockRequest;
use App\Services\EmailBlocks\BlockRequestApprover;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

/**
 * Admin queue of public "never email me again" requests (spec §3.19):
 * approve (→ global blacklist forward-base, source=block-request), reject,
 * bulk approve. Rows are created by the public §1.10 flow; there is nothing
 * to create or edit here. The auto-approve setting (F-3.19-003) is
 * config('emp-public.block_requests.auto_approve') and surfaced on the list
 * page subheading.
 */
class EmailBlockRequestResource extends Resource
{
    protected static ?string $model = EmailBlockRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-no-symbol';

    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): ?string
    {
        return __('blacklist.admin_nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('public.block_requests.admin.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('public.block_requests.admin.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('public.block_requests.admin.plural_label');
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
                    ->label(__('public.block_requests.admin.email'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('public.block_requests.admin.status'))
                    ->badge()
                    ->formatStateUsing(fn (BlockRequestStatus $state): string => $state->label())
                    ->color(fn (BlockRequestStatus $state): string => $state->color()),
                Tables\Columns\TextColumn::make('ip')
                    ->label(__('public.block_requests.admin.ip'))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('confirmed_at')
                    ->label(__('public.block_requests.admin.confirmed_at'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('public.block_requests.admin.requested_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('public.block_requests.admin.status'))
                    ->options(BlockRequestStatus::options()),
            ])
            ->actions([
                // F-3.19-002: approve → global blacklist (source=block-request).
                Tables\Actions\Action::make('approve')
                    ->label(__('public.block_requests.admin.approve'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (EmailBlockRequest $record): bool => $record->status->isApprovable())
                    ->action(function (EmailBlockRequest $record): void {
                        app(BlockRequestApprover::class)->approve($record);

                        Notification::make()
                            ->title(__('public.block_requests.admin.approved'))
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('reject')
                    ->label(__('public.block_requests.admin.reject'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (EmailBlockRequest $record): bool => $record->status !== BlockRequestStatus::Approved
                        && $record->status !== BlockRequestStatus::Rejected)
                    ->action(function (EmailBlockRequest $record): void {
                        app(BlockRequestApprover::class)->reject($record);

                        Notification::make()
                            ->title(__('public.block_requests.admin.rejected'))
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                // F-3.19-002 bulk approve. Already-approved rows are skipped
                // inside the approver (D-28 convention: filter in the action).
                Tables\Actions\BulkAction::make('bulk_approve')
                    ->label(__('public.block_requests.admin.bulk_approve'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records): void {
                        $approver = app(BlockRequestApprover::class);

                        foreach ($records as $record) {
                            $approver->approve($record);
                        }

                        Notification::make()
                            ->title(__('public.block_requests.admin.approved'))
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmailBlockRequests::route('/'),
        ];
    }
}
