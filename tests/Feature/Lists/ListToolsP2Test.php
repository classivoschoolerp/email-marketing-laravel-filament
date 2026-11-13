<?php

declare(strict_types=1);

use App\Enums\SubscriberStatus;
use App\Enums\VerificationStatus;
use App\Filament\Customer\Resources\EmailListResource\Pages\ListEmailLists;
use App\Jobs\Lists\CleanListJob;
use App\Jobs\Lists\DeduplicateListsJob;
use App\Jobs\Lists\MergeListsJob;
use App\Jobs\Verification\VerifyBatchJob;
use App\Models\CampaignBounceLog;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\GlobalBlacklistEmail;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Models\VerificationJob;
use App\Services\Blacklist\BlacklistMatcher;
use App\Services\Lists\FieldValueService;
use App\Services\Lists\SubscriberCloner;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);
    Storage::fake('local');

    $this->runMerge = fn (MergeListsJob $job) => $job->handle(
        app(CurrentCustomer::class),
        app(SubscriberCloner::class),
        app(BlacklistMatcher::class),
    );

    $this->runClean = fn (CleanListJob $job) => $job->handle(app(CurrentCustomer::class));

    $this->runDedupe = fn (DeduplicateListsJob $job) => $job->handle(app(CurrentCustomer::class));

    $this->emails = fn (EmailList $list): array => $list->subscribers()
        ->orderBy('email')
        ->pluck('email')
        ->all();
});

/*
|--------------------------------------------------------------------------
| F-2.2.13-003 — Merge lists
|--------------------------------------------------------------------------
*/

it('F-2.2.13-003 merges lists, maps custom fields by tag and never resurrects opt-outs', function (): void {
    $target = EmailList::factory()->for($this->customer)->create(['name' => 'Target']);
    $sourceA = EmailList::factory()->for($this->customer)->create(['name' => 'Source A']);
    $sourceB = EmailList::factory()->for($this->customer)->create(['name' => 'Source B']);

    $targetField = ListField::factory()->for($target, 'list')->create(['tag' => 'FNAME', 'label' => 'First name']);
    $sourceField = ListField::factory()->for($sourceA, 'list')->create(['tag' => 'FNAME', 'label' => 'First name']);
    // A tag with no counterpart on the target: mapped values must be skipped,
    // never invented on the target list.
    $orphanField = ListField::factory()->for($sourceA, 'list')->create(['tag' => 'ORPHAN', 'label' => 'Orphan']);

    $moving = Subscriber::factory()->for($sourceA, 'list')->create([
        'email' => 'keep@example.com',
        'status' => SubscriberStatus::Confirmed,
    ]);
    app(FieldValueService::class)->set($moving, $sourceField, 'Ada');
    app(FieldValueService::class)->set($moving, $orphanField, 'dropped');

    Subscriber::factory()->for($sourceA, 'list')->create([
        'email' => 'gone@example.com',
        'status' => SubscriberStatus::Unsubscribed,
    ]);
    Subscriber::factory()->for($sourceA, 'list')->create([
        'email' => 'banned@example.com',
        'status' => SubscriberStatus::Blacklisted,
    ]);
    Subscriber::factory()->for($sourceB, 'list')->create([
        'email' => 'second@example.com',
        'status' => SubscriberStatus::Unconfirmed,
    ]);
    // Suppressed account-wide (D-74) — a merge must not re-add it.
    GlobalBlacklistEmail::query()->create([
        'email' => 'suppressed@example.com',
        'customer_id' => $this->customer->id,
        'source' => GlobalBlacklistEmail::SOURCE_MANUAL,
    ]);
    Subscriber::factory()->for($sourceB, 'list')->create([
        'email' => 'suppressed@example.com',
        'status' => SubscriberStatus::Confirmed,
    ]);

    ($this->runMerge)(new MergeListsJob(
        $this->customer->id,
        [$sourceA->id, $sourceB->id],
        $target->id,
        MergeListsJob::DUPLICATES_SKIP,
        false,
        (string) Str::uuid(),
    ));

    expect(($this->emails)($target))->toBe(['keep@example.com', 'second@example.com'])
        // Sources are untouched when delete_sources is off.
        ->and($sourceA->subscribers()->count())->toBe(3)
        ->and($sourceB->subscribers()->count())->toBe(2);

    $merged = $target->subscribers()->where('email', 'keep@example.com')->firstOrFail();

    expect(app(FieldValueService::class)->get($merged, $targetField))->toBe('Ada')
        ->and($merged->fieldValues()->count())->toBe(1); // ORPHAN skipped
});

it('F-2.2.13-003 applies the chosen duplicate handling on the target list', function (): void {
    $target = EmailList::factory()->for($this->customer)->create(['name' => 'Target']);
    $source = EmailList::factory()->for($this->customer)->create(['name' => 'Source']);

    $targetField = ListField::factory()->for($target, 'list')->create(['tag' => 'FNAME', 'label' => 'First name']);
    $sourceField = ListField::factory()->for($source, 'list')->create(['tag' => 'FNAME', 'label' => 'First name']);

    $existing = Subscriber::factory()->for($target, 'list')->create([
        'email' => 'dupe@example.com',
        'status' => SubscriberStatus::Confirmed,
    ]);
    app(FieldValueService::class)->set($existing, $targetField, 'Old');

    $incoming = Subscriber::factory()->for($source, 'list')->create([
        'email' => 'dupe@example.com',
        'status' => SubscriberStatus::Confirmed,
    ]);
    app(FieldValueService::class)->set($incoming, $sourceField, 'New');

    // skip → the target row keeps its own value.
    ($this->runMerge)(new MergeListsJob(
        $this->customer->id, [$source->id], $target->id,
        MergeListsJob::DUPLICATES_SKIP, false, (string) Str::uuid(),
    ));

    expect($target->subscribers()->count())->toBe(1)
        ->and(app(FieldValueService::class)->get($existing->refresh(), $targetField))->toBe('Old');

    // update → the source's mapped values overwrite the target row's.
    ($this->runMerge)(new MergeListsJob(
        $this->customer->id, [$source->id], $target->id,
        MergeListsJob::DUPLICATES_UPDATE, false, (string) Str::uuid(),
    ));

    expect($target->subscribers()->count())->toBe(1)
        ->and(app(FieldValueService::class)->get($existing->refresh(), $targetField))->toBe('New');
});

it('F-2.2.13-003 deletes and purges the source lists when asked', function (): void {
    Queue::fake();

    $target = EmailList::factory()->for($this->customer)->create(['name' => 'Target']);
    $source = EmailList::factory()->for($this->customer)->create(['name' => 'Source']);

    Subscriber::factory()->for($source, 'list')->create([
        'email' => 'moved@example.com',
        'status' => SubscriberStatus::Confirmed,
    ]);

    ($this->runMerge)(new MergeListsJob(
        $this->customer->id, [$source->id], $target->id,
        MergeListsJob::DUPLICATES_SKIP, true, (string) Str::uuid(),
    ));

    expect(($this->emails)($target))->toBe(['moved@example.com'])
        ->and(EmailList::query()->whereKey($source->id)->exists())->toBeFalse()
        ->and(EmailList::withTrashed()->whereKey($source->id)->firstOrFail()->status)
        ->toBe(EmailList::STATUS_PENDING_DELETE);

    Queue::assertPushed(\App\Jobs\Lists\PurgeListJob::class, 1);
});

it('F-2.2.13-003 refuses to merge a foreign list', function (): void {
    $foreignCustomer = Customer::factory()->create();
    $foreign = EmailList::factory()->for($foreignCustomer)->create(['name' => 'Foreign']);
    Subscriber::factory()->for($foreign, 'list')->create(['email' => 'foreign@example.com']);

    $target = EmailList::factory()->for($this->customer)->create(['name' => 'Mine']);

    ($this->runMerge)(new MergeListsJob(
        $this->customer->id, [$foreign->id], $target->id,
        MergeListsJob::DUPLICATES_SKIP, false, (string) Str::uuid(),
    ));

    expect($target->subscribers()->count())->toBe(0)
        ->and(Subscriber::withoutTenancy()->where('list_id', $foreign->id)->count())->toBe(1);

    // ...and the reverse direction: a foreign target receives nothing.
    $mine = EmailList::factory()->for($this->customer)->create(['name' => 'Source']);
    Subscriber::factory()->for($mine, 'list')->create(['email' => 'mine@example.com']);

    ($this->runMerge)(new MergeListsJob(
        $this->customer->id, [$mine->id], $foreign->id,
        MergeListsJob::DUPLICATES_SKIP, false, (string) Str::uuid(),
    ));

    expect(Subscriber::withoutTenancy()->where('list_id', $foreign->id)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| F-2.2.13-004 — Clean list
|--------------------------------------------------------------------------
*/

it('F-2.2.13-004 cleans only the selected kinds of subscribers', function (): void {
    $list = EmailList::factory()->for($this->customer)->create(['name' => 'Dirty']);

    $hard = Subscriber::factory()->for($list, 'list')->create([
        'email' => 'hard@example.com',
        'status' => SubscriberStatus::Bounced,
        'bounce_type' => 'hard',
    ]);
    $soft = Subscriber::factory()->for($list, 'list')->create([
        'email' => 'soft@example.com',
        'status' => SubscriberStatus::Bounced,
        'bounce_type' => 'soft',
    ]);
    $logged = Subscriber::factory()->for($list, 'list')->create([
        'email' => 'logged@example.com',
        'status' => SubscriberStatus::Confirmed,
    ]);
    CampaignBounceLog::query()->create([
        'customer_id' => $this->customer->id,
        'subscriber_id' => $logged->id,
        'bounce_type' => 'hard',
        'email' => 'logged@example.com',
    ]);
    $stale = Subscriber::factory()->for($list, 'list')->create([
        'email' => 'stale@example.com',
        'status' => SubscriberStatus::Unconfirmed,
        'subscribed_at' => now()->subDays(90),
    ]);
    $fresh = Subscriber::factory()->for($list, 'list')->create([
        'email' => 'fresh@example.com',
        'status' => SubscriberStatus::Unconfirmed,
        'subscribed_at' => now()->subDays(3),
    ]);
    $role = Subscriber::factory()->for($list, 'list')->create([
        'email' => 'info@example.com',
        'status' => SubscriberStatus::Confirmed,
    ]);
    $keep = Subscriber::factory()->for($list, 'list')->create([
        'email' => 'real.person@example.com',
        'status' => SubscriberStatus::Confirmed,
    ]);

    // Only the hard-bounce rule: soft bounces and everything else survive.
    ($this->runClean)(new CleanListJob(
        $this->customer->id, $list->id, [CleanListJob::RULE_HARD_BOUNCES], 30, (string) Str::uuid(),
    ));

    expect(($this->emails)($list))->toBe([
        'fresh@example.com', 'info@example.com', 'real.person@example.com',
        'soft@example.com', 'stale@example.com',
    ]);

    // Unconfirmed older than 30 days.
    ($this->runClean)(new CleanListJob(
        $this->customer->id, $list->id, [CleanListJob::RULE_UNCONFIRMED], 30, (string) Str::uuid(),
    ));

    expect(($this->emails)($list))->toBe([
        'fresh@example.com', 'info@example.com', 'real.person@example.com', 'soft@example.com',
    ]);

    // Role addresses, detected by the built-in verification engine (D-12).
    ($this->runClean)(new CleanListJob(
        $this->customer->id, $list->id, [CleanListJob::RULE_ROLE_ADDRESSES], 30, (string) Str::uuid(),
    ));

    expect(($this->emails)($list))->toBe([
        'fresh@example.com', 'real.person@example.com', 'soft@example.com',
    ]);

    // Removals are soft deletes, so nothing is lost.
    expect(Subscriber::withoutTenancy()->withTrashed()->whereKey([
        $hard->id, $logged->id, $stale->id, $role->id,
    ])->whereNotNull('deleted_at')->count())->toBe(4)
        ->and(Subscriber::withoutTenancy()->whereKey([$soft->id, $fresh->id, $keep->id])->count())->toBe(3);
});

it('F-2.2.13-004 removes duplicate addresses keeping the earliest row', function (): void {
    $list = EmailList::factory()->for($this->customer)->create(['name' => 'Dupes']);

    $first = Subscriber::factory()->for($list, 'list')->create(['email' => 'dupe@example.com']);

    // The (list_id, email) unique index blocks an identical second row, so the
    // reachable duplicate is a case-differing one written past the model.
    $secondId = (int) Subscriber::withoutTenancy()->insertGetId([
        'uid' => (string) Str::uuid(),
        'customer_id' => $this->customer->id,
        'list_id' => $list->id,
        'email' => 'DUPE@example.com',
        'status' => SubscriberStatus::Confirmed->value,
        'source' => 'manual',
        'subscribed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($list->subscribers()->count())->toBe(2);

    ($this->runClean)(new CleanListJob(
        $this->customer->id, $list->id, [CleanListJob::RULE_DUPLICATES], 0, (string) Str::uuid(),
    ));

    expect($list->subscribers()->pluck('id')->all())->toBe([$first->id])
        ->and(Subscriber::withoutTenancy()->withTrashed()->whereKey($secondId)->firstOrFail()->deleted_at)
        ->not->toBeNull();
});

it('F-2.2.13-004 refuses to clean a foreign list', function (): void {
    $foreignCustomer = Customer::factory()->create();
    $foreign = EmailList::factory()->for($foreignCustomer)->create();
    Subscriber::factory()->for($foreign, 'list')->create([
        'email' => 'info@foreign.example',
        'status' => SubscriberStatus::Bounced,
        'bounce_type' => 'hard',
    ]);

    ($this->runClean)(new CleanListJob(
        $this->customer->id, $foreign->id, CleanListJob::RULES, 1, (string) Str::uuid(),
    ));

    expect(Subscriber::withoutTenancy()->where('list_id', $foreign->id)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| F-2.2.13-006 — Deduplicate across lists
|--------------------------------------------------------------------------
*/

it('F-2.2.13-006 keeps the earliest-subscribed row per address across lists', function (): void {
    $a = EmailList::factory()->for($this->customer)->create(['name' => 'A']);
    $b = EmailList::factory()->for($this->customer)->create(['name' => 'B']);
    $c = EmailList::factory()->for($this->customer)->create(['name' => 'C']);
    $untouched = EmailList::factory()->for($this->customer)->create(['name' => 'Not selected']);

    $oldest = Subscriber::factory()->for($b, 'list')->create([
        'email' => 'dupe@example.com',
        'subscribed_at' => now()->subDays(30),
    ]);
    $middle = Subscriber::factory()->for($a, 'list')->create([
        'email' => 'dupe@example.com',
        'subscribed_at' => now()->subDays(10),
    ]);
    $newest = Subscriber::factory()->for($c, 'list')->create([
        'email' => 'dupe@example.com',
        'subscribed_at' => now()->subDay(),
    ]);
    $outside = Subscriber::factory()->for($untouched, 'list')->create([
        'email' => 'dupe@example.com',
        'subscribed_at' => now()->subDays(60),
    ]);
    $unique = Subscriber::factory()->for($a, 'list')->create([
        'email' => 'only-once@example.com',
        'subscribed_at' => now()->subDays(5),
    ]);

    ($this->runDedupe)(new DeduplicateListsJob(
        $this->customer->id, [$a->id, $b->id, $c->id], null, (string) Str::uuid(),
    ));

    expect(Subscriber::withoutTenancy()->whereKey($oldest->id)->exists())->toBeTrue()
        ->and(Subscriber::withoutTenancy()->whereKey($middle->id)->exists())->toBeFalse()
        ->and(Subscriber::withoutTenancy()->whereKey($newest->id)->exists())->toBeFalse()
        // The unselected list is never touched, even though it holds the
        // globally oldest row.
        ->and(Subscriber::withoutTenancy()->whereKey($outside->id)->exists())->toBeTrue()
        ->and(Subscriber::withoutTenancy()->whereKey($unique->id)->exists())->toBeTrue();
});

it('F-2.2.13-006 keeps the master list row when a master is chosen', function (): void {
    $master = EmailList::factory()->for($this->customer)->create(['name' => 'Master']);
    $other = EmailList::factory()->for($this->customer)->create(['name' => 'Other']);

    $inMaster = Subscriber::factory()->for($master, 'list')->create([
        'email' => 'dupe@example.com',
        'subscribed_at' => now()->subDay(), // newest — the master rule still wins
    ]);
    $inOther = Subscriber::factory()->for($other, 'list')->create([
        'email' => 'dupe@example.com',
        'subscribed_at' => now()->subDays(40),
    ]);
    // Address absent from the master → falls back to the earliest-subscribed rule.
    $onlyElsewhereOld = Subscriber::factory()->for($other, 'list')->create([
        'email' => 'elsewhere@example.com',
        'subscribed_at' => now()->subDays(20),
    ]);

    ($this->runDedupe)(new DeduplicateListsJob(
        $this->customer->id, [$master->id, $other->id], $master->id, (string) Str::uuid(),
    ));

    expect(Subscriber::withoutTenancy()->whereKey($inMaster->id)->exists())->toBeTrue()
        ->and(Subscriber::withoutTenancy()->whereKey($inOther->id)->exists())->toBeFalse()
        ->and(Subscriber::withoutTenancy()->whereKey($onlyElsewhereOld->id)->exists())->toBeTrue();
});

it('F-2.2.13-006 refuses to deduplicate across a foreign list', function (): void {
    $foreignCustomer = Customer::factory()->create();
    $foreign = EmailList::factory()->for($foreignCustomer)->create();
    $foreignRow = Subscriber::factory()->for($foreign, 'list')->create([
        'email' => 'dupe@example.com',
        'subscribed_at' => now()->subDays(99),
    ]);

    $mine = EmailList::factory()->for($this->customer)->create();
    $mineRow = Subscriber::factory()->for($mine, 'list')->create([
        'email' => 'dupe@example.com',
        'subscribed_at' => now()->subDay(),
    ]);

    ($this->runDedupe)(new DeduplicateListsJob(
        $this->customer->id, [$mine->id, $foreign->id], $foreign->id, (string) Str::uuid(),
    ));

    // Only one of the two lists resolved → nothing to compare, both survive.
    expect(Subscriber::withoutTenancy()->whereKey($foreignRow->id)->exists())->toBeTrue()
        ->and(Subscriber::withoutTenancy()->whereKey($mineRow->id)->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| F-2.2.13-005 — Email verification integration (list-level hook)
|--------------------------------------------------------------------------
*/

it('F-2.2.13-005 queues a list-target verification run from the list tools', function (): void {
    Queue::fake();

    $list = EmailList::factory()->for($this->customer)->create();

    Livewire::test(ListEmailLists::class)
        ->callTableAction('verify', $list, data: [
            'provider' => 'zerobounce',
            'auto_action' => VerificationJob::ACTION_BLACKLIST,
        ])
        ->assertHasNoTableActionErrors();

    $job = VerificationJob::query()->sole();

    expect($job->target)->toBe(VerificationJob::TARGET_LIST)
        ->and((int) $job->list_id)->toBe($list->id)
        ->and($job->provider)->toBe('zerobounce')
        ->and($job->auto_action)->toBe(VerificationJob::ACTION_BLACKLIST)
        ->and($job->status)->toBe(VerificationJob::STATUS_PENDING);

    Queue::assertPushed(VerifyBatchJob::class, fn (VerifyBatchJob $pushed): bool => $pushed->verificationJobId === (int) $job->id);
});

it('F-2.2.13-005 verifies the list through the registry and applies the D-75 auto-action', function (): void {
    config()->set('emp-verification.providers.zerobounce.api_key', 'test-key');

    \Illuminate\Support\Facades\Http::fake([
        'api.zerobounce.net/*' => \Illuminate\Support\Facades\Http::response([
            'address' => 'dead@example.com',
            'status' => 'invalid',
            'sub_status' => 'mailbox_not_found',
        ]),
    ]);

    $list = EmailList::factory()->for($this->customer)->create();
    $dead = Subscriber::factory()->for($list, 'list')->create([
        'email' => 'dead@example.com',
        'status' => SubscriberStatus::Confirmed,
    ]);

    $run = VerificationJob::create([
        'customer_id' => $this->customer->id,
        'list_id' => $list->id,
        'target' => VerificationJob::TARGET_LIST,
        'provider' => 'zerobounce',
        'auto_action' => VerificationJob::ACTION_UNSUBSCRIBE,
        'status' => VerificationJob::STATUS_PENDING,
    ]);

    (new VerifyBatchJob((int) $run->id))->handle(app(CurrentCustomer::class));

    expect($dead->refresh()->verification_status)->toBe(VerificationStatus::Undeliverable)
        ->and($dead->status)->toBe(SubscriberStatus::Unsubscribed)
        ->and($run->refresh()->status)->toBe(VerificationJob::STATUS_COMPLETED)
        ->and($run->undeliverable_count)->toBe(1);
});

it('F-2.2.13-005 hides the verify action on a foreign list', function (): void {
    $foreign = EmailList::factory()->create();

    Livewire::test(ListEmailLists::class)
        ->assertCanNotSeeTableRecords([$foreign])
        ->assertTableActionHidden('verify', $foreign);

    expect(VerificationJob::withoutTenancy()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Table wiring — every bulk tool is queued (docs/ARCHITECTURE.md rule 5)
|--------------------------------------------------------------------------
*/

it('queues the merge, clean and deduplicate tools from the list table', function (): void {
    Queue::fake();

    $target = EmailList::factory()->for($this->customer)->create(['name' => 'Target']);
    $source = EmailList::factory()->for($this->customer)->create(['name' => 'Source']);

    Livewire::test(ListEmailLists::class)
        ->callTableAction('clean', $source, data: [
            'rules' => [
                CleanListJob::RULE_HARD_BOUNCES,
                CleanListJob::RULE_UNCONFIRMED,
                CleanListJob::RULE_ROLE_ADDRESSES,
            ],
            'unconfirmed_days' => 45,
        ])
        ->assertHasNoTableActionErrors()
        ->callTableBulkAction('merge', [$source, $target], data: [
            'target_list_id' => $target->id,
            'duplicate_handling' => MergeListsJob::DUPLICATES_UPDATE,
            'delete_sources' => false,
        ])
        ->callTableBulkAction('deduplicate', [$source, $target], data: [
            'master_list_id' => $target->id,
        ]);

    Queue::assertPushed(CleanListJob::class, fn (CleanListJob $job): bool => $job->listId === $source->id
        && $job->rules === [
            CleanListJob::RULE_HARD_BOUNCES,
            CleanListJob::RULE_UNCONFIRMED,
            CleanListJob::RULE_ROLE_ADDRESSES,
        ]
        && $job->unconfirmedDays === 45);

    Queue::assertPushed(MergeListsJob::class, fn (MergeListsJob $job): bool => $job->targetListId === $target->id
        && $job->sourceListIds === [$source->id]
        && $job->duplicateHandling === MergeListsJob::DUPLICATES_UPDATE
        && $job->deleteSources === false);

    Queue::assertPushed(DeduplicateListsJob::class, function (DeduplicateListsJob $job) use ($source, $target): bool {
        $ids = $job->listIds;
        sort($ids);
        $expected = [$source->id, $target->id];
        sort($expected);

        return $job->masterListId === $target->id && $ids === $expected;
    });
});
