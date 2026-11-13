<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\BounceAction;
use App\Enums\InfraServerStatus;
use App\Enums\MailboxProtocol;
use App\Filament\Admin\Resources\BounceServerResource\Pages;
use App\Filament\Admin\Resources\Concerns\BuildsMailboxForm;
use App\Jobs\Infra\ProcessBounceServerJob;
use App\Models\BounceServer;
use App\Models\Scopes\CustomerScope;
use App\Services\Mailboxes\MailboxConnectionTester;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Admin bounce servers (spec §2.9.4 + §3.13.2 global config, WP-15): mailbox
 * config form, validate-connection button (single connection test — the
 * actual processing is queued/cron only), per-server processing log page and
 * a queued "process now" action. Guarded by BounceServerPolicy
 * (servers.manage).
 */
class BounceServerResource extends Resource
{
    use BuildsMailboxForm;

    protected static ?string $model = BounceServer::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-uturn-left';
    protected static ?string $navigationGroup = 'Deliverability';

    protected static ?string $slug = 'bounce-servers';

    protected static ?int $navigationSort = 72;

    public static function getModelLabel(): string
    {
        return __('infra.bounce.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('infra.bounce.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('infra.bounce.navigation_label');
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

            // F-2.9.4-007/008 — bounce actions
            Forms\Components\Section::make(__('infra.sections.bounce_actions'))
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('hard_bounce_action')
                        ->label(__('infra.fields.hard_bounce_action'))
                        ->helperText(__('infra.help.hard_bounce_action'))
                        ->options(BounceAction::options())
                        ->default(BounceAction::MarkBounced->value)
                        ->required(),
                    Forms\Components\TextInput::make('soft_bounce_threshold')
                        ->label(__('infra.fields.soft_bounce_threshold'))
                        ->helperText(__('infra.help.soft_bounce_threshold'))
                        ->numeric()->integer()->minValue(0)
                        ->default(5)
                        ->required(),
                    Forms\Components\Select::make('soft_bounce_action')
                        ->label(__('infra.fields.soft_bounce_action'))
                        ->options(BounceAction::options())
                        ->default(BounceAction::MarkBounced->value)
                        ->required(),
                ]),
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
                Tables\Columns\IconColumn::make('locked')
                    ->label(__('infra.fields.locked'))
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('heroicon-o-lock-open'),
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
                    ->url(fn (BounceServer $record): string => static::getUrl('logs', ['record' => $record])),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    /** F-2.9.4-005 — validate connection through the real mailbox client. */
    public static function testConnectionAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('test_connection')
            ->label(__('infra.actions.test_connection'))
            ->icon('heroicon-o-check-badge')
            ->action(function (BounceServer $record): void {
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

    /** Queued single-server run — processing never happens in the request. */
    public static function processNowAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('process_now')
            ->label(__('infra.actions.process_now'))
            ->icon('heroicon-o-play')
            ->action(function (BounceServer $record): void {
                ProcessBounceServerJob::dispatch($record->id);

                Notification::make()->title(__('infra.notifications.processing_queued'))->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBounceServers::route('/'),
            'create' => Pages\CreateBounceServer::route('/create'),
            'edit' => Pages\EditBounceServer::route('/{record}/edit'),
            'logs' => Pages\BounceServerLogs::route('/{record}/logs'),
        ];
    }
}
