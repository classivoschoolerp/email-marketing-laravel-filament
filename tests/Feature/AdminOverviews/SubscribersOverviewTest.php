<?php

declare(strict_types=1);

use App\Enums\SubscriberStatus;
use App\Filament\Admin\Resources\SubscriberOverviewResource;
use App\Filament\Admin\Resources\SubscriberOverviewResource\Pages\ListSubscriberOverview;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\GlobalBlacklistEmail;
use App\Models\Subscriber;
use App\Services\Subscribers\GlobalSubscriberSearch;
use Livewire\Livewire;
use Tests\Support\AdminTestHelpers;

/*
 * WP-39 — §3.8 subscribers overview across all customers.
 *
 * F-3.8-001 global search by email, F-3.8-002 which lists an address is on
 * system-wide, F-3.8-003 global unsubscribe / blacklist.
 */

/** Two tenants, the same address on three lists between them. */
function wp39_overview_fixture(): array
{
    $a = Customer::factory()->create(['email' => 'tenant-a@example.test']);
    $b = Customer::factory()->create(['email' => 'tenant-b@example.test']);

    $listA1 = EmailList::factory()->create(['customer_id' => $a->id, 'name' => 'A weekly']);
    $listA2 = EmailList::factory()->create(['customer_id' => $a->id, 'name' => 'A offers']);
    $listB1 = EmailList::factory()->create(['customer_id' => $b->id, 'name' => 'B news']);

    $shared = 'shared@example.test';

    $rows = [
        Subscriber::factory()->create(['customer_id' => $a->id, 'list_id' => $listA1->id, 'email' => $shared]),
        Subscriber::factory()->create(['customer_id' => $a->id, 'list_id' => $listA2->id, 'email' => $shared]),
        Subscriber::factory()->create(['customer_id' => $b->id, 'list_id' => $listB1->id, 'email' => $shared]),
    ];

    $other = Subscriber::factory()->create([
        'customer_id' => $b->id,
        'list_id' => $listB1->id,
        'email' => 'someone-else@example.test',
    ]);

    return compact('a', 'b', 'rows', 'other', 'shared');
}

it('searches subscribers across every customer (F-3.8-001)', function (): void {
    $fixture = wp39_overview_fixture();
    $search = app(GlobalSubscriberSearch::class);

    $results = $search->search($fixture['shared']);

    expect($results)->toHaveCount(3)
        ->and($results->pluck('customer_id')->unique()->sort()->values()->all())
        ->toBe([$fixture['a']->id, $fixture['b']->id]);

    // Partial matching so an operator can hunt a whole domain.
    expect($search->search('example.test')->count())->toBe(4)
        ->and($search->search('  ')->count())->toBe(0);
});

it('reports every list an address is on, system-wide (F-3.8-002)', function (): void {
    $fixture = wp39_overview_fixture();
    $search = app(GlobalSubscriberSearch::class);

    $memberships = $search->memberships($fixture['shared']);

    expect($memberships)->toHaveCount(3)
        ->and($memberships->pluck('list.name')->sort()->values()->all())
        ->toBe(['A offers', 'A weekly', 'B news'])
        ->and($search->listCount($fixture['shared']))->toBe(3)
        ->and($search->listCount('nobody@example.test'))->toBe(0);
});

it('unsubscribes an address everywhere and blacklists it install-wide (F-3.8-003)', function (): void {
    $fixture = wp39_overview_fixture();

    $result = app(GlobalSubscriberSearch::class)->globalUnsubscribe(
        $fixture['shared'],
        blacklist: true,
        reason: 'Abuse report',
    );

    expect($result['unsubscribed'])->toBe(3)
        ->and($result['blacklisted'])->toBe(1);

    foreach ($fixture['rows'] as $row) {
        expect($row->refresh()->status)->toBe(SubscriberStatus::Unsubscribed)
            ->and($row->unsubscribed_at)->not->toBeNull();
    }

    // A different address on the same list is untouched.
    expect($fixture['other']->refresh()->status)->not->toBe(SubscriberStatus::Unsubscribed);

    $entry = GlobalBlacklistEmail::query()->where('email', $fixture['shared'])->firstOrFail();

    // customer_id null = install-wide, which binds every tenant (D-74).
    expect($entry->customer_id)->toBeNull()
        ->and($entry->reason)->toBe('Abuse report')
        ->and($entry->source)->toBe(GlobalBlacklistEmail::SOURCE_MANUAL);
});

it('is idempotent and does not re-count already unsubscribed rows', function (): void {
    $fixture = wp39_overview_fixture();
    $search = app(GlobalSubscriberSearch::class);

    expect($search->globalUnsubscribe($fixture['shared'])['unsubscribed'])->toBe(3)
        ->and($search->globalUnsubscribe($fixture['shared'])['unsubscribed'])->toBe(0);
});

it('can unsubscribe without blacklisting', function (): void {
    $fixture = wp39_overview_fixture();

    $result = app(GlobalSubscriberSearch::class)->globalUnsubscribe($fixture['shared']);

    expect($result['unsubscribed'])->toBe(3)
        ->and($result['blacklisted'])->toBe(0)
        ->and(GlobalBlacklistEmail::query()->where('email', $fixture['shared'])->exists())->toBeFalse();
});

it('lists every tenant\'s subscribers on the admin overview', function (): void {
    AdminTestHelpers::actingAdmin(['subscribers.view']);
    $fixture = wp39_overview_fixture();

    $this->get(SubscriberOverviewResource::getUrl('index', panel: 'admin'))->assertOk();

    Livewire::test(ListSubscriberOverview::class)
        ->assertCanSeeTableRecords([...$fixture['rows'], $fixture['other']]);
});

it('runs the global unsubscribe action from the admin table (F-3.8-003)', function (): void {
    AdminTestHelpers::actingAdmin(['subscribers.view', 'subscribers.update']);
    $fixture = wp39_overview_fixture();

    Livewire::test(ListSubscriberOverview::class)
        ->callTableAction('globalUnsubscribe', $fixture['rows'][0], data: [
            'blacklist' => true,
            'reason' => 'Spam trap',
        ])
        ->assertHasNoTableActionErrors();

    foreach ($fixture['rows'] as $row) {
        expect($row->refresh()->status)->toBe(SubscriberStatus::Unsubscribed);
    }

    expect(GlobalBlacklistEmail::query()->where('email', $fixture['shared'])->exists())->toBeTrue();
});

it('keeps the overview behind the admin subscriber policy', function (): void {
    $fixture = wp39_overview_fixture();

    // An admin with no delegated permissions may not read the overview.
    $powerless = AdminTestHelpers::actingAdmin();

    expect($powerless->can('viewAny', Subscriber::class))->toBeFalse();

    expect($this->get(SubscriberOverviewResource::getUrl('index', panel: 'admin'))->status())
        ->toBeIn([403, 404]);
});
