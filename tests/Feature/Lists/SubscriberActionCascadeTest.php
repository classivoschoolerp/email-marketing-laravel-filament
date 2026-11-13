<?php

declare(strict_types=1);

use App\Enums\SubscriberStatus;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Support\Tenancy\CurrentCustomer;

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    app(CurrentCustomer::class)->set($this->customer);
});

function subscriberOn(EmailList $list, string $email): ?Subscriber
{
    return Subscriber::withoutTenancy()
        ->where('list_id', $list->id)
        ->where('email', $email)
        ->first();
}

it('subscribes to the linked lists when someone subscribes (F-2.2.2-015)', function (): void {
    $target = EmailList::factory()->for($this->customer)->create();
    $list = EmailList::factory()->for($this->customer)->create([
        'on_subscribe_subscribe_ids' => [$target->id],
    ]);

    Subscriber::factory()->for($list, 'list')->create(['email' => 'a@x.example']);

    expect(subscriberOn($target, 'a@x.example'))->not->toBeNull()
        ->and(subscriberOn($target, 'a@x.example')->status)->toBe(SubscriberStatus::Confirmed);
});

it('unsubscribes from the linked lists when someone subscribes (F-2.2.2-016)', function (): void {
    $target = EmailList::factory()->for($this->customer)->create();
    Subscriber::factory()->for($target, 'list')->create(['email' => 'b@x.example']);

    $list = EmailList::factory()->for($this->customer)->create([
        'on_subscribe_unsubscribe_ids' => [$target->id],
    ]);

    Subscriber::factory()->for($list, 'list')->create(['email' => 'b@x.example']);

    expect(subscriberOn($target, 'b@x.example')->status)->toBe(SubscriberStatus::Unsubscribed);
});

it('unsubscribes from linked lists on unsubscribe (F-2.2.2-017)', function (): void {
    $target = EmailList::factory()->for($this->customer)->create();
    Subscriber::factory()->for($target, 'list')->create(['email' => 'c@x.example']);

    $list = EmailList::factory()->for($this->customer)->create([
        'on_unsubscribe_unsubscribe_ids' => [$target->id],
    ]);
    $subscriber = Subscriber::factory()->for($list, 'list')->create(['email' => 'c@x.example']);

    $subscriber->status = SubscriberStatus::Unsubscribed;
    $subscriber->save();

    expect(subscriberOn($target, 'c@x.example')->status)->toBe(SubscriberStatus::Unsubscribed);
});

it('subscribes to linked lists on unsubscribe (F-2.2.2-018)', function (): void {
    $target = EmailList::factory()->for($this->customer)->create();
    $list = EmailList::factory()->for($this->customer)->create([
        'on_unsubscribe_subscribe_ids' => [$target->id],
    ]);
    $subscriber = Subscriber::factory()->for($list, 'list')->create(['email' => 'd@x.example']);

    $subscriber->status = SubscriberStatus::Unsubscribed;
    $subscriber->save();

    $onTarget = subscriberOn($target, 'd@x.example');

    expect($onTarget)->not->toBeNull()
        ->and($onTarget->status)->toBe(SubscriberStatus::Confirmed);
});

it('survives mutually referencing lists without infinite recursion', function (): void {
    $a = EmailList::factory()->for($this->customer)->create();
    $b = EmailList::factory()->for($this->customer)->create();

    $a->update(['on_subscribe_subscribe_ids' => [$b->id]]);
    $b->update(['on_subscribe_subscribe_ids' => [$a->id]]);

    Subscriber::factory()->for($a, 'list')->create(['email' => 'loop@x.example']);

    expect(subscriberOn($b, 'loop@x.example'))->not->toBeNull()
        ->and(Subscriber::withoutTenancy()->where('email', 'loop@x.example')->count())->toBe(2);
});

it('ignores cross-tenant list ids in subscriber actions', function (): void {
    $foreign = EmailList::factory()->create(); // other customer
    $list = EmailList::factory()->for($this->customer)->create([
        'on_subscribe_subscribe_ids' => [$foreign->id],
    ]);

    Subscriber::factory()->for($list, 'list')->create(['email' => 'e@x.example']);

    expect(subscriberOn($foreign, 'e@x.example'))->toBeNull();
});

it('does not fire subscribe actions for inactive statuses', function (): void {
    $target = EmailList::factory()->for($this->customer)->create();
    $list = EmailList::factory()->for($this->customer)->create([
        'on_subscribe_subscribe_ids' => [$target->id],
    ]);

    Subscriber::factory()->for($list, 'list')->unsubscribed()->create(['email' => 'f@x.example']);

    expect(subscriberOn($target, 'f@x.example'))->toBeNull();
});
