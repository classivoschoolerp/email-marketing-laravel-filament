<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\FormResource\Pages;

use App\Enums\FormStatus;
use App\Enums\FormType;
use App\Filament\Customer\Resources\FormResource;
use App\Models\Form;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * §2.7 forms index (F-2.7-001): submission counts and conversion rate per
 * form. Views come from the daily rollup and submissions from the
 * authoritative `form_submissions` table, both as aggregate subqueries so
 * the page costs one query regardless of how many forms a tenant has.
 */
class ListForms extends ListRecords
{
    protected static string $resource = FormResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label(__('forms.actions.create')),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withCount('submissions')
                ->withSum('stats as views_sum', 'views'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('forms.fields.name'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label(__('forms.fields.type'))
                    ->badge()
                    ->formatStateUsing(fn (FormType $state): string => $state->label()),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('forms.fields.status'))
                    ->badge()
                    ->color(fn (FormStatus $state): string => $state->color())
                    ->formatStateUsing(fn (FormStatus $state): string => $state->label()),

                Tables\Columns\TextColumn::make('list.name')
                    ->label(__('forms.fields.list'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('views_sum')
                    ->label(__('forms.analytics.views'))
                    ->numeric()
                    ->default(0)
                    ->sortable(),

                Tables\Columns\TextColumn::make('submissions_count')
                    ->label(__('forms.analytics.submissions'))
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('conversion_rate')
                    ->label(__('forms.analytics.conversion_rate'))
                    ->state(fn (Form $record): string => self::conversionRate($record)),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('forms.fields.created_at'))
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('forms.fields.status'))
                    ->options(FormStatus::options()),
                Tables\Filters\SelectFilter::make('type')
                    ->label(__('forms.fields.type'))
                    ->options(FormType::options()),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('fields')
                    ->label(__('forms.actions.fields'))
                    ->icon('heroicon-m-list-bullet')
                    ->url(fn (Form $record): string => FormResource::getUrl('fields', ['record' => $record])),
                Tables\Actions\Action::make('embed')
                    ->label(__('forms.actions.embed'))
                    ->icon('heroicon-m-code-bracket')
                    ->url(fn (Form $record): string => FormResource::getUrl('embed', ['record' => $record])),
                Tables\Actions\Action::make('analytics')
                    ->label(__('forms.actions.analytics'))
                    ->icon('heroicon-m-chart-bar')
                    ->url(fn (Form $record): string => FormResource::getUrl('analytics', ['record' => $record])),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /** Conversion rate as a display string; no views yet reads as "—". */
    public static function conversionRate(Form $record): string
    {
        $views = (int) ($record->views_sum ?? 0);
        $submissions = (int) ($record->submissions_count ?? 0);

        if ($views <= 0) {
            return '—';
        }

        return number_format(($submissions / $views) * 100, 1).'%';
    }
}
