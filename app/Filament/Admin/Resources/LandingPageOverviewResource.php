<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\LandingPageStatus;
use App\Filament\Admin\Resources\LandingPageOverviewResource\Pages;
use App\Jobs\Exports\AdminOverviewExportJob;
use App\Models\Customer;
use App\Models\LandingPage;
use App\Models\Scopes\CustomerScope;
use App\Services\Landing\LandingPageStats;
use App\Services\Reports\AdminOverviewExporter;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * All landing pages across every customer (spec §3.10 F-3.10-001, WP-40) —
 * the WP-18 overview pattern.
 *
 * "Disable" means UNPUBLISH, not delete and not edit: the page's public URL
 * starts answering 404 immediately (LandingPage::isLive()) while its content,
 * revisions and analytics are left untouched, so the customer can be told
 * what happened and can put it back. Stats come from {@see LandingPageStats},
 * the §2.6 owner.
 */
class LandingPageOverviewResource extends Resource
{
    protected static ?string $model = LandingPage::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationGroup = 'Customer Activity';

    protected static ?string $slug = 'landing-pages-overview';

    protected static ?int $navigationSort = 74;

    public static function getNavigationGroup(): ?string
    {
        return __('reports.overviews.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('reports.overviews.landing_pages.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('reports.overviews.landing_pages.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('reports.overviews.landing_pages.plural_label');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScope(CustomerScope::class)
            ->with('customer');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('reports.overviews.landing_pages.name'))
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('customer.email')
                    ->label(__('reports.overviews.columns.customer'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('slug')
                    ->label(__('reports.overviews.landing_pages.slug'))
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('reports.overviews.landing_pages.status'))
                    ->badge()
                    ->formatStateUsing(fn (LandingPageStatus $state): string => $state->label())
                    ->color(fn (LandingPageStatus $state): string => $state->color()),

                Tables\Columns\TextColumn::make('visits')
                    ->label(__('reports.overviews.landing_pages.visits'))
                    ->state(fn (LandingPage $record): string => number_format(
                        static::stats($record)['visits'],
                    )),

                Tables\Columns\TextColumn::make('conversions')
                    ->label(__('reports.overviews.landing_pages.conversions'))
                    ->state(fn (LandingPage $record): string => number_format(
                        static::stats($record)['conversions'],
                    ))
                    ->description(fn (LandingPage $record): string => static::stats($record)['conversion_rate'].'%'),

                Tables\Columns\TextColumn::make('published_at')
                    ->label(__('reports.overviews.landing_pages.published_at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('customer_id')
                    ->label(__('reports.overviews.filters.customer'))
                    ->options(fn (): array => Customer::query()->orderBy('email')->pluck('email', 'id')->all())
                    ->searchable(),

                Tables\Filters\SelectFilter::make('status')
                    ->label(__('reports.overviews.filters.status'))
                    ->options(fn (): array => LandingPageStatus::options()),

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
                static::disableAction(),
            ])
            ->headerActions([
                static::exportAction(),
            ])
            ->paginated([25, 50, 100]);
    }

    public static function statsAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('stats')
            ->label(__('reports.overviews.actions.stats'))
            ->icon('heroicon-m-chart-bar')
            ->modalHeading(fn (LandingPage $record): string => __('reports.overviews.landing_pages.stats_heading', ['name' => $record->name]))
            ->modalSubmitAction(false)
            ->visible(fn (LandingPage $record): bool => auth('admin')->user()?->can('view', $record) ?? false)
            ->modalContent(fn (LandingPage $record): View => view('filament.admin.overviews.landing-page-stats', [
                'page' => $record,
                'summary' => app(LandingPageStats::class)->summary($record),
                'sources' => app(LandingPageStats::class)->bySource($record),
            ]));
    }

    /**
     * F-3.10-001 "disable" — unpublish (and re-publish). Never touches the
     * page content, so the action is fully reversible.
     */
    public static function disableAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('toggleDisabled')
            ->label(fn (LandingPage $record): string => $record->status === LandingPageStatus::Unpublished
                ? __('reports.overviews.actions.enable')
                : __('reports.overviews.actions.disable'))
            ->icon(fn (LandingPage $record): string => $record->status === LandingPageStatus::Unpublished
                ? 'heroicon-m-eye'
                : 'heroicon-m-eye-slash')
            ->color(fn (LandingPage $record): string => $record->status === LandingPageStatus::Unpublished
                ? 'success'
                : 'warning')
            ->requiresConfirmation()
            ->modalDescription(__('reports.overviews.landing_pages.disable_confirm'))
            ->visible(fn (LandingPage $record): bool => auth('admin')->user()?->can('update', $record) ?? false)
            ->action(function (LandingPage $record): void {
                $disabling = $record->status !== LandingPageStatus::Unpublished;

                $record->forceFill([
                    'status' => $disabling ? LandingPageStatus::Unpublished : LandingPageStatus::Published,
                ])->save();

                Notification::make()->success()
                    ->title($disabling
                        ? __('reports.overviews.notifications.disabled')
                        : __('reports.overviews.notifications.enabled'))
                    ->send();
            });
    }

    public static function exportAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('export')
            ->label(__('reports.overviews.actions.export'))
            ->icon('heroicon-m-arrow-down-tray')
            ->action(function (): void {
                AdminOverviewExportJob::dispatch(
                    AdminOverviewExporter::TYPE_LANDING_PAGES,
                    (int) auth('admin')->id(),
                    Str::random(32),
                );

                Notification::make()->success()->title(__('reports.overviews.notifications.export_queued'))->send();
            });
    }

    /**
     * Memoised per render pass: two columns and the modal all want the same
     * summary, and LandingPageStats runs three counts per call.
     *
     * @return array{visits:int, unique_visitors:int, conversions:int, conversion_rate:float}
     */
    private static function stats(LandingPage $page): array
    {
        static $cache = [];

        return $cache[(int) $page->id] ??= app(LandingPageStats::class)->summary($page);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLandingPageOverviews::route('/'),
        ];
    }
}
