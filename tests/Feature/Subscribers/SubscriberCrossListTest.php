<?php

declare(strict_types=1);

use App\Enums\SubscriberStatus;
use App\Filament\Customer\Resources\SubscriberResource;
use App\Filament\Customer\Resources\SubscriberResource\Pages\ViewSubscriber;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Services\Subscribers\SubscriberListMemberships;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;

/**
 * F-2.2.5-012 — cross-list view: every list of the SAME customer that carries
 * this email address. The email address is the one identifier that genuinely
 * recurs across tenants, so the tenant fence is the point of these tests.
 */
beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);

    $this->newsletter = EmailList::factory()->for($this->customer)->create(['name' => 'Newsletter']);
    $this->betaList = EmailList::factory()->for($this->customer)->create(['name' => 'Beta programme']);
    $this->archive = EmailList::factory()->for($this->customer)->create(['name' => 'Zzz archive']);

    $this->subscriber = Subscriber::factory()->for($this->newsletter, 'list')->create([
        'email' => 'shared@x.example',
        'status' => SubscriberStatus::Confirmed,
        'subscribed_at' => now()->subDays(3),
    ]);

    $this->onBeta = Subscriber::factory()->for($this->betaList, 'list')->create([
        'email' => 'shared@x.example',
        'status' => SubscriberStatus::Unsubscribed,
        'subscribed_at' => now()->subDays(10),
    ]);

    // Same customer, different address — must not appear.
    Subscriber::factory()->for($this->archive, 'list')->create(['email' => 'someone-else@x.example']);

    $this->memberships = app(SubscriberListMemberships::class);
});

it('lists every list of this customer holding the address, in list-name order (F-2.2.5-012)', function (): void {
    $rows = $this->memberships->for($this->subscriber);

    expect($rows)->toHaveCount(2)
        ->and(array_column($rows, 'list_name'))->toBe(['Beta programme', 'Newsletter'])
        ->and(array_column($rows, 'current'))->toBe([false, true])
        ->and($rows[0]['status'])->toBe(SubscriberStatus::Unsubscribed)
        ->and($rows[1]['status'])->toBe(SubscriberStatus::Confirmed)
        ->and($rows[0]['list_id'])->toBe((int) $this->betaList->id)
        ->and($rows[0]['subscriber_id'])->toBe((int) $this->onBeta->id)
        ->and($rows[1]['subscriber_id'])->toBe((int) $this->subscriber->id)
        ->and($rows[0]['subscribed_at']?->format('Y-m-d'))->toBe(now()->subDays(10)->format('Y-m-d'));
});

it('matches the address case-insensitively (F-2.2.5-012)', function (): void {
    // Stored lowercase by the model; a mixed-case lookup still resolves.
    $this->subscriber->email = 'SHARED@X.EXAMPLE';

    expect($this->memberships->for($this->subscriber))->toHaveCount(2);
});

it('excludes soft-deleted subscriber rows and soft-deleted lists (F-2.2.5-012)', function (): void {
    $trashedList = EmailList::factory()->for($this->customer)->create(['name' => 'Trashed list']);
    Subscriber::factory()->for($trashedList, 'list')->create(['email' => 'shared@x.example']);
    $trashedList->delete();

    $deletedRow = Subscriber::factory()->for($this->archive, 'list')->create(['email' => 'shared@x.example']);
    $deletedRow->delete();

    expect(array_column($this->memberships->for($this->subscriber), 'list_name'))
        ->toBe(['Beta programme', 'Newsletter']);
});

it('never leaks another tenant\'s list carrying the same address (F-2.2.5-012)', function (): void {
    $foreignCustomer = Customer::factory()->create();
    $foreignList = EmailList::factory()->for($foreignCustomer)->create(['name' => 'Competitor list']);
    Subscriber::factory()->for($foreignList, 'list')->create(['email' => 'shared@x.example']);

    $rows = $this->memberships->for($this->subscriber);

    expect($rows)->toHaveCount(2)
        ->and(array_column($rows, 'list_name'))->not->toContain('Competitor list');

    $this->get(SubscriberResource::getUrl('view', ['record' => $this->subscriber], panel: 'customer'))
        ->assertOk()
        ->assertSee(__('subscribers.memberships.heading'))
        ->assertSee('Beta programme')
        ->assertDontSee('Competitor list');
});

it('exposes the memberships through the profile page and the JSON export (F-2.2.5-012)', function (): void {
    $page = new ViewSubscriber;
    $page->record = $this->subscriber;

    expect(array_column($page->listMemberships(), 'list_name'))->toBe(['Beta programme', 'Newsletter']);

    expect($page->exportPayload()['lists'])->toBe([
        ['list' => 'Beta programme', 'status' => 'unsubscribed', 'subscribed_at' => $this->onBeta->subscribed_at->toIso8601String()],
        ['list' => 'Newsletter', 'status' => 'confirmed', 'subscribed_at' => $this->subscriber->subscribed_at->toIso8601String()],
    ]);
});
