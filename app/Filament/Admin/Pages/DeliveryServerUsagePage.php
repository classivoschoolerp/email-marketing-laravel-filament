<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Pages\Concerns\RequiresLogsPermission;
use App\Filament\Admin\Widgets\ServerUsageChart;
use App\Models\DeliveryServer;
use App\Models\DeliveryServerUsage;
use Filament\Forms;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Global delivery-server usage logs (spec §3.27 F-3.27-004, WP-17): the
 * WP-14 daily rollups across ALL servers — volume over time chart + table,
 * filterable by server and date range. The per-server drill-down stays on
 * the WP-15 DeliveryServerUsageLogs resource page; this is the fleet view.
 */
class DeliveryServerUsagePage extends Page implements HasTable
{
    use InteractsWithTable;
    use RequiresLogsPermission;

    protected static ?string $navigationIcon = 'heroicon-o-server-stack';
    protected static ?string $navigationGroup = 'Sending Infrastructure';

    protected static string $view = 'filament.admin.pages.delivery-server-usage';

    protected static ?string $slug = 'logs/server-usage';

    protected static ?int $navigationSort = 114;

    public static function getNavigationGroup(): ?string
    {
        return __('infra.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('reports.admin.server_usage.navigation_label');
    }

    public function getTitle(): string|Htmlable
    {
        return __('reports.admin.server_usage.title');
    }

    /** @return array<int, mixed> */
    protected function getHeaderWidgets(): array
    {
        return [
            ServerUsageChart::class,
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => DeliveryServerUsage::query()->with('server'))
            ->defaultSort('date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('date')
                    ->label(__('reports.admin.server_usage.date'))
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('server.name')
                    ->label(__('reports.columns.server'))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('sent')
                    ->label(__('reports.admin.server_usage.sent'))
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('failed')
                    ->label(__('reports.admin.server_usage.failed'))
                    ->numeric()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('delivery_server_id')
                    ->label(__('reports.columns.server'))
                    ->options(fn (): array => DeliveryServer::withoutTenancy()->orderBy('name')->pluck('name', 'id')->all()),
                Tables\Filters\Filter::make('date_between')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label(__('reports.filters.from')),
                        Forms\Components\DatePicker::make('until')->label(__('reports.filters.until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $from) => $q->whereDate('date', '>=', $from))
                        ->when($data['until'] ?? null, fn (Builder $q, string $until) => $q->whereDate('date', '<=', $until))),
            ])
            ->paginated([25, 50, 100]);
    }
}
