<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\LandingPageResource\Pages;

use App\Enums\LandingPageStatus;
use App\Filament\Customer\Resources\LandingPageResource;
use App\Jobs\Landing\DuplicateLandingPageJob;
use App\Models\LandingPage;
use App\Services\Landing\LandingPageUrl;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Landing-page index (spec §2.6 F-2.6-001): name, status, visits,
 * conversions, conversion rate and domain — plus the publish/unpublish
 * (F-2.6-012) and duplicate (F-2.6-014) actions.
 *
 * Both counters are aggregate subqueries, so the page costs one query no
 * matter how many pages a tenant owns (the §2.7 forms index rule).
 */
class ListLandingPages extends ListRecords
{
    protected static string $resource = LandingPageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label(__('landing.actions.create')),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withCount(['visits', 'conversions']))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('landing.fields.name'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (LandingPage $record): string => '/'.LandingPageUrl::PREFIX.'/'.$record->slug),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('landing.fields.status'))
                    ->badge()
                    ->color(fn (LandingPageStatus $state): string => $state->color())
                    ->formatStateUsing(fn (LandingPageStatus $state): string => $state->label()),

                Tables\Columns\TextColumn::make('visits_count')
                    ->label(__('landing.columns.visits'))
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('conversions_count')
                    ->label(__('landing.columns.conversions'))
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('conversion_rate')
                    ->label(__('landing.columns.conversion_rate'))
                    ->state(fn (LandingPage $record): string => self::conversionRate($record)),

                Tables\Columns\TextColumn::make('domain')
                    ->label(__('landing.columns.domain'))
                    ->state(fn (LandingPage $record): string => self::domainLabel($record))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('landing.fields.updated_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('landing.fields.status'))
                    ->options(LandingPageStatus::options()),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('build')
                    ->label(__('landing.actions.build'))
                    ->icon('heroicon-m-squares-2x2')
                    ->url(fn (LandingPage $record): string => LandingPageResource::getUrl('build', ['record' => $record])),

                Tables\Actions\Action::make('variants')
                    ->label(__('landing.actions.variants'))
                    ->icon('heroicon-m-beaker')
                    ->url(fn (LandingPage $record): string => LandingPageResource::getUrl('variants', ['record' => $record])),

                Tables\Actions\Action::make('analytics')
                    ->label(__('landing.actions.analytics'))
                    ->icon('heroicon-m-chart-bar')
                    ->url(fn (LandingPage $record): string => LandingPageResource::getUrl('analytics', ['record' => $record])),

                // F-2.6-012
                Tables\Actions\Action::make('publish')
                    ->label(__('landing.actions.publish'))
                    ->icon('heroicon-m-rocket-launch')
                    ->visible(fn (LandingPage $record): bool => $record->status !== LandingPageStatus::Published)
                    ->action(fn (LandingPage $record) => self::publish($record)),

                Tables\Actions\Action::make('unpublish')
                    ->label(__('landing.actions.unpublish'))
                    ->icon('heroicon-m-eye-slash')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (LandingPage $record): bool => $record->status === LandingPageStatus::Published)
                    ->action(fn (LandingPage $record) => self::unpublish($record)),

                // F-2.6-014
                Tables\Actions\Action::make('duplicate')
                    ->label(__('landing.actions.duplicate'))
                    ->icon('heroicon-m-document-duplicate')
                    ->action(function (LandingPage $record): void {
                        // Queued: the copy walks every uploaded asset (docs/ARCHITECTURE.md rule 5).
                        DuplicateLandingPageJob::dispatch(
                            (int) $record->id,
                            (int) $record->customer_id,
                            (string) Str::uuid(),
                        );

                        Notification::make()->success()
                            ->title(__('landing.notifications.duplicated'))
                            ->send();
                    }),

                Tables\Actions\EditAction::make()->label(__('landing.actions.settings')),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /** Conversion rate as a display string; no visits yet reads as "—". */
    public static function conversionRate(LandingPage $record): string
    {
        $visits = (int) ($record->visits_count ?? 0);
        $conversions = (int) ($record->conversions_count ?? 0);

        if ($visits <= 0) {
            return '—';
        }

        return number_format(($conversions / $visits) * 100, 1).'%';
    }

    /** The host the page is published on (F-2.6-001 "domain" column). */
    public static function domainLabel(LandingPage $record): string
    {
        $urls = app(LandingPageUrl::class);

        return (string) (parse_url($urls->base($record), PHP_URL_HOST) ?: $urls->appHost());
    }

    public static function publish(LandingPage $record): void
    {
        $record->forceFill([
            'status' => LandingPageStatus::Published,
            'published_at' => $record->published_at ?? now(),
        ])->save();

        Notification::make()->success()->title(__('landing.notifications.published'))->send();
    }

    public static function unpublish(LandingPage $record): void
    {
        $record->forceFill(['status' => LandingPageStatus::Unpublished])->save();

        Notification::make()->success()->title(__('landing.notifications.unpublished'))->send();
    }
}
