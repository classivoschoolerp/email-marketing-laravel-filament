<?php

declare(strict_types=1);

use App\Filament\Customer\Resources\SubscriberResource;
use App\Filament\Customer\Resources\SubscriberResource\Pages\ListSubscribers;
use App\Filament\Customer\Resources\SubscriberResource\Pages\ViewSubscriber;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Models\SubscriberTag;
use App\Services\Subscribers\SubscriberTagService;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * F-2.2.5-004 — subscriber tags. The storage (subscriber_tags) shipped with
 * WP-21 for the automation tag triggers; what these tests pin is the P2 UX:
 * a tag editor on the profile that still fires the automation model events,
 * a tag column, and a tag filter on the index — all tenant-fenced.
 */
beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->for($this->customer)->create();

    $this->subscriber = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'tagged@x.example']);
    $this->other = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'plain@x.example']);

    $this->tags = app(SubscriberTagService::class);
});

it('lists a subscriber\'s tags on the profile in name order (F-2.2.5-004)', function (): void {
    $this->tags->attach($this->subscriber, 'VIP');
    $this->tags->attach($this->subscriber, 'beta');

    Livewire::test(ViewSubscriber::class, ['record' => $this->subscriber->getRouteKey()])
        ->assertFormSet(['tags' => ['beta', 'vip']]);
});

it('adds and removes tags from the profile editor (F-2.2.5-004)', function (): void {
    $this->tags->attach($this->subscriber, 'vip');
    $this->tags->attach($this->subscriber, 'stale');

    Livewire::test(ViewSubscriber::class, ['record' => $this->subscriber->getRouteKey()])
        ->fillForm(['tags' => ['vip', '  Newsletter  ']])
        ->call('saveFields')
        ->assertHasNoFormErrors();

    // "stale" detached, "vip" untouched, "  Newsletter  " normalized.
    expect($this->tags->tagsFor($this->subscriber))->toBe(['newsletter', 'vip']);

    $rows = SubscriberTag::withoutTenancy()->where('subscriber_id', $this->subscriber->id)->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('customer_id')->unique()->values()->all())->toBe([$this->customer->id]);
});

it('clears every tag when the editor is emptied (F-2.2.5-004)', function (): void {
    $this->tags->attach($this->subscriber, 'vip');

    Livewire::test(ViewSubscriber::class, ['record' => $this->subscriber->getRouteKey()])
        ->fillForm(['tags' => []])
        ->call('saveFields')
        ->assertHasNoFormErrors();

    expect($this->tags->tagsFor($this->subscriber))->toBe([])
        ->and(SubscriberTag::withoutTenancy()->where('subscriber_id', $this->subscriber->id)->count())->toBe(0);
});

it('filters the subscribers index by tag (F-2.2.5-004)', function (): void {
    $this->tags->attach($this->subscriber, 'vip');
    $this->tags->attach($this->other, 'beta');

    Livewire::test(ListSubscribers::class)
        ->filterTable('tags', ['vip'])
        ->assertCanSeeTableRecords([$this->subscriber])
        ->assertCanNotSeeTableRecords([$this->other])
        ->assertCountTableRecords(1);

    // Multiple tags match ANY of them.
    Livewire::test(ListSubscribers::class)
        ->filterTable('tags', ['vip', 'beta'])
        ->assertCanSeeTableRecords([$this->subscriber, $this->other])
        ->assertCountTableRecords(2);

    // No tag selected leaves the table untouched.
    Livewire::test(ListSubscribers::class)
        ->filterTable('tags', [])
        ->assertCountTableRecords(2);
});

it('never offers or matches another tenant\'s tags (F-2.2.5-004)', function (): void {
    $this->tags->attach($this->subscriber, 'vip');

    $foreignCustomer = Customer::factory()->create();
    $foreignList = EmailList::factory()->for($foreignCustomer)->create();
    $foreignSubscriber = Subscriber::factory()->for($foreignList, 'list')->create(['email' => 'foreign@y.example']);

    $this->tags->attach($foreignSubscriber, 'secret-tenant-tag');
    $this->tags->attach($foreignSubscriber, 'vip');

    // Suggestions/filter options carry this tenant's names only.
    expect(SubscriberResource::tagOptions())->toBe(['vip' => 'vip']);

    // A shared tag name never drags the other tenant's subscriber in.
    Livewire::test(ListSubscribers::class)
        ->filterTable('tags', ['vip', 'secret-tenant-tag'])
        ->assertCanSeeTableRecords([$this->subscriber])
        ->assertCanNotSeeTableRecords([$foreignSubscriber])
        ->assertCountTableRecords(1);
});
