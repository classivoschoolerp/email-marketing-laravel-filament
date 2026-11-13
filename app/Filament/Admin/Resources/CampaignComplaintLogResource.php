<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\CampaignComplaintLogResource\Pages;
use App\Filament\Admin\Resources\Concerns\ExportsAdminLogs;
use App\Models\Campaign;
use App\Models\CampaignComplaintLog;
use App\Models\Customer;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Global complaint/FBL logs, admin side (spec §3.27 F-3.27-003, WP-17):
 * subscriber, ARF feedback type, original message id and raw excerpt —
 * read-only, filterable, gated by `logs.view`.
 */
class CampaignComplaintLogResource extends Resource
{
    use ExportsAdminLogs;

    protected static ?string $model = CampaignComplaintLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-hand-raised';
    protected static ?string $navigationGroup = 'Deliverability';

    protected static ?string $slug = 'logs/complaints';

    protected static ?int $navigationSort = 112;

    public static function getModelLabel(): string
    {
        return __('reports.admin.complaints.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('reports.admin.complaints.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('reports.admin.complaints.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('blacklist.admin_nav_group');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['campaign', 'subscriber']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('email')
                    ->label(__('reports.columns.subscriber'))
                    ->state(fn (CampaignComplaintLog $record): string => (string) ($record->subscriber?->email ?? $record->email ?? '—'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('campaign.name')
                    ->label(__('reports.columns.campaign'))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('feedback_type')
                    ->label(__('reports.columns.feedback_type'))
                    ->badge()
                    ->color('warning')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('message_id')
                    ->label(__('reports.columns.message_id'))
                    ->limit(28)
                    ->tooltip(fn (CampaignComplaintLog $record): ?string => $record->message_id)
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('message')
                    ->label(__('reports.columns.message'))
                    ->limit(50)
                    ->tooltip(fn (CampaignComplaintLog $record): ?string => $record->message)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('reports.columns.date'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('campaign_id')
                    ->label(__('reports.columns.campaign'))
                    ->options(fn (): array => Campaign::withoutTenancy()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                Tables\Filters\SelectFilter::make('customer_id')
                    ->label(__('reports.columns.customer'))
                    ->options(fn (): array => Customer::query()->orderBy('email')->pluck('email', 'id')->all())
                    ->searchable(),
            ])
            ->headerActions([static::logExportAction('complaints')])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCampaignComplaintLogs::route('/'),
        ];
    }
}
