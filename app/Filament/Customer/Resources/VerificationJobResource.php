<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources;

use App\Enums\VerificationStatus;
use App\Filament\Customer\Resources\VerificationJobResource\Pages;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\VerificationJob;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Email verification runs (spec §2.9.12, WP-16): queued bulk verification
 * with live progress + credit consumption (F-2.9.12-008), per-list history
 * via the list filter (F-2.9.12-009), result CSV download, single-address
 * real-time check and per-customer provider credentials (D-12). Targets:
 * whole list / segment here, selected subscribers via the Subscribers bulk
 * action, single address via the header action (F-2.9.12-004).
 */
class VerificationJobResource extends Resource
{
    protected static ?string $model = VerificationJob::class;

    protected static ?string $navigationIcon = 'heroicon-o-check-badge';

    protected static ?string $slug = 'verifications';

    protected static ?int $navigationSort = 62;

    public static function getModelLabel(): string
    {
        return __('verification.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('verification.resource.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('verification.resource.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('blacklist.nav_group');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('list');

        $user = Filament::auth()->user();

        if ($user instanceof Customer) {
            $query->where('verification_jobs.customer_id', $user->id);
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('5s')
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('target')
                    ->label(__('verification.index.target'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('verification.target.'.$state)),
                Tables\Columns\TextColumn::make('list.name')
                    ->label(__('verification.index.list'))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('single_email')
                    ->label(__('verification.index.single_email'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('provider')
                    ->label(__('verification.index.provider')),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('verification.index.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('verification.job_status.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        VerificationJob::STATUS_COMPLETED => 'success',
                        VerificationJob::STATUS_FAILED => 'danger',
                        VerificationJob::STATUS_RUNNING => 'info',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('progress')
                    ->label(__('verification.index.progress'))
                    ->state(fn (VerificationJob $record): string => $record->progressPercent() === null
                        ? '—'
                        : $record->progressPercent().'% ('.$record->processed.'/'.$record->total.')'),
                Tables\Columns\TextColumn::make('deliverable_count')
                    ->label(__('verification.index.deliverable'))
                    ->color(VerificationStatus::Deliverable->color())
                    ->numeric(),
                Tables\Columns\TextColumn::make('undeliverable_count')
                    ->label(__('verification.index.undeliverable'))
                    ->color(VerificationStatus::Undeliverable->color())
                    ->numeric(),
                Tables\Columns\TextColumn::make('risky_count')
                    ->label(__('verification.index.risky'))
                    ->color(VerificationStatus::Risky->color())
                    ->numeric(),
                Tables\Columns\TextColumn::make('unknown_count')
                    ->label(__('verification.index.unknown'))
                    ->numeric(),
                Tables\Columns\TextColumn::make('credits_used')
                    ->label(__('verification.index.credits_used'))
                    ->numeric(),
                Tables\Columns\TextColumn::make('finished_at')
                    ->label(__('verification.index.finished_at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                // Verification history per list (F-2.9.12-009).
                Tables\Filters\SelectFilter::make('list_id')
                    ->label(__('verification.index.list'))
                    ->options(fn (): array => EmailList::query()->pluck('name', 'id')->all()),
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('verification.index.status'))
                    ->options([
                        VerificationJob::STATUS_PENDING => __('verification.job_status.pending'),
                        VerificationJob::STATUS_RUNNING => __('verification.job_status.running'),
                        VerificationJob::STATUS_COMPLETED => __('verification.job_status.completed'),
                        VerificationJob::STATUS_FAILED => __('verification.job_status.failed'),
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('downloadResults')
                    ->label(__('verification.actions.download_results'))
                    ->icon('heroicon-o-document-arrow-down')
                    ->url(fn (VerificationJob $record): string => route('verification.results.download', ['uid' => $record->uid]))
                    ->visible(fn (VerificationJob $record): bool => $record->result_path !== null),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVerificationJobs::route('/'),
        ];
    }
}
