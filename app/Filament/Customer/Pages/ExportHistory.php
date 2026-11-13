<?php

declare(strict_types=1);

namespace App\Filament\Customer\Pages;

use App\Models\ExportJob;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\URL;

/**
 * Export history (spec F-2.2.8-005): every export run of the tenant with
 * status, progress and an expiring signed download link. Files vanish after
 * the §3.25.2 retention window (exports:prune) — the rows stay as history.
 */
class ExportHistory extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static string $view = 'filament.customer.pages.export-history';

    protected static ?string $slug = 'exports';

    protected static ?int $navigationSort = 14;

    public static function getNavigationGroup(): ?string
    {
        return __('lists.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('imports.export.history_title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('imports.export.history_title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => ExportJob::query())
            ->poll('10s')
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('imports.export.created'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('list.name')
                    ->label(__('imports.export.list'))
                    ->placeholder(__('imports.export.multiple_lists')),
                Tables\Columns\TextColumn::make('scope')
                    ->label(__('imports.export.scope'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('imports.export.scopes.'.$state)),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('imports.export.status'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        ExportJob::STATUS_COMPLETED => 'success',
                        ExportJob::STATUS_FAILED => 'danger',
                        ExportJob::STATUS_PROCESSING => 'info',
                        ExportJob::STATUS_EXPIRED => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => __('imports.export.statuses.'.$state)),
                Tables\Columns\TextColumn::make('processed_rows')
                    ->label(__('imports.export.rows'))
                    ->formatStateUsing(fn (ExportJob $record): string => number_format($record->processed_rows)
                        .($record->total_rows !== null ? ' / '.number_format($record->total_rows) : '')),
                Tables\Columns\TextColumn::make('expires_at')
                    ->label(__('imports.export.expires'))
                    ->dateTime()
                    ->placeholder(__('imports.export.never'))
                    ->sortable(),
            ])
            ->actions([
                // Expiring signed link (F-2.2.8-005); the download controller
                // re-checks the policy on top of the signature.
                Tables\Actions\Action::make('download')
                    ->label(__('imports.export.download'))
                    ->icon('heroicon-m-arrow-down-tray')
                    ->visible(fn (ExportJob $record): bool => $record->isDownloadable())
                    ->url(fn (ExportJob $record): string => $record->expires_at !== null
                        ? URL::temporarySignedRoute('lists.exports.download', $record->expires_at, ['uid' => $record->uid])
                        : URL::signedRoute('lists.exports.download', ['uid' => $record->uid]))
                    ->openUrlInNewTab(),
            ]);
    }
}
