<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\CampaignDeliveryLogResource\Pages;
use App\Filament\Admin\Resources\Concerns\ExportsAdminLogs;
use App\Models\Campaign;
use App\Models\CampaignDeliveryLog;
use App\Models\Customer;
use App\Models\DeliveryServer;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Global campaign delivery logs, admin side (spec §3.27 F-3.27-001, WP-17):
 * every send attempt across all tenants — campaign, subscriber, server,
 * status, message ID, SMTP response, retries — filterable by campaign /
 * customer / server / status. Strictly read-only (retention belongs to
 * logs:prune); gated by CampaignDeliveryLogPolicy (`logs.view`).
 */
class CampaignDeliveryLogResource extends Resource
{
    use ExportsAdminLogs;

    protected static ?string $model = CampaignDeliveryLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';
    protected static ?string $navigationGroup = 'Deliverability';

    protected static ?string $slug = 'logs/delivery';

    protected static ?int $navigationSort = 110;

    public static function getModelLabel(): string
    {
        return __('reports.admin.delivery.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('reports.admin.delivery.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('reports.admin.delivery.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('blacklist.admin_nav_group');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['campaign', 'subscriber', 'deliveryServer']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('campaign.name')
                    ->label(__('reports.columns.campaign'))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('subscriber.email')
                    ->label(__('reports.columns.subscriber'))
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('deliveryServer.name')
                    ->label(__('reports.columns.server'))
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('reports.columns.status'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        CampaignDeliveryLog::STATUS_SENT => 'success',
                        CampaignDeliveryLog::STATUS_FAILED => 'danger',
                        CampaignDeliveryLog::STATUS_RETRYING => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('message_id')
                    ->label(__('reports.columns.message_id'))
                    ->limit(28)
                    ->tooltip(fn (CampaignDeliveryLog $record): ?string => $record->message_id)
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('response')
                    ->label(__('reports.columns.response'))
                    ->limit(45)
                    ->tooltip(fn (CampaignDeliveryLog $record): ?string => $record->response)
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('retries')
                    ->label(__('reports.columns.retries'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('reports.columns.date'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('reports.columns.status'))
                    ->options([
                        CampaignDeliveryLog::STATUS_SENT => __('reports.delivery_statuses.sent'),
                        CampaignDeliveryLog::STATUS_FAILED => __('reports.delivery_statuses.failed'),
                        CampaignDeliveryLog::STATUS_RETRYING => __('reports.delivery_statuses.retrying'),
                        CampaignDeliveryLog::STATUS_SKIPPED => __('reports.delivery_statuses.skipped'),
                    ]),
                Tables\Filters\SelectFilter::make('campaign_id')
                    ->label(__('reports.columns.campaign'))
                    ->options(fn (): array => Campaign::withoutTenancy()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                Tables\Filters\SelectFilter::make('customer')
                    ->label(__('reports.columns.customer'))
                    ->options(fn (): array => Customer::query()->orderBy('email')->pluck('email', 'id')->all())
                    ->searchable()
                    // Delivery logs carry no customer_id — resolve through the campaign.
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['value'] ?? null, fn (Builder $q, $customerId): Builder => $q
                            ->whereIn('campaign_id', Campaign::withoutTenancy()
                                ->where('customer_id', (int) $customerId)
                                ->select('id')))),
                Tables\Filters\SelectFilter::make('delivery_server_id')
                    ->label(__('reports.columns.server'))
                    ->options(fn (): array => DeliveryServer::withoutTenancy()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->headerActions([static::logExportAction('delivery')])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCampaignDeliveryLogs::route('/'),
        ];
    }
}
