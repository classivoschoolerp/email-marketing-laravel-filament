<?php

declare(strict_types=1);

use App\Filament\Customer\Resources\EmailListResource;
use App\Filament\Customer\Resources\EmailListResource\Pages\ManageListSegments;
use App\Jobs\Segments\RefreshSegmentSnapshotJob;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ListSegment;
use App\Models\ListSegmentSnapshotMember;
use App\Models\Subscriber;
use App\Services\Campaigns\CampaignRecipients;
use App\Services\Segments\SegmentQueryBuilder;
use App\Support\Queue\Queues;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * F-2.2.10-013 — static / frozen segments. Freezing materialises the current
 * matches into list_segment_snapshot_members inside a QUEUED job; from then on
 * the segment resolves from that snapshot everywhere, send-time targeting
 * included.
 */
beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->for($this->customer)->create();
    $this->builder = app(SegmentQueryBuilder::class);

    $this->one = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'one@x.example']);
    $this->two = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'two@x.example']);
    $this->gone = Subscriber::factory()->for($this->list, 'list')->unsubscribed()->create(['email' => 'gone@x.example']);

    // "confirmed subscribers" — dynamically one@ + two@ today.
    $this->segment = ListSegment::factory()->for($this->list, 'list')->create([
        'name' => 'Confirmed',
        'match_type' => 'all',
        'is_static' => true,
    ]);

    $this->segment->conditions()->create([
        'customer_id' => $this->customer->id,
        'type' => 'status',
        'operator' => 'is',
        'value' => 'confirmed',
        'sort_order' => 0,
    ]);

    $this->freeze = fn (?ListSegment $segment = null) => RefreshSegmentSnapshotJob::dispatchSync(
        (int) ($segment ?? $this->segment)->id,
        (string) Str::uuid(),
    );
});

it('matches nothing until a static segment has been frozen (F-2.2.10-013)', function (): void {
    expect($this->segment->hasSnapshot())->toBeFalse()
        // Dynamically it would match two people — a static segment must never
        // silently fall back to the live set.
        ->and($this->builder->dynamicSubscribers($this->segment)->count())->toBe(2)
        ->and($this->builder->count($this->segment))->toBe(0)
        ->and($this->builder->subscribers($this->segment)->pluck('email')->all())->toBe([]);
});

it('freezes the current matches into a snapshot through a queued job (F-2.2.10-013)', function (): void {
    ($this->freeze)();

    $this->segment->refresh();

    $frozen = ListSegmentSnapshotMember::query()
        ->where('list_segment_id', $this->segment->id)
        ->orderBy('subscriber_id')
        ->pluck('subscriber_id')
        ->all();

    expect($frozen)->toBe([$this->one->id, $this->two->id])
        ->and($this->segment->snapshot_count)->toBe(2)
        ->and($this->segment->snapshot_taken_at)->not->toBeNull()
        ->and($this->builder->subscribers($this->segment)->orderBy('email')->pluck('email')->all())
        ->toBe(['one@x.example', 'two@x.example'])
        ->and($this->builder->count($this->segment))->toBe(2);
});

it('keeps the frozen membership when new subscribers start matching (F-2.2.10-013)', function (): void {
    ($this->freeze)();

    $late = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'late@x.example']);

    $this->segment->refresh();

    // The live conditions now match three people; the frozen segment does not.
    expect($this->builder->dynamicSubscribers($this->segment)->count())->toBe(3)
        ->and($this->builder->subscribers($this->segment)->orderBy('email')->pluck('email')->all())
        ->toBe(['one@x.example', 'two@x.example']);

    // Refreshing on demand recaptures — and only then.
    ($this->freeze)();

    $this->segment->refresh();

    expect($this->builder->subscribers($this->segment)->orderBy('email')->pluck('email')->all())
        ->toBe(['late@x.example', 'one@x.example', 'two@x.example'])
        ->and($this->segment->snapshot_count)->toBe(3)
        ->and(ListSegmentSnapshotMember::query()->where('list_segment_id', $this->segment->id)->count())->toBe(3)
        ->and(ListSegmentSnapshotMember::query()->where('subscriber_id', $late->id)->exists())->toBeTrue();
});

it('targets a campaign at the frozen membership at send time (F-2.2.10-013)', function (): void {
    ($this->freeze)();

    $campaign = Campaign::factory()->for($this->customer)->create([
        'email_list_id' => $this->list->id,
        'list_segment_id' => $this->segment->id,
    ]);

    // Somebody who joined after the freeze must NOT be mailed.
    Subscriber::factory()->for($this->list, 'list')->create(['email' => 'late@x.example']);

    $recipients = app(CampaignRecipients::class);

    expect($recipients->count($campaign))->toBe(2)
        ->and($recipients->query($campaign)?->orderBy('email')->pluck('email')->all())
        ->toBe(['one@x.example', 'two@x.example']);

    // The same campaign against a dynamic segment would pick the newcomer up.
    $this->segment->forceFill(['is_static' => false])->save();

    expect($recipients->count($campaign->refresh()))->toBe(3);
});

it('clears the snapshot when a segment goes back to dynamic (F-2.2.10-013)', function (): void {
    ($this->freeze)();

    $this->segment->forceFill(['is_static' => false])->save();

    ($this->freeze)();

    $this->segment->refresh();

    expect(ListSegmentSnapshotMember::query()->where('list_segment_id', $this->segment->id)->count())->toBe(0)
        ->and($this->segment->snapshot_taken_at)->toBeNull()
        ->and($this->segment->snapshot_count)->toBe(0)
        ->and($this->builder->subscribers($this->segment)->orderBy('email')->pluck('email')->all())
        ->toBe(['one@x.example', 'two@x.example']);
});

it('never lets another tenant see or resolve a snapshot (F-2.2.10-013)', function (): void {
    ($this->freeze)();

    $other = Customer::factory()->create();
    app(CurrentCustomer::class)->set($other);

    // The snapshot rows belong to the owning tenant and are scoped away.
    expect(ListSegmentSnapshotMember::query()->count())->toBe(0)
        ->and(ListSegmentSnapshotMember::withoutTenancy()->count())->toBe(2)
        ->and(ListSegment::query()->count())->toBe(0);

    // A foreign subscriber with the same list id can never be pulled in by a
    // snapshot row: the query re-fences to the segment's own customer.
    app(CurrentCustomer::class)->set($this->customer);

    expect($this->builder->subscribers($this->segment->refresh())->pluck('customer_id')->unique()->all())
        ->toBe([$this->customer->id]);
});

it('duplicating a static segment never inherits the original snapshot (F-2.2.10-013)', function (): void {
    ($this->freeze)();

    $copy = $this->segment->refresh()->replicate(['uid']);
    $copy->name = 'Copy';
    $copy->save();

    expect($copy->is_static)->toBeTrue()
        ->and($copy->snapshot_taken_at)->toBeNull()
        ->and($copy->snapshot_count)->toBe(0)
        ->and($this->builder->count($copy))->toBe(0);
});

it('queues the freeze instead of running it inline (F-2.2.10-013)', function (): void {
    Queue::fake();

    RefreshSegmentSnapshotJob::dispatch((int) $this->segment->id, 'op-1');

    Queue::assertPushedOn(Queues::MAINTENANCE, RefreshSegmentSnapshotJob::class);
    Queue::assertPushed(
        RefreshSegmentSnapshotJob::class,
        fn (RefreshSegmentSnapshotJob $job): bool => $job->segmentId === (int) $this->segment->id,
    );

    expect(ListSegmentSnapshotMember::withoutTenancy()->count())->toBe(0);
});

describe('segments UI (F-2.2.10-013)', function (): void {
    beforeEach(function (): void {
        $this->actingAs($this->customer, 'customer');
        Filament::setCurrentPanel(Filament::getPanel('customer'));
    });

    it('queues a snapshot refresh from the segment row action (F-2.2.10-013)', function (): void {
        Queue::fake();

        Livewire::test(ManageListSegments::class, ['record' => $this->list->getRouteKey()])
            ->callTableAction('refresh_snapshot', $this->segment)
            ->assertHasNoTableActionErrors();

        Queue::assertPushed(
            RefreshSegmentSnapshotJob::class,
            fn (RefreshSegmentSnapshotJob $job): bool => $job->segmentId === (int) $this->segment->id,
        );
    });

    it('freezes a segment created as static (F-2.2.10-013)', function (): void {
        Queue::fake();

        $undoRepeaterFake = Repeater::fake();

        Livewire::test(ManageListSegments::class, ['record' => $this->list->getRouteKey()])
            ->callTableAction('create', data: [
                'name' => 'Frozen',
                'match_type' => 'all',
                'is_static' => true,
                'conditions' => [
                    ['type' => 'status', 'operator' => 'is', 'value' => 'confirmed'],
                ],
            ])
            ->assertHasNoTableActionErrors();

        $undoRepeaterFake();

        $created = ListSegment::query()->where('name', 'Frozen')->firstOrFail();

        expect($created->is_static)->toBeTrue()
            ->and($created->snapshot_taken_at)->toBeNull();

        Queue::assertPushed(
            RefreshSegmentSnapshotJob::class,
            fn (RefreshSegmentSnapshotJob $job): bool => $job->segmentId === (int) $created->id,
        );
    });

    it('never exposes another tenant\'s segment to the snapshot action (F-2.2.10-013)', function (): void {
        $other = Customer::factory()->create();
        $otherList = EmailList::factory()->for($other)->create();
        $otherSegment = ListSegment::withoutTenancy()->create([
            'customer_id' => $other->id,
            'list_id' => $otherList->id,
            'name' => 'Theirs',
            'match_type' => 'all',
            'is_static' => true,
        ]);

        Queue::fake();

        // The foreign segment is not even visible in this tenant's table, and
        // firing the action at it queues nothing.
        Livewire::test(ManageListSegments::class, ['record' => $this->list->getRouteKey()])
            ->assertCanNotSeeTableRecords([$otherSegment])
            ->callTableAction('refresh_snapshot', $otherSegment);

        $this->get(EmailListResource::getUrl('segments', ['record' => $otherList], panel: 'customer'))
            ->assertNotFound();

        Queue::assertNotPushed(RefreshSegmentSnapshotJob::class);
        expect(ListSegmentSnapshotMember::withoutTenancy()->where('list_segment_id', $otherSegment->id)->count())->toBe(0);
    });
});
