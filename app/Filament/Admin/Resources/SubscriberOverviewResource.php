<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\SubscriberStatus;
use App\Filament\Admin\Resources\SubscriberOverviewResource\Pages;
use App\Models\Customer;
use App\Models\Scopes\CustomerScope;
use App\Models\Subscriber;
use App\Services\Subscribers\GlobalSubscriberSearch;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * §3.8 subscribers overview across ALL customers (WP-39).
 *
 * F-3.8-001 global search by email, F-3.8-002 which lists an address is on
 * system-wide, F-3.8-003 a global unsubscribe / blacklist action.
 *
 * Cross-tenant on purpose — the admin panel has no bound tenant — but the
 * D-05 scope is dropped explicitly rather than relied upon to be absent
 * (defense in depth, the WP-18 overview convention). Gated by
 * SubscriberPolicy, which requires the admin `subscribers.view` permission
 * (and `subscribers.update` for the destructive actions).
 */
class SubscriberOverviewResource extends Resource
{
    protected static ?string $model = Subscriber::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationGroup = 'Customer Activity';

    protected static ?string $slug = 'subscribers-overview';

    protected static ?int $navigationSort = 72;

    public static function getNavigationGroup(): ?string
    {
        return __('dashboards.admin.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('dashboards.subscribers_overview.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('dashboards.subscribers_overview.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('dashboards.subscribers_overview.model_label_plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScope(CustomerScope::class)
            ->with(['customer', 'list']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('email')
                    ->label(__('subscribers.fields.email'))
                    ->searchable()
                    ->copyable()
                    // F-3.8-002 at a glance: how many lists across the whole
                    // install carry this address.
                    ->description(fn (Subscriber $record): string => __('dashboards.subscribers_overview.on_lists', [
                        'count' => app(GlobalSubscriberSearch::class)->listCount((string) $record->email),
                    ])),
                Tables\Columns\TextColumn::make('customer.email')
                    ->label(__('dashboards.subscribers_overview.customer'))
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('list.name')
                    ->label(__('dashboards.subscribers_overview.list'))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('subscribers.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (SubscriberStatus $state): string => $state->label())
                    ->color(fn (SubscriberStatus $state): string => $state->color()),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('subscribers.fields.created_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('customer_id')
                    ->label(__('dashboards.subscribers_overview.customer'))
                    ->options(fn (): array => Customer::query()
                        ->withoutGlobalScopes()
                        ->orderBy('email')
                        ->limit(500)
                        ->pluck('email', 'id')
                        ->all())
                    ->searchable(),
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('subscribers.fields.status'))
                    ->options(fn (): array => collect(SubscriberStatus::cases())
                        ->mapWithKeys(fn (SubscriberStatus $s): array => [$s->value => $s->label()])
                        ->all()),
            ])
            ->actions([
                // F-3.8-002 — every list this address is on, system-wide.
                Tables\Actions\Action::make('memberships')
                    ->label(__('dashboards.subscribers_overview.memberships'))
                    ->icon('heroicon-m-queue-list')
                    ->modalWidth('3xl')
                    ->modalSubmitAction(false)
                    ->modalContent(fn (Subscriber $record): View => view(
                        'filament.admin.pages.partials.subscriber-memberships',
                        [
                            'email' => (string) $record->email,
                            'rows' => app(GlobalSubscriberSearch::class)->memberships((string) $record->email),
                        ],
                    )),

                // F-3.8-003 — global unsubscribe + blacklist.
                Tables\Actions\Action::make('globalUnsubscribe')
                    ->label(__('dashboards.subscribers_overview.global_unsubscribe'))
                    ->icon('heroicon-m-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('dashboards.subscribers_overview.global_unsubscribe_confirm'))
                    ->form([
                        Forms\Components\Toggle::make('blacklist')
                            ->label(__('dashboards.subscribers_overview.also_blacklist'))
                            ->helperText(__('dashboards.subscribers_overview.also_blacklist_help'))
                            ->default(true),
                        Forms\Components\TextInput::make('reason')
                            ->label(__('dashboards.subscribers_overview.reason'))
                            ->maxLength(191),
                    ])
                    ->action(function (Subscriber $record, array $data): void {
                        $result = app(GlobalSubscriberSearch::class)->globalUnsubscribe(
                            (string) $record->email,
                            blacklist: (bool) ($data['blacklist'] ?? false),
                            reason: $data['reason'] ?? null,
                        );

                        Notification::make()
                            ->success()
                            ->title(__('dashboards.subscribers_overview.global_unsubscribe_done', [
                                'count' => $result['unsubscribed'],
                            ]))
                            ->send();
                    }),
            ])
            ->bulkActions([
                // D-28: bulk actions run no per-record policies — the gate is
                // re-checked here for the acting admin, once, before acting.
                Tables\Actions\BulkAction::make('bulkGlobalUnsubscribe')
                    ->label(__('dashboards.subscribers_overview.global_unsubscribe'))
                    ->icon('heroicon-m-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records): void {
                        $admin = auth('admin')->user();
                        $search = app(GlobalSubscriberSearch::class);
                        $total = 0;

                        foreach ($records->unique('email') as $record) {
                            if ($admin?->can('update', $record) !== true) {
                                continue;
                            }

                            $total += $search->globalUnsubscribe((string) $record->email, blacklist: true)['unsubscribed'];
                        }

                        Notification::make()
                            ->success()
                            ->title(__('dashboards.subscribers_overview.global_unsubscribe_done', ['count' => $total]))
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriberOverview::route('/'),
        ];
    }
}
