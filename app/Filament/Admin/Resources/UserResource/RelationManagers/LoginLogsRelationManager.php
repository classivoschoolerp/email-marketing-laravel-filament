<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\UserResource\RelationManagers;

use App\Enums\LoginResult;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-user activity log (spec §3.2 F-3.2-006): the user's login_logs rows.
 * Full audit-trail events beyond logins/impersonations are an audit stub —
 * the platform-wide audit log ships with the logs & monitoring work package
 * (§3.27); this table is its per-user anchor.
 */
class LoginLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'loginLogs';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.users.activity.logins_title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('admin.users.activity.logins_title'))
            ->description(__('admin.users.activity.audit_stub'))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('auth-module.security.history_time'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('ip')
                    ->label(__('auth-module.security.history_ip'))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('user_agent')
                    ->label(__('auth-module.security.history_device'))
                    ->limit(48)
                    ->placeholder(__('auth-module.security.unknown_device')),
                Tables\Columns\TextColumn::make('result')
                    ->label(__('auth-module.security.history_result'))
                    ->badge()
                    ->formatStateUsing(fn (LoginResult $state): string => $state->label())
                    ->color(fn (LoginResult $state): string => match ($state) {
                        LoginResult::Success => 'success',
                        LoginResult::Failed, LoginResult::TwoFactorFailed => 'warning',
                        LoginResult::Blocked, LoginResult::Lockout => 'danger',
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
