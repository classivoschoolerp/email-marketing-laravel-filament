<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\LoginResult;
use App\Filament\Admin\Resources\Concerns\ExportsAdminLogs;
use App\Filament\Admin\Resources\LoginLogResource\Pages;
use App\Models\Customer;
use App\Models\LoginLog;
use App\Models\User;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Global login logs, admin side (spec §3.27 F-3.27-005/006, WP-17): every
 * attempt on both guards — account, IP, user agent, result — with a failed
 * attempts tab and the live brute-force lockout widget (WP-02
 * LoginThrottle). location stays null until GeoIP (D-10). Read-only; gated
 * by the existing LoginLogPolicy (customers.view or users.view).
 */
class LoginLogResource extends Resource
{
    use ExportsAdminLogs;

    protected static ?string $model = LoginLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-finger-print';

    protected static ?string $navigationGroup = 'Users & Access';

    protected static ?string $slug = 'logs/logins';

    protected static ?int $navigationSort = 113;

    public static function getModelLabel(): string
    {
        return __('reports.admin.logins.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('reports.admin.logins.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('reports.admin.logins.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.access');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('reports.columns.date'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('guard')
                    ->label(__('reports.admin.logins.guard'))
                    ->badge()
                    ->color(fn (string $state): string => $state === 'admin' ? 'info' : 'gray'),
                Tables\Columns\TextColumn::make('email')
                    ->label(__('reports.columns.account'))
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('model_type')
                    ->label(__('reports.admin.logins.account_type'))
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        (new User)->getMorphClass() => __('reports.admin.logins.type_user'),
                        (new Customer)->getMorphClass() => __('reports.admin.logins.type_customer'),
                        default => '—',
                    })
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('ip')
                    ->label(__('reports.columns.ip'))
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('user_agent')
                    ->label(__('reports.columns.user_agent'))
                    ->limit(40)
                    ->tooltip(fn (LoginLog $record): ?string => $record->user_agent)
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('location')
                    ->label(__('reports.columns.location'))
                    ->placeholder(__('reports.geo.unknown_until_geoip'))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('result')
                    ->label(__('reports.columns.result'))
                    ->badge()
                    ->formatStateUsing(fn (LoginResult $state): string => $state->label())
                    ->color(fn (LoginResult $state): string => match ($state) {
                        LoginResult::Success => 'success',
                        LoginResult::Failed, LoginResult::TwoFactorFailed => 'danger',
                        LoginResult::Blocked, LoginResult::Lockout => 'warning',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('guard')
                    ->label(__('reports.admin.logins.guard'))
                    ->options([
                        'admin' => __('reports.admin.logins.type_user'),
                        'customer' => __('reports.admin.logins.type_customer'),
                    ]),
                Tables\Filters\SelectFilter::make('result')
                    ->label(__('reports.columns.result'))
                    ->options(collect(LoginResult::cases())
                        ->mapWithKeys(fn (LoginResult $case): array => [$case->value => $case->label()])
                        ->all()),
            ])
            ->headerActions([static::logExportAction('logins')])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLoginLogs::route('/'),
        ];
    }
}
