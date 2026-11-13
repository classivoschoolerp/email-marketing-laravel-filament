<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\BounceType;
use App\Filament\Admin\Resources\CampaignBounceLogResource\Pages;
use App\Filament\Admin\Resources\Concerns\ExportsAdminLogs;
use App\Models\Campaign;
use App\Models\CampaignBounceLog;
use App\Models\Customer;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Global campaign bounce logs, admin side (spec §3.27 F-3.27-002, WP-17):
 * subscriber, bounce type, original message id and raw excerpt — read-only,
 * filterable by type / campaign / customer, gated by `logs.view`.
 */
class CampaignBounceLogResource extends Resource
{
    use ExportsAdminLogs;

    protected static ?string $model = CampaignBounceLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-uturn-left';
    protected static ?string $navigationGroup = 'Deliverability';

    protected static ?string $slug = 'logs/bounces';

    protected static ?int $navigationSort = 111;

    public static function getModelLabel(): string
    {
        return __('reports.admin.bounces.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('reports.admin.bounces.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('reports.admin.bounces.navigation_label');
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
                    ->state(fn (CampaignBounceLog $record): string => (string) ($record->subscriber?->email ?? $record->email ?? '—'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('campaign.name')
                    ->label(__('reports.columns.campaign'))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('bounce_type')
                    ->label(__('reports.columns.bounce_type'))
                    ->badge()
                    ->formatStateUsing(fn (BounceType $state): string => $state->label())
                    ->color(fn (BounceType $state): string => match ($state) {
                        BounceType::Hard => 'danger',
                        BounceType::Soft => 'warning',
                        BounceType::Internal => 'gray',
                    }),
                Tables\Columns\TextColumn::make('message_id')
                    ->label(__('reports.columns.message_id'))
                    ->limit(28)
                    ->tooltip(fn (CampaignBounceLog $record): ?string => $record->message_id)
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('message')
                    ->label(__('reports.columns.message'))
                    ->limit(50)
                    ->tooltip(fn (CampaignBounceLog $record): ?string => $record->message)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('reports.columns.date'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('bounce_type')
                    ->label(__('reports.columns.bounce_type'))
                    ->options(BounceType::options()),
                Tables\Filters\SelectFilter::make('campaign_id')
                    ->label(__('reports.columns.campaign'))
                    ->options(fn (): array => Campaign::withoutTenancy()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                Tables\Filters\SelectFilter::make('customer_id')
                    ->label(__('reports.columns.customer'))
                    ->options(fn (): array => Customer::query()->orderBy('email')->pluck('email', 'id')->all())
                    ->searchable(),
            ])
            ->headerActions([static::logExportAction('bounces')])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCampaignBounceLogs::route('/'),
        ];
    }
}
