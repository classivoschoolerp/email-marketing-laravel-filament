<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\ListOverviewResource\Pages;
use App\Filament\Admin\Resources\ListOverviewResource\RelationManagers\SubscribersRelationManager;
use App\Jobs\Exports\AdminListsExportJob;
use App\Jobs\Lists\PurgeListJob;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Scopes\CustomerScope;
use Closure;
use Filament\Forms;
use Filament\Infolists\Components;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Lists overview across ALL customers (spec §3.6, WP-18): global index with
 * the customer column, read-only detail drill (infolist + subscribers
 * relation), disable/enable via the `visible` flag, delete with typed
 * confirmation routed through the same soft-delete + queued purge path the
 * customer module uses, filters by customer/size/date and a queued CSV
 * export. Gated by EmailListPolicy (`lists.*` admin permissions).
 */
class ListOverviewResource extends Resource
{
    protected static ?string $model = EmailList::class;

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';
    protected static ?string $navigationGroup = 'Customer Activity';

    protected static ?string $slug = 'lists-overview';

    protected static ?int $navigationSort = 71;

    public static function getNavigationGroup(): ?string
    {
        return __('dashboards.admin.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('dashboards.lists_overview.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('dashboards.lists_overview.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('dashboards.lists_overview.model_label_plural');
    }

    public static function getEloquentQuery(): Builder
    {
        // Cross-tenant on purpose (§3.6): the admin panel has no bound
        // tenant, but drop the scope explicitly — defense in depth.
        return parent::getEloquentQuery()
            ->withoutGlobalScope(CustomerScope::class)
            ->with('customer');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount([
                'subscribers as subscribers_count',
                'subscribers as confirmed_count' => fn (Builder $q) => $q->where('status', 'confirmed'),
                'subscribers as unconfirmed_count' => fn (Builder $q) => $q->where('status', 'unconfirmed'),
                'subscribers as unsubscribed_count' => fn (Builder $q) => $q->where('status', 'unsubscribed'),
                'subscribers as bounced_count' => fn (Builder $q) => $q->where('status', 'bounced'),
            ]))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('dashboards.lists_overview.columns.name'))
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),
                Tables\Columns\TextColumn::make('customer.email')
                    ->label(__('dashboards.lists_overview.columns.customer'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('subscribers_count')
                    ->label(__('dashboards.lists_overview.columns.subscribers'))
                    ->sortable()
                    ->description(fn (EmailList $record): string => __('dashboards.lists_overview.columns.split', [
                        'confirmed' => number_format((int) $record->confirmed_count),
                        'unconfirmed' => number_format((int) $record->unconfirmed_count),
                        'unsubscribed' => number_format((int) $record->unsubscribed_count),
                        'bounced' => number_format((int) $record->bounced_count),
                    ])),
                Tables\Columns\IconColumn::make('visible')
                    ->label(__('dashboards.lists_overview.columns.visible'))
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('dashboards.lists_overview.columns.created'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('customer_id')
                    ->label(__('dashboards.lists_overview.filters.customer'))
                    ->options(fn (): array => Customer::query()->orderBy('email')->pluck('email', 'id')->all())
                    ->searchable(),
                Tables\Filters\Filter::make('size')
                    ->form([
                        Forms\Components\TextInput::make('min_subscribers')
                            ->label(__('dashboards.lists_overview.filters.min_subscribers'))
                            ->numeric(),
                        Forms\Components\TextInput::make('max_subscribers')
                            ->label(__('dashboards.lists_overview.filters.max_subscribers'))
                            ->numeric(),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['min_subscribers'] ?? null, fn (Builder $q, $value) => $q->has('subscribers', '>=', (int) $value))
                        ->when($data['max_subscribers'] ?? null, fn (Builder $q, $value) => $q->has('subscribers', '<=', (int) $value))),
                Tables\Filters\Filter::make('created')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label(__('dashboards.lists_overview.filters.created_from')),
                        Forms\Components\DatePicker::make('until')->label(__('dashboards.lists_overview.filters.created_until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                static::toggleVisibilityAction(),
                static::deleteListAction(),
            ])
            ->headerActions([
                static::exportAction(),
            ])
            ->paginated([25, 50, 100]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Components\Section::make(__('dashboards.lists_overview.detail.heading'))
                ->columns(3)
                ->schema([
                    Components\TextEntry::make('name')
                        ->label(__('dashboards.lists_overview.columns.name')),
                    Components\TextEntry::make('customer.email')
                        ->label(__('dashboards.lists_overview.columns.customer')),
                    Components\TextEntry::make('created_at')
                        ->label(__('dashboards.lists_overview.columns.created'))
                        ->dateTime(),
                    Components\TextEntry::make('opt_in')
                        ->label(__('dashboards.lists_overview.detail.opt_in'))
                        ->formatStateUsing(fn ($state): string => $state->label()),
                    Components\TextEntry::make('opt_out')
                        ->label(__('dashboards.lists_overview.detail.opt_out'))
                        ->formatStateUsing(fn ($state): string => $state->label()),
                    Components\IconEntry::make('visible')
                        ->label(__('dashboards.lists_overview.columns.visible'))
                        ->boolean(),
                ]),
            Components\Section::make(__('dashboards.lists_overview.detail.stats'))
                ->columns(5)
                ->schema([
                    Components\TextEntry::make('stat_total')
                        ->label(__('dashboards.lists_overview.detail.total'))
                        ->state(fn (EmailList $record): string => number_format($record->subscribers()->count())),
                    Components\TextEntry::make('stat_confirmed')
                        ->label(__('subscribers.status.confirmed'))
                        ->state(fn (EmailList $record): string => number_format($record->subscribers()->where('status', 'confirmed')->count())),
                    Components\TextEntry::make('stat_unconfirmed')
                        ->label(__('subscribers.status.unconfirmed'))
                        ->state(fn (EmailList $record): string => number_format($record->subscribers()->where('status', 'unconfirmed')->count())),
                    Components\TextEntry::make('stat_unsubscribed')
                        ->label(__('subscribers.status.unsubscribed'))
                        ->state(fn (EmailList $record): string => number_format($record->subscribers()->where('status', 'unsubscribed')->count())),
                    Components\TextEntry::make('stat_bounced')
                        ->label(__('subscribers.status.bounced'))
                        ->state(fn (EmailList $record): string => number_format($record->subscribers()->where('status', 'bounced')->count())),
                ]),
        ]);
    }

    /** Disable/enable = the `visible` flag (F-3.6-003). */
    public static function toggleVisibilityAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('toggleVisibility')
            ->label(fn (EmailList $record): string => $record->visible
                ? __('dashboards.lists_overview.actions.disable')
                : __('dashboards.lists_overview.actions.enable'))
            ->icon(fn (EmailList $record): string => $record->visible ? 'heroicon-m-eye-slash' : 'heroicon-m-eye')
            ->color(fn (EmailList $record): string => $record->visible ? 'warning' : 'success')
            ->requiresConfirmation()
            ->visible(fn (EmailList $record): bool => auth('admin')->user()?->can('update', $record) ?? false)
            ->action(function (EmailList $record): void {
                $record->forceFill(['visible' => ! $record->visible])->save();

                Notification::make()->success()
                    ->title($record->visible
                        ? __('dashboards.lists_overview.notifications.enabled')
                        : __('dashboards.lists_overview.notifications.disabled'))
                    ->send();
            });
    }

    /**
     * Delete any list (F-3.6-003): typed confirmation, then the same
     * soft-delete + queued purge path as the customer module (§2.2.14).
     */
    public static function deleteListAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('deleteList')
            ->label(__('dashboards.lists_overview.actions.delete'))
            ->icon('heroicon-m-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('dashboards.lists_overview.delete.heading'))
            ->modalDescription(__('dashboards.lists_overview.delete.description'))
            ->form([
                Forms\Components\TextInput::make('confirm_name')
                    ->label(__('dashboards.lists_overview.delete.type_name'))
                    ->required()
                    ->rule(fn (EmailList $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        if ((string) $value !== $record->name) {
                            $fail(__('dashboards.lists_overview.delete.name_mismatch'));
                        }
                    }),
            ])
            ->visible(fn (EmailList $record): bool => auth('admin')->user()?->can('delete', $record) ?? false)
            ->action(function (EmailList $record): void {
                $record->status = EmailList::STATUS_PENDING_DELETE;
                $record->save();
                $record->delete();

                PurgeListJob::dispatch(
                    (int) $record->customer_id,
                    (int) $record->id,
                    (string) Str::uuid(),
                );

                Notification::make()->success()
                    ->title(__('dashboards.lists_overview.notifications.delete_queued'))
                    ->send();
            });
    }

    /** Queued CSV export of the global index (F-3.6-005). */
    public static function exportAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('export')
            ->label(__('dashboards.lists_overview.actions.export'))
            ->icon('heroicon-m-arrow-down-tray')
            ->action(function (): void {
                AdminListsExportJob::dispatch((int) auth('admin')->id(), Str::random(32));

                Notification::make()->success()
                    ->title(__('dashboards.lists_overview.notifications.export_queued'))
                    ->send();
            });
    }

    public static function getRelations(): array
    {
        return [
            SubscribersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListListOverviews::route('/'),
            'view' => Pages\ViewListOverview::route('/{record}'),
        ];
    }
}
