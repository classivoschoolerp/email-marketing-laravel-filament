<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\InfraServerStatus;
use App\Enums\MailboxProtocol;
use App\Filament\Admin\Resources\Concerns\BuildsMailboxForm;
use App\Filament\Admin\Resources\EmailBoxMonitorResource\Pages;
use App\Jobs\Infra\ProcessEmailBoxMonitorJob;
use App\Models\EmailBoxMonitor;
use App\Models\Scopes\CustomerScope;
use App\Services\Mailboxes\MailboxConnectionTester;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Email box monitors (spec §2.9.6, WP-33): the WP-15 mailbox config form
 * reused verbatim, plus condition management and the processing log.
 * Guarded by EmailBoxMonitorPolicy (servers.manage).
 */
class EmailBoxMonitorResource extends Resource
{
    use BuildsMailboxForm;

    protected static ?string $model = EmailBoxMonitor::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';
    protected static ?string $navigationGroup = 'Deliverability';

    protected static ?string $slug = 'email-box-monitors';

    protected static ?int $navigationSort = 73;

    public static function getModelLabel(): string
    {
        return __('infra.monitors.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('infra.monitors.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('infra.monitors.navigation_label');
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
                Tables\Columns\TextColumn::make('conditions_count')
                    ->label(__('infra.monitors.fields.conditions_count'))
                    ->counts('conditions'),
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
                Tables\Actions\Action::make('test_connection')
                    ->label(__('infra.actions.test_connection'))
                    ->icon('heroicon-o-check-badge')
                    ->action(function (EmailBoxMonitor $record): void {
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
                    }),
                Tables\Actions\Action::make('process_now')
                    ->label(__('infra.actions.process_now'))
                    ->icon('heroicon-o-play')
                    ->action(function (EmailBoxMonitor $record): void {
                        ProcessEmailBoxMonitorJob::dispatch($record->id);

                        Notification::make()->title(__('infra.notifications.processing_queued'))->success()->send();
                    }),
                Tables\Actions\Action::make('conditions')
                    ->label(__('infra.monitors.sections.conditions'))
                    ->icon('heroicon-o-funnel')
                    ->url(fn (EmailBoxMonitor $record): string => static::getUrl('conditions', ['record' => $record])),
                Tables\Actions\Action::make('logs')
                    ->label(__('infra.actions.logs'))
                    ->icon('heroicon-o-document-text')
                    ->url(fn (EmailBoxMonitor $record): string => static::getUrl('logs', ['record' => $record])),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmailBoxMonitors::route('/'),
            'create' => Pages\CreateEmailBoxMonitor::route('/create'),
            'edit' => Pages\EditEmailBoxMonitor::route('/{record}/edit'),
            'conditions' => Pages\EmailBoxMonitorConditions::route('/{record}/conditions'),
            'logs' => Pages\EmailBoxMonitorLogs::route('/{record}/logs'),
        ];
    }
}
