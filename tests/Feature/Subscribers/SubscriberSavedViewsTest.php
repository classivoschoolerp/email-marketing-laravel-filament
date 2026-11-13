<?php

declare(strict_types=1);

use App\Filament\Customer\Resources\SubscriberResource\Pages\ListSubscribers;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Models\SubscriberSavedView;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * F-2.2.4-009 — named, per-customer saved views (filters + visible columns +
 * search) on the subscribers index: save the current view, load it, delete it.
 */
beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->for($this->customer)->create();

    $this->confirmed = Subscriber::factory()->for($this->list, 'list')->count(3)->create();
    $this->unsubscribed = Subscriber::factory()->for($this->list, 'list')->unsubscribed()->count(2)->create();
});

it('saves the current filters, columns and search as a named view', function (): void {
    Livewire::test(ListSubscribers::class)
        ->filterTable('status', ['unsubscribed'])
        ->set('toggledTableColumns', ['source' => false, 'ip' => true])
        ->searchTable('example')
        ->callAction('save_view', data: ['name' => 'Churn watch'])
        ->assertHasNoActionErrors();

    $view = SubscriberSavedView::query()->firstOrFail();

    expect(SubscriberSavedView::query()->count())->toBe(1)
        ->and($view->name)->toBe('Churn watch')
        ->and((int) $view->customer_id)->toBe($this->customer->id)
        ->and($view->filters['status']['values'])->toBe(['unsubscribed'])
        ->and($view->columns)->toBe(['source' => false, 'ip' => true])
        ->and($view->search)->toBe('example');
});

it('loads a saved view back onto the table', function (): void {
    SubscriberSavedView::create([
        'customer_id' => $this->customer->id,
        'name' => 'Only unsubscribed',
        'filters' => ['status' => ['values' => ['unsubscribed']]],
        'columns' => ['source' => false, 'ip' => true],
        'search' => '',
    ]);

    $page = Livewire::test(ListSubscribers::class)
        ->assertCountTableRecords(5)
        ->callAction('load_view', data: ['view_id' => SubscriberSavedView::query()->value('id')])
        ->assertHasNoActionErrors();

    expect($page->get('tableFilters')['status']['values'])->toBe(['unsubscribed'])
        ->and($page->get('toggledTableColumns'))->toBe(['source' => false, 'ip' => true]);

    // The restored filter really constrains the query: 2 of 5.
    $page->assertCountTableRecords(2)
        ->assertCanSeeTableRecords($this->unsubscribed)
        ->assertCanNotSeeTableRecords($this->confirmed);
});

it('overwrites a view saved again under the same name', function (): void {
    Livewire::test(ListSubscribers::class)
        ->filterTable('status', ['unsubscribed'])
        ->callAction('save_view', data: ['name' => 'Mine']);

    Livewire::test(ListSubscribers::class)
        ->filterTable('status', ['confirmed'])
        ->callAction('save_view', data: ['name' => 'Mine']);

    expect(SubscriberSavedView::query()->count())->toBe(1)
        ->and(SubscriberSavedView::query()->firstOrFail()->filters['status']['values'])->toBe(['confirmed']);
});

it('deletes a saved view', function (): void {
    $view = SubscriberSavedView::create([
        'customer_id' => $this->customer->id,
        'name' => 'Disposable',
        'filters' => [],
        'columns' => [],
    ]);

    Livewire::test(ListSubscribers::class)
        ->callAction('delete_view', data: ['view_id' => $view->id])
        ->assertHasNoActionErrors();

    expect(SubscriberSavedView::query()->count())->toBe(0);
});

it('hides the load and delete actions until a view exists', function (): void {
    Livewire::test(ListSubscribers::class)
        ->assertActionVisible('save_view')
        ->assertActionHidden('load_view')
        ->assertActionHidden('delete_view');

    SubscriberSavedView::create([
        'customer_id' => $this->customer->id,
        'name' => 'Something',
        'filters' => [],
        'columns' => [],
    ]);

    Livewire::test(ListSubscribers::class)
        ->assertActionVisible('load_view')
        ->assertActionVisible('delete_view');
});

it('never lists, loads or deletes another tenant\'s view', function (): void {
    $otherCustomer = Customer::factory()->create();
    $foreign = SubscriberSavedView::withoutTenancy()->create([
        'customer_id' => $otherCustomer->id,
        'name' => 'Foreign view',
        'filters' => ['status' => ['values' => ['blacklisted']]],
        'columns' => [],
    ]);

    // The tenant has one view of its own, so the actions are reachable —
    // but the foreign row is neither listed nor addressable.
    $own = SubscriberSavedView::create([
        'customer_id' => $this->customer->id,
        'name' => 'Mine',
        'filters' => [],
        'columns' => [],
    ]);

    expect(SubscriberSavedView::pickerOptions())->toBe([$own->id => 'Mine']);

    // A forged id resolves to nothing: no state applied, row untouched.
    $page = Livewire::test(ListSubscribers::class)
        ->callAction('load_view', data: ['view_id' => $foreign->id]);

    // Filters stay at their untouched default — the foreign view's
    // "blacklisted" status filter was never applied.
    expect($page->get('tableFilters')['status']['values'] ?? null)->toBe([]);

    Livewire::test(ListSubscribers::class)
        ->callAction('delete_view', data: ['view_id' => $foreign->id]);

    expect(SubscriberSavedView::withoutTenancy()->whereKey($foreign->id)->exists())->toBeTrue();
});
