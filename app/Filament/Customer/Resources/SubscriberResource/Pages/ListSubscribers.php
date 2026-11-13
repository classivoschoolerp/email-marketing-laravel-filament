<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\SubscriberResource\Pages;

use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Enums\SubscriberSource;
use App\Enums\SubscriberStatus;
use App\Filament\Customer\Resources\SubscriberResource;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ListSegment;
use App\Models\Subscriber;
use App\Models\SubscriberSavedView;
use Closure;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

/**
 * Subscribers index (spec §2.2.4) with inline quick-add (F-2.2.4-010),
 * save-search-as-segment (F-2.2.4-004) and named saved table views —
 * filters + visible columns + search (F-2.2.4-009).
 */
class ListSubscribers extends ListRecords
{
    protected static string $resource = SubscriberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label(__('subscribers.actions.create')),
            // Inline quick-add (F-2.2.4-010): email-only modal, no page change.
            Actions\Action::make('quick_add')
                ->label(__('subscribers.actions.quick_add'))
                ->icon('heroicon-m-bolt')
                ->form([
                    Forms\Components\Select::make('list_id')
                        ->label(__('subscribers.form.list'))
                        ->options(fn (): array => SubscriberResource::listOptions())
                        ->default(fn (): mixed => $this->getTableFilterState('list_id')['value'] ?? null)
                        ->required(),
                    Forms\Components\TextInput::make('email')
                        ->label(__('subscribers.form.email'))
                        ->email()
                        ->required()
                        ->rule(fn (Forms\Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            $exists = Subscriber::query()
                                ->where('list_id', $get('list_id'))
                                ->where('email', mb_strtolower(trim((string) $value)))
                                ->exists();

                            if ($exists) {
                                $fail(__('subscribers.form.duplicate_error'));
                            }
                        }),
                    Forms\Components\Select::make('status')
                        ->label(__('subscribers.form.status'))
                        ->options(SubscriberStatus::options())
                        ->default(SubscriberStatus::Confirmed->value)
                        ->required(),
                ])
                ->authorize(fn (): bool => Filament::auth()->user()?->can('create', Subscriber::class) ?? false)
                ->action(function (array $data): void {
                    $user = Filament::auth()->user();

                    Subscriber::create([
                        'customer_id' => $user instanceof Customer ? $user->id : null,
                        'list_id' => (int) $data['list_id'],
                        'email' => (string) $data['email'],
                        'status' => (string) $data['status'],
                        'source' => SubscriberSource::Manual,
                    ]);

                    Notification::make()->title(__('subscribers.actions.added'))->success()->send();
                }),
            // Save the current search + status filter as a segment (F-2.2.4-004).
            Actions\Action::make('save_segment')
                ->label(__('subscribers.actions.save_segment'))
                ->icon('heroicon-m-funnel')
                ->visible(fn (): bool => filled($this->getTableFilterState('list_id')['value'] ?? null)
                    && (filled($this->getTableSearch()) || filled($this->getTableFilterState('status')['values'] ?? null)))
                ->form([
                    Forms\Components\TextInput::make('name')
                        ->label(__('segments.form.name'))
                        ->required()
                        ->maxLength(191),
                ])
                ->action(function (array $data): void {
                    $listId = (int) ($this->getTableFilterState('list_id')['value'] ?? 0);
                    $search = trim((string) $this->getTableSearch());
                    $statuses = array_values((array) ($this->getTableFilterState('status')['values'] ?? []));

                    /** @var EmailList|null $list */
                    $list = EmailList::query()->find($listId);

                    if ($list === null) {
                        return;
                    }

                    $user = Filament::auth()->user();
                    $customerId = $user instanceof Customer ? $user->id : (int) $list->customer_id;

                    // Multiple statuses need ANY matching; a search term needs
                    // ALL — nested groups are P2 (F-2.2.10-011), so with both
                    // we keep the search and the first status.
                    $matchType = ($search === '' && count($statuses) > 1) ? 'any' : 'all';

                    if ($search !== '' && count($statuses) > 1) {
                        $statuses = array_slice($statuses, 0, 1);
                    }

                    $segment = ListSegment::create([
                        'customer_id' => $customerId,
                        'list_id' => $list->id,
                        'name' => (string) $data['name'],
                        'match_type' => $matchType,
                    ]);

                    $sort = 0;

                    if ($search !== '') {
                        $segment->conditions()->create([
                            'customer_id' => $customerId,
                            'type' => SegmentConditionType::Field->value,
                            'operator' => SegmentOperator::Contains->value,
                            'value' => $search,
                            'sort_order' => $sort++,
                        ]);
                    }

                    foreach ($statuses as $status) {
                        $segment->conditions()->create([
                            'customer_id' => $customerId,
                            'type' => SegmentConditionType::Status->value,
                            'operator' => SegmentOperator::Is->value,
                            'value' => (string) $status,
                            'sort_order' => $sort++,
                        ]);
                    }

                    Notification::make()->title(__('subscribers.actions.segment_saved'))->success()->send();
                }),
            ...$this->savedViewActions(),
        ];
    }

    /* ================================================================ *
     *  Saved views (F-2.2.4-009)
     * ================================================================ */

    /**
     * Named per-customer table views: save the current filters + visible
     * columns + search, load one back, delete one. The state written and read
     * here is Filament's own Livewire table state, so a loaded view is
     * re-evaluated by the table's filters exactly like a hand-made one.
     *
     * @return array<int, Actions\Action>
     */
    private function savedViewActions(): array
    {
        return [
            Actions\Action::make('save_view')
                ->label(__('subscribers.views.save'))
                ->icon('heroicon-m-bookmark')
                ->form([
                    Forms\Components\TextInput::make('name')
                        ->label(__('subscribers.views.name'))
                        ->required()
                        ->maxLength(191),
                ])
                ->action(function (array $data): void {
                    $customerId = SubscriberResource::currentCustomerId();

                    if ($customerId === 0) {
                        return;
                    }

                    SubscriberSavedView::updateOrCreate(
                        ['customer_id' => $customerId, 'name' => (string) $data['name']],
                        [
                            'filters' => $this->tableFilters ?? [],
                            'columns' => $this->toggledTableColumns,
                            'search' => (string) $this->getTableSearch(),
                        ],
                    );

                    Notification::make()->title(__('subscribers.views.saved'))->success()->send();
                }),
            Actions\Action::make('load_view')
                ->label(__('subscribers.views.load'))
                ->icon('heroicon-m-arrow-down-on-square')
                ->visible(fn (): bool => SubscriberSavedView::query()->exists())
                ->form([
                    Forms\Components\Select::make('view_id')
                        ->label(__('subscribers.views.name'))
                        ->options(fn (): array => SubscriberSavedView::pickerOptions())
                        ->required(),
                ])
                ->action(function (array $data): void {
                    // Tenant-scoped find: a forged id from another customer
                    // resolves to null and nothing is applied.
                    $view = SubscriberSavedView::query()->find((int) $data['view_id']);

                    if ($view === null) {
                        Notification::make()->title(__('subscribers.views.missing'))->danger()->send();

                        return;
                    }

                    $this->tableFilters = (array) ($view->filters ?? []);
                    $this->toggledTableColumns = (array) ($view->columns ?? []);
                    $this->tableSearch = (string) ($view->search ?? '');

                    // Persist the restored state the same way the UI would.
                    $this->updatedToggledTableColumns();
                    $this->updatedTableSearch();
                    $this->handleTableFilterUpdates();

                    Notification::make()->title(__('subscribers.views.loaded', ['name' => $view->name]))->success()->send();
                }),
            Actions\Action::make('delete_view')
                ->label(__('subscribers.views.delete'))
                ->icon('heroicon-m-trash')
                ->color('danger')
                ->visible(fn (): bool => SubscriberSavedView::query()->exists())
                ->requiresConfirmation()
                ->form([
                    Forms\Components\Select::make('view_id')
                        ->label(__('subscribers.views.name'))
                        ->options(fn (): array => SubscriberSavedView::pickerOptions())
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $view = SubscriberSavedView::query()->find((int) $data['view_id']);

                    if ($view === null) {
                        Notification::make()->title(__('subscribers.views.missing'))->danger()->send();

                        return;
                    }

                    $view->delete();

                    Notification::make()->title(__('subscribers.views.deleted'))->success()->send();
                }),
        ];
    }
}
