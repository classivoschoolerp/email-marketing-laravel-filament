<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeliveryServerResource\Pages;

use App\Filament\Admin\Resources\DeliveryServerResource;
use App\Filament\Customer\Resources\DeliveryServerResource as CustomerDeliveryServerResource;
use App\Models\DeliveryServer;
use App\Sending\ServerUsage;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-server usage logs (spec §3.13.1 F-3.13.1-006 "sends, bounces, errors
 * over time" + §2.9.1 F-2.9.1-003 quota bars & reset countdowns, WP-15).
 *
 * Table: the delivery_server_usage daily rollups (sent / failed-errors).
 * Header: live quota bars for all three windows, the hourly reset countdown
 * (HourlyWindowLimiter) and — Schema-guarded, because campaign_delivery_logs
 * belongs to the parallel WP-13 package — total delivery-log rows and
 * bounced/failed counts for this server once that table exists.
 */
class DeliveryServerUsageLogs extends ManageRelatedRecords
{
    protected static string $resource = DeliveryServerResource::class;

    protected static string $relationship = 'usage';

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    public static function getNavigationLabel(): string
    {
        return __('infra.usage.title');
    }

    public function getTitle(): string|Htmlable
    {
        /** @var DeliveryServer $server */
        $server = $this->getRecord();

        return __('infra.usage.heading', ['name' => $server->name]);
    }

    public function getSubheading(): string|Htmlable|null
    {
        /** @var DeliveryServer $server */
        $server = $this->getRecord();
        $usage = app(ServerUsage::class);

        $lines = [
            __('infra.usage.hourly_bar', [
                'bar' => CustomerDeliveryServerResource::usageBar($usage->usedThisHour($server), $server->hourly_quota),
                'seconds' => $usage->secondsUntilHourlyReset(),
            ]),
            __('infra.usage.daily_bar', [
                'bar' => CustomerDeliveryServerResource::usageBar($usage->usedToday($server), $server->daily_quota),
            ]),
            __('infra.usage.monthly_bar', [
                'bar' => CustomerDeliveryServerResource::usageBar($usage->usedThisMonth($server), $server->monthly_quota),
            ]),
        ];

        $deliveryStats = $this->deliveryLogStats($server);

        if ($deliveryStats !== null) {
            $lines[] = $deliveryStats;
        }

        return implode(' | ', $lines);
    }

    /**
     * Aggregates from WP-13's campaign_delivery_logs — guarded so this page
     * works before that parallel package lands.
     */
    private function deliveryLogStats(DeliveryServer $server): ?string
    {
        if (! Schema::hasTable('campaign_delivery_logs')
            || ! Schema::hasColumn('campaign_delivery_logs', 'delivery_server_id')) {
            return null;
        }

        $query = DB::table('campaign_delivery_logs')->where('delivery_server_id', $server->id);
        $total = (int) $query->count();

        $failed = 0;

        if (Schema::hasColumn('campaign_delivery_logs', 'status')) {
            $failed = (int) DB::table('campaign_delivery_logs')
                ->where('delivery_server_id', $server->id)
                ->whereIn('status', ['failed', 'bounced', 'error'])
                ->count();
        }

        return __('infra.usage.delivery_log_totals', ['total' => $total, 'failed' => $failed]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('date')
                    ->label(__('infra.usage.columns.date'))
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sent')
                    ->label(__('infra.usage.columns.sent'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('failed')
                    ->label(__('infra.usage.columns.failed'))
                    ->sortable(),
            ])
            ->defaultSort('date', 'desc')
            ->paginated([25, 50, 100]);
    }
}
