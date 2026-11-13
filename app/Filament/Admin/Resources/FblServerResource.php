<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\InfraServerStatus;
use App\Enums\MailboxProtocol;
use App\Filament\Admin\Resources\Concerns\BuildsMailboxForm;
use App\Filament\Admin\Resources\FblServerResource\Pages;
use App\Jobs\Infra\ProcessFblServerJob;
use App\Models\FblServer;
use App\Models\Scopes\CustomerScope;
use App\Services\Mailboxes\MailboxConnectionTester;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Admin FBL servers (spec §2.9.5 + §3.13.3 global config, WP-15): the same
 * mailbox config as bounce servers (F-2.9.5-001), validate-connection,
 * queued "process now" and a per-server complaint log page. The complaint
 * action itself is fixed by F-2.9.5-003 (unsubscribe + blacklist). Guarded
 * by FblServerPolicy (servers.manage).
 */
class FblServerResource extends Resource
{
    use BuildsMailboxForm;

    protected static ?string $model = FblServer::class;

    protected static ?string $navigationIcon = 'heroicon-o-flag';
    protected static ?string $navigationGroup = 'Deliverability';

    protected static ?string $slug = 'fbl-servers';

    protected static ?int $navigationSort = 73;

    public static function getModelLabel(): string
    {
        return __('infra.fbl.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('infra.fbl.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('infra.fbl.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('blacklist.admin_nav_group');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScope(CustomerScope::class);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            static::mailboxConnectionSection(),
            static::mailboxProcessingSection(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('infra.fields.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('customer.email')
                    ->label(__('infra.fields.customer'))
                    ->placeholder(__('infra.labels.system')),
                Tables\Columns\TextColumn::make('hostname')
                    ->label(__('infra.fields.hostname')),
                Tables\Columns\TextColumn::make('protocol')
                    ->label(__('infra.fields.protocol'))
                    ->badge()
                    ->formatStateUsing(fn (MailboxProtocol $state): string => $state->label()),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('infra.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (InfraServerStatus $state): string => $state->label())
                    ->color(fn (InfraServerStatus $state): string => $state->color()),
                Tables\Columns\TextColumn::make('last_run_at')
                    ->label(__('infra.fields.last_run'))
                    ->since()
                    ->placeholder(__('sending.labels.never')),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('infra.fields.status'))
                    ->options(InfraServerStatus::options()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                static::testConnectionAction(),
                static::processNowAction(),
                Tables\Actions\Action::make('logs')
                    ->label(__('infra.actions.logs'))
                    ->icon('heroicon-o-document-text')
                    ->url(fn (FblServer $record): string => static::getUrl('logs', ['record' => $record])),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function testConnectionAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('test_connection')
            ->label(__('infra.actions.test_connection'))
            ->icon('heroicon-o-check-badge')
            ->action(function (FblServer $record): void {
                $result = app(MailboxConnectionTester::class)->test($record->connection());

                if ($result['ok']) {
                    Notification::make()->title(__('infra.notifications.connection_ok'))->success()->send();
                } else {
                    Notification::make()
                        ->title(__('infra.notifications.connection_failed'))
                        ->body((string) $result['error'])
                        ->danger()
                        ->send();
                }
            });
    }

    public static function processNowAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('process_now')
            ->label(__('infra.actions.process_now'))
            ->icon('heroicon-o-play')
            ->action(function (FblServer $record): void {
                ProcessFblServerJob::dispatch($record->id);

                Notification::make()->title(__('infra.notifications.processing_queued'))->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFblServers::route('/'),
            'create' => Pages\CreateFblServer::route('/create'),
            'edit' => Pages\EditFblServer::route('/{record}/edit'),
            'logs' => Pages\FblServerLogs::route('/{record}/logs'),
        ];
    }
}
