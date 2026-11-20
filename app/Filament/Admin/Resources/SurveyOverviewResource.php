<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\SurveyStatus;
use App\Filament\Admin\Resources\SurveyOverviewResource\Pages;
use App\Jobs\Exports\AdminOverviewExportJob;
use App\Models\Customer;
use App\Models\Scopes\CustomerScope;
use App\Models\Survey;
use App\Services\Reports\AdminOverviewExporter;
use App\Services\Surveys\SurveyStats;
use Closure;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * All surveys across every customer (spec §3.9 F-3.9-001, WP-40) — the WP-18
 * overview pattern: withoutTenancy + a customer column + filters + a queued
 * cross-tenant CSV export.
 *
 * Stats come from {@see SurveyStats}, the §2.8 owner, so an operator and the
 * customer read identical numbers. Deliberately read-only apart from delete
 * (F-3.9-001 is "stats access, delete"): SurveyPolicy grants the admin guard
 * no update path, so an operator can never rewrite a tenant's questionnaire.
 */
class SurveyOverviewResource extends Resource
{
    protected static ?string $model = Survey::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationGroup = 'Customer Activity';

    protected static ?string $slug = 'surveys-overview';

    protected static ?int $navigationSort = 73;

    public static function getNavigationGroup(): ?string
    {
        return __('reports.overviews.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('reports.overviews.surveys.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('reports.overviews.surveys.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('reports.overviews.surveys.plural_label');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        // Cross-tenant on purpose (§3.9): the admin panel binds no tenant,
        // but drop the scope explicitly — defense in depth.
        return parent::getEloquentQuery()
            ->withoutGlobalScope(CustomerScope::class)
            ->with(['customer', 'list']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount([
                'responders as responders_count',
                'completedResponders as completed_count',
            ]))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('reports.overviews.surveys.name'))
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('customer.email')
                    ->label(__('reports.overviews.columns.customer'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('list.name')
                    ->label(__('reports.overviews.surveys.list'))
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('reports.overviews.surveys.status'))
                    ->badge()
                    ->formatStateUsing(fn (SurveyStatus $state): string => $state->label()),

                Tables\Columns\TextColumn::make('responders_count')
                    ->label(__('reports.overviews.surveys.responders'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('completed_count')
                    ->label(__('reports.overviews.surveys.completed'))
                    ->description(fn (Survey $record): string => static::completionRate($record).'%'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('reports.overviews.columns.created_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('customer_id')
                    ->label(__('reports.overviews.filters.customer'))
                    ->options(fn (): array => Customer::query()->orderBy('email')->pluck('email', 'id')->all())
                    ->searchable(),

                Tables\Filters\SelectFilter::make('status')
                    ->label(__('reports.overviews.filters.status'))
                    ->options(fn (): array => collect(SurveyStatus::cases())
                        ->mapWithKeys(fn (SurveyStatus $case): array => [$case->value => $case->label()])
                        ->all()),

                Tables\Filters\Filter::make('created')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label(__('reports.overviews.filters.created_from')),
                        Forms\Components\DatePicker::make('until')->label(__('reports.overviews.filters.created_until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->actions([
                static::statsAction(),
                static::deleteSurveyAction(),
            ])
            ->headerActions([
                static::exportAction(),
            ])
            ->paginated([25, 50, 100]);
    }

    /** F-3.9-001 "stats access" — SurveyStats verbatim, in a modal. */
    public static function statsAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('stats')
            ->label(__('reports.overviews.actions.stats'))
            ->icon('heroicon-m-chart-bar')
            ->modalHeading(fn (Survey $record): string => __('reports.overviews.surveys.stats_heading', ['name' => $record->name]))
            ->modalSubmitAction(false)
            ->visible(fn (Survey $record): bool => auth('admin')->user()?->can('view', $record) ?? false)
            ->modalContent(fn (Survey $record): View => view('filament.admin.overviews.survey-stats', [
                'survey' => $record,
                'stats' => app(SurveyStats::class)->forSurvey($record),
            ]));
    }

    /** F-3.9-001 "delete" — typed confirmation, same shape as §3.6. */
    public static function deleteSurveyAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('deleteSurvey')
            ->label(__('reports.overviews.actions.delete'))
            ->icon('heroicon-m-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('reports.overviews.surveys.delete_confirm'))
            ->form([
                Forms\Components\TextInput::make('confirm_name')
                    ->label(__('reports.overviews.surveys.name'))
                    ->required()
                    ->rule(fn (Survey $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        if ((string) $value !== (string) $record->name) {
                            $fail(__('validation.in', ['attribute' => $attribute]));
                        }
                    }),
            ])
            ->visible(fn (Survey $record): bool => auth('admin')->user()?->can('delete', $record) ?? false)
            ->action(function (Survey $record): void {
                $record->delete();

                Notification::make()->success()->title(__('reports.overviews.notifications.deleted'))->send();
            });
    }

    public static function exportAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('export')
            ->label(__('reports.overviews.actions.export'))
            ->icon('heroicon-m-arrow-down-tray')
            ->action(function (): void {
                AdminOverviewExportJob::dispatch(
                    AdminOverviewExporter::TYPE_SURVEYS,
                    (int) auth('admin')->id(),
                    Str::random(32),
                );

                Notification::make()->success()->title(__('reports.overviews.notifications.export_queued'))->send();
            });
    }

    public static function completionRate(Survey $survey): float
    {
        $responders = (int) ($survey->responders_count ?? 0);

        return $responders > 0
            ? round(((int) ($survey->completed_count ?? 0)) * 100 / $responders, 1)
            : 0.0;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSurveyOverviews::route('/'),
        ];
    }
}
