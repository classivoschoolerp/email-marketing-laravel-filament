<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources;

use App\Filament\Customer\Resources\ActivityLogResource\Pages;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\SubUser;
use App\Observers\ActivityLogObserver;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * §2.15.7 account activity log (WP-39): chronological, filterable by user,
 * action type and date (F-2.15.7-002), exportable (F-2.15.7-003).
 *
 * Read-only by policy — the log is append-only and nothing in the UI can
 * edit or delete a row.
 */
class ActivityLogResource extends Resource
{
    protected static ?string $model = ActivityLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $slug = 'activity';

    protected static ?int $navigationSort = 93;

    public static function getModelLabel(): string
    {
        return __('activity.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('activity.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('activity.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('auth-module.profile.nav_group');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('activity.empty.heading'))
            ->emptyStateDescription(__('activity.empty.description'))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('activity.fields.when'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('actor_name')
                    ->label(__('activity.fields.actor'))
                    ->formatStateUsing(fn (?string $state, ActivityLog $record): string => $record->actorLabel())
                    ->searchable(),
                Tables\Columns\TextColumn::make('action')
                    ->label(__('activity.fields.action'))
                    ->badge()
                    ->formatStateUsing(fn (string $state, ActivityLog $record): string => $record->actionLabel())
                    ->searchable(),
                Tables\Columns\TextColumn::make('subject_label')
                    ->label(__('activity.fields.subject'))
                    ->placeholder('—')
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('ip')
                    ->label(__('activity.fields.ip'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // F-2.15.7-002 filter by user (owner or a specific member).
                Tables\Filters\SelectFilter::make('actor')
                    ->label(__('activity.fields.actor'))
                    ->options(fn (): array => self::actorOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if ($value === null || $value === '') {
                            return $query;
                        }

                        if ($value === 'owner') {
                            return $query->where('actor_type', Customer::class);
                        }

                        return $query->byActor(SubUser::class, (int) $value);
                    }),

                // F-2.15.7-002 filter by action type.
                Tables\Filters\SelectFilter::make('action')
                    ->label(__('activity.fields.action'))
                    ->options(fn (): array => self::actionOptions())
                    ->multiple(),

                // F-2.15.7-002 filter by date range.
                Tables\Filters\Filter::make('period')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label(__('activity.fields.from')),
                        Forms\Components\DatePicker::make('until')->label(__('activity.fields.until')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, mixed $date): Builder => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, mixed $date): Builder => $q->whereDate('created_at', '<=', $date));
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivityLogs::route('/'),
        ];
    }

    /**
     * Owner + every team member that has ever acted.
     *
     * @return array<string, string>
     */
    public static function actorOptions(): array
    {
        $options = ['owner' => __('activity.actor_owner')];

        $user = Filament::auth()->user();

        if (! $user instanceof Customer) {
            return $options;
        }

        foreach (SubUser::query()->where('customer_id', $user->id)->orderBy('email')->get() as $member) {
            $options[(string) $member->id] = $member->fullName();
        }

        return $options;
    }

    /**
     * Every action key this install can produce, from the observer's own
     * subject map — so the filter cannot drift from what is recorded.
     *
     * @return array<string, string>
     */
    public static function actionOptions(): array
    {
        $options = [];

        foreach (ActivityLogObserver::SUBJECTS as $prefix) {
            foreach (['created', 'updated', 'deleted', 'restored'] as $verb) {
                $key = $prefix.'.'.$verb;
                $options[$key] = ActivityLog::labelFor($key);
            }
        }

        foreach ([
            'campaign.sent',
            'team_member.invited',
            'team_member.invitation_resent',
            'team_member.accepted',
            'team_member.revoked',
            'team_member.suspended',
            'team_member.reinstated',
            'settings.updated',
            'subscription.cancelled',
            'subscription.resumed',
            'subscription.plan_changed',
            'import.completed',
            'export.completed',
        ] as $key) {
            $options[$key] = ActivityLog::labelFor($key);
        }

        ksort($options);

        return $options;
    }
}
