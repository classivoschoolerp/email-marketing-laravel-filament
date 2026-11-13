<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\AuditLogResource\Pages;
use App\Filament\Admin\Resources\Concerns\ExportsAdminLogs;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Scopes\CustomerScope;
use App\Observers\ActivityLogObserver;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

/**
 * §3.27 F-3.27-011 — "who changed what, before/after diff" (WP-40).
 *
 * Reads the WP-39 `activity_logs` table across every tenant. The diff itself
 * comes from the `old_values` / `new_values` columns this work package added
 * and {@see ActivityLogObserver} now fills; rows written before that carry
 * NULL in both and the modal says so rather than rendering an empty diff.
 *
 * Read-only on both guards. An audit trail an operator can edit is not an
 * audit trail, so there is no create, update, delete or bulk action here.
 */
class AuditLogResource extends Resource
{
    use ExportsAdminLogs;

    protected static ?string $model = ActivityLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Users & Access';

    protected static ?string $slug = 'logs/audit';

    protected static ?int $navigationSort = 121;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.access');
    }

    public static function getNavigationLabel(): string
    {
        return __('reports.admin.audit.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('reports.admin.audit.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('reports.admin.audit.plural_label');
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
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('reports.columns.date'))
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('customer.email')
                    ->label(__('reports.overviews.columns.customer'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('actor_name')
                    ->label(__('reports.admin.audit.actor'))
                    ->formatStateUsing(fn (ActivityLog $record): string => $record->actorLabel())
                    ->searchable(),

                Tables\Columns\TextColumn::make('action')
                    ->label(__('reports.admin.audit.action'))
                    ->badge()
                    ->formatStateUsing(fn (ActivityLog $record): string => $record->actionLabel())
                    ->searchable(),

                Tables\Columns\TextColumn::make('subject_label')
                    ->label(__('reports.admin.audit.subject'))
                    ->placeholder('—')
                    ->limit(40)
                    ->searchable(),

                Tables\Columns\IconColumn::make('has_diff')
                    ->label(__('reports.admin.audit.diff'))
                    ->boolean()
                    ->getStateUsing(fn (ActivityLog $record): bool => $record->hasDiff()),

                Tables\Columns\TextColumn::make('ip')
                    ->label(__('reports.columns.ip'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('customer_id')
                    ->label(__('reports.overviews.filters.customer'))
                    ->options(fn (): array => Customer::query()->orderBy('email')->pluck('email', 'id')->all())
                    ->searchable(),

                Tables\Filters\SelectFilter::make('action')
                    ->label(__('reports.admin.audit.action'))
                    ->options(fn (): array => static::actionOptions())
                    ->searchable(),

                Tables\Filters\TernaryFilter::make('has_diff')
                    ->label(__('reports.admin.audit.diff'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('new_values'),
                        false: fn (Builder $query): Builder => $query->whereNull('new_values'),
                        blank: fn (Builder $query): Builder => $query,
                    ),

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
                Tables\Actions\Action::make('diff')
                    ->label(__('reports.admin.audit.view'))
                    ->icon('heroicon-m-eye')
                    ->modalSubmitAction(false)
                    ->visible(fn (ActivityLog $record): bool => auth('admin')->user()?->can('view', $record) ?? false)
                    ->modalContent(fn (ActivityLog $record): View => view('filament.admin.logs.audit-diff', [
                        'log' => $record,
                    ])),
            ])
            ->headerActions([static::logExportAction('audit')])
            ->paginated([25, 50, 100]);
    }

    /**
     * Action keys the filter offers. Derived from the observer's subject map
     * so the list cannot drift from what is actually recorded.
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

        ksort($options);

        return $options;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
        ];
    }
}
