<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\AutomationResource\Pages;

use App\Enums\AutomationNodeType;
use App\Enums\AutomationStatus;
use App\Filament\Customer\Resources\AutomationResource;
use App\Jobs\Automations\BulkAutomationActionJob;
use App\Models\Automation;
use App\Models\Customer;
use App\Models\EmailList;
use App\Services\Automations\Blueprints;
use App\Services\Automations\GraphPublisher;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * §2.4.1 automations index: table (F-2.4.1-001), filters (F-2.4.1-002),
 * actions (F-2.4.1-003) and the pre-built templates gallery (F-2.4.1-004).
 * Bulk actions filter to policy-permitted rows (D-28).
 */
class ListAutomations extends ListRecords
{
    protected static string $resource = AutomationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('fromBlueprint')
                ->label(__('automations.actions.from_blueprint'))
                ->icon('heroicon-m-sparkles')
                ->color('gray')
                ->visible(fn (): bool => auth('customer')->user()?->can('create', Automation::class) ?? false)
                ->form([
                    Forms\Components\Radio::make('blueprint')
                        ->label(__('automations.fields.blueprint'))
                        ->options(collect(Blueprints::options())->map(fn (array $option): string => $option['name'])->all())
                        ->descriptions(collect(Blueprints::options())->map(fn (array $option): string => $option['description'])->all())
                        ->required(),
                ])
                ->action(function (array $data): void {
                    /** @var Customer $customer */
                    $customer = auth('customer')->user();

                    $automation = app(Blueprints::class)->instantiate((string) $data['blueprint'], $customer);

                    $this->redirect(AutomationResource::getUrl('builder', ['record' => $automation]));
                }),

            Actions\CreateAction::make()
                ->label(__('automations.actions.create')),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('automations.fields.name'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (Automation $record): ?string => $record->isPublished()
                        ? __('automations.fields.version').' '.$record->version
                        : null),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('automations.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (AutomationStatus $state): string => $state->label())
                    ->color(fn (AutomationStatus $state): string => $state->color()),

                Tables\Columns\TextColumn::make('trigger_type')
                    ->label(__('automations.fields.trigger'))
                    ->formatStateUsing(fn (?string $state): string => $state !== null
                        ? (AutomationNodeType::tryFrom($state)?->label() ?? $state)
                        : '—'),

                Tables\Columns\TextColumn::make('entered_count')
                    ->label(__('automations.fields.entered'))
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('active_count')
                    ->label(__('automations.fields.active'))
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('completed_count')
                    ->label(__('automations.fields.completed'))
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('conversion')
                    ->label(__('automations.fields.conversion'))
                    ->state(fn (Automation $record): string => $record->conversionRate() !== null
                        ? $record->conversionRate().'%'
                        : '—'),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('automations.fields.updated_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('automations.filters.status'))
                    ->options(AutomationStatus::options()),

                Tables\Filters\SelectFilter::make('trigger_type')
                    ->label(__('automations.filters.trigger'))
                    ->options(AutomationNodeType::optionsFor(AutomationNodeType::triggers())),

                Tables\Filters\SelectFilter::make('email_list_id')
                    ->label(__('automations.filters.list'))
                    ->options(fn (): array => EmailList::query()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->actions([
                Tables\Actions\Action::make('builder')
                    ->label(__('automations.actions.builder'))
                    ->icon('heroicon-m-pencil-square')
                    ->url(fn (Automation $record): string => AutomationResource::getUrl('builder', ['record' => $record]))
                    ->visible(fn (Automation $record): bool => auth('customer')->user()?->can('update', $record) ?? false),

                Tables\Actions\Action::make('activate')
                    ->label(__('automations.actions.activate'))
                    ->icon('heroicon-m-play')
                    ->color('success')
                    ->visible(fn (Automation $record): bool => $record->status !== AutomationStatus::Active
                        && (auth('customer')->user()?->can('update', $record) ?? false))
                    ->action(fn (Automation $record) => $this->activate($record)),

                Tables\Actions\Action::make('pause')
                    ->label(__('automations.actions.pause'))
                    ->icon('heroicon-m-pause')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (Automation $record): bool => $record->status === AutomationStatus::Active
                        && (auth('customer')->user()?->can('update', $record) ?? false))
                    ->action(function (Automation $record): void {
                        $record->forceFill(['status' => AutomationStatus::Paused])->save();

                        Notification::make()->success()->title(__('automations.notifications.paused'))->send();
                    }),

                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('report')
                        ->label(__('automations.actions.report'))
                        ->icon('heroicon-m-chart-bar')
                        ->url(fn (Automation $record): string => AutomationResource::getUrl('report', ['record' => $record])),

                    Tables\Actions\EditAction::make()
                        ->label(__('automations.actions.settings')),

                    Tables\Actions\Action::make('duplicate')
                        ->label(__('automations.actions.duplicate'))
                        ->icon('heroicon-m-document-duplicate')
                        ->visible(fn (): bool => auth('customer')->user()?->can('create', Automation::class) ?? false)
                        ->action(function (Automation $record): void {
                            $copy = $record->replicate([
                                'uid', 'version', 'published_at', 'segment_seeded_at',
                                'entered_count', 'active_count', 'completed_count', 'converted_count',
                            ]);
                            $copy->name = __('automations.duplicate_name', ['name' => $record->name]);
                            $copy->status = AutomationStatus::Draft;
                            $copy->version = 0;
                            $copy->save();

                            Notification::make()->success()
                                ->title(__('automations.notifications.duplicated', ['name' => $copy->name]))
                                ->send();
                        }),

                    Tables\Actions\DeleteAction::make()
                        ->label(__('automations.actions.delete')),
                ]),
            ])
            // Every bulk hands the policy-filtered selection to a queued job
            // (Part 8 F-Phase-3-Differentiat-006 / docs/ARCHITECTURE.md rule 5). The caller
            // filters with the acting customer's policy (D-28); the job
            // re-checks state before each flip.
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make()
                    ->label(__('automations.actions.bulk_delete'))
                    ->using(fn (Collection $records) => $this->dispatchBulk(
                        $records,
                        BulkAutomationActionJob::ACTION_DELETE,
                        fn (Automation $record): bool => auth('customer')->user()?->can('delete', $record) ?? false,
                    )),

                Tables\Actions\BulkAction::make('pause')
                    ->label(__('automations.actions.bulk_pause'))
                    ->icon('heroicon-m-pause')
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records) => $this->dispatchBulk(
                        $records,
                        BulkAutomationActionJob::ACTION_PAUSE,
                        fn (Automation $record): bool => $record->status === AutomationStatus::Active
                            && (auth('customer')->user()?->can('update', $record) ?? false),
                    )),

                Tables\Actions\BulkAction::make('activate')
                    ->label(__('automations.actions.bulk_activate'))
                    ->icon('heroicon-m-play')
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records) => $this->dispatchBulk(
                        $records,
                        BulkAutomationActionJob::ACTION_ACTIVATE,
                        fn (Automation $record): bool => $record->status !== AutomationStatus::Active
                            && $record->isPublished()
                            && (auth('customer')->user()?->can('update', $record) ?? false),
                    )),
            ]);
    }

    /**
     * Filter a bulk selection with the acting customer's policy, then hand the
     * ids to the queued job. Returns nothing to the table — the work is async.
     *
     * @param  Collection<int, Automation>  $records
     * @param  callable(Automation): bool  $allowed
     */
    private function dispatchBulk(Collection $records, string $action, callable $allowed): void
    {
        $ids = $records->filter($allowed)->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();

        if ($ids !== []) {
            BulkAutomationActionJob::dispatch($ids, $action);
        }

        Notification::make()->success()
            ->title(__('automations.notifications.bulk_queued', ['count' => count($ids)]))
            ->send();
    }

    private function activate(Automation $record): void
    {
        if (! $record->isPublished()) {
            if (empty($record->canvas)) {
                Notification::make()->danger()
                    ->title(__('automations.notifications.cannot_activate_unpublished'))
                    ->send();

                return;
            }

            try {
                app(GraphPublisher::class)->publish($record);
                $record->refresh();
            } catch (ValidationException $exception) {
                Notification::make()->danger()
                    ->title(__('automations.notifications.publish_failed'))
                    ->body(collect($exception->errors())->flatten()->implode(' '))
                    ->send();

                return;
            }
        }

        $record->forceFill(['status' => AutomationStatus::Active])->save();

        Notification::make()->success()->title(__('automations.notifications.activated'))->send();
    }
}
