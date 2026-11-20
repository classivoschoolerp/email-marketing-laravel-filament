<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\SurveyResource\Pages;

use App\Enums\SurveyResponderStatus;
use App\Enums\SurveyStatus;
use App\Filament\Customer\Resources\SurveyResource;
use App\Models\Survey;
use App\Services\Surveys\SurveyLinkResolver;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * §2.8 surveys index (F-2.8-001): responder counts per survey, plus the
 * campaign merge tag (F-2.8-008) and the public link, copyable from the row.
 */
class ListSurveys extends ListRecords
{
    protected static string $resource = SurveyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label(__('surveys.actions.create')),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withCount([
                    'responders',
                    'responders as completed_count' => fn (Builder $q) => $q
                        ->where('status', SurveyResponderStatus::Complete->value),
                ]))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('surveys.fields.name'))
                    ->description(fn (Survey $record): ?string => $record->display_name)
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('surveys.fields.status'))
                    ->badge()
                    ->color(fn (SurveyStatus $state): string => $state->color())
                    ->formatStateUsing(fn (SurveyStatus $state): string => $state->label()),

                Tables\Columns\TextColumn::make('completed_count')
                    ->label(__('surveys.fields.completed_responders'))
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('responders_count')
                    ->label(__('surveys.fields.all_responders'))
                    ->numeric()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('max_responders')
                    ->label(__('surveys.fields.max_responders'))
                    ->placeholder(__('surveys.fields.unlimited'))
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('end_at')
                    ->label(__('surveys.fields.end_at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('merge_tag')
                    ->label(__('surveys.fields.merge_tag'))
                    ->state(fn (Survey $record): string => app(SurveyLinkResolver::class)->tagFor($record))
                    ->copyable()
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('surveys.fields.status'))
                    ->options(SurveyStatus::options()),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('questions')
                    ->label(__('surveys.actions.questions'))
                    ->icon('heroicon-m-queue-list')
                    ->url(fn (Survey $record): string => SurveyResource::getUrl('questions', ['record' => $record])),
                Tables\Actions\Action::make('responders')
                    ->label(__('surveys.actions.responders'))
                    ->icon('heroicon-m-users')
                    ->url(fn (Survey $record): string => SurveyResource::getUrl('responders', ['record' => $record])),
                Tables\Actions\Action::make('stats')
                    ->label(__('surveys.actions.stats'))
                    ->icon('heroicon-m-chart-pie')
                    ->url(fn (Survey $record): string => SurveyResource::getUrl('stats', ['record' => $record])),
                Tables\Actions\Action::make('public')
                    ->label(__('surveys.actions.open_public'))
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->url(fn (Survey $record): string => route('surveys.public.start', ['uid' => $record->uid]))
                    ->openUrlInNewTab(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
