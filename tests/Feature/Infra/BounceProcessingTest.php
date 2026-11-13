<?php

declare(strict_types=1);

use App\Enums\BounceAction;
use App\Enums\BounceType;
use App\Enums\SubscriberStatus;
use App\Models\BounceRule;
use App\Models\BounceServer;
use App\Models\Campaign;
use App\Models\CampaignBounceLog;
use App\Models\CronRun;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\GlobalBlacklistEmail;
use App\Models\Subscriber;
use App\Services\Bounces\BounceClassifier;
use App\Services\Bounces\BounceProcessor;
use App\Services\Bounces\ParsedBounce;
use App\Services\Mailboxes\MailboxClientInterface;
use App\Settings\CronSettings;
use Illuminate\Support\Facades\Schema;
use Tests\Fakes\FakeMailbox;

function infra_fixture(string $name, array $replacements = []): string
{
    $raw = (string) file_get_contents(base_path('tests/Fixtures/bounces/'.$name));

    return str_replace(array_keys($replacements), array_values($replacements), $raw);
}

/** @return array{0: Customer, 1: Subscriber, 2: Campaign} */
function infra_subscriber(string $email = 'victim@dest.example'): array
{
    $customer = Customer::factory()->create();
    $list = EmailList::factory()->for($customer)->create();
    $subscriber = Subscriber::factory()
        ->for($customer)
        ->for($list, 'list')
        ->create(['email' => $email, 'status' => SubscriberStatus::Confirmed]);
    $campaign = Campaign::factory()->create([
        'customer_id' => $customer->id,
        'email_list_id' => $list->id,
    ]);

    return [$customer, $subscriber, $campaign];
}

function bounce_mailbox(array $messages): FakeMailbox
{
    $fake = new FakeMailbox($messages);
    app()->instance(MailboxClientInterface::class, $fake);

    return $fake;
}

// ---------------------------------------------------------------------------
// F-2.9.4-006 — classification matrix through the seeded bounce_rules
// ---------------------------------------------------------------------------
it('classifies hard, soft and internal via the seeded rules', function (string $haystack, BounceType $expected): void {
    $bounce = new ParsedBounce(isDsn: false, excerpt: $haystack);

    expect(app(BounceClassifier::class)->classify($bounce))->toBe($expected);
})->with([
    'user unknown → hard' => ['550 5.1.1 Recipient rejected: User unknown', BounceType::Hard],
    'status 5.x → hard' => ["Action: failed\nStatus: 5.7.1", BounceType::Hard],
    'mailbox full → soft' => ['452 4.2.2 Mailbox full', BounceType::Soft],
    'greylist → soft' => ['451 Greylisted, try again later', BounceType::Soft],
    'status 4.x → soft' => ["status: 4.4.1\nconnection refused", BounceType::Soft],
    'out of office → internal' => ['I am out of the office until Monday', BounceType::Internal],
    'auto-submitted → internal' => ['Auto-Submitted: auto-replied', BounceType::Internal],
    'unmatched chatter → internal' => ['completely unrelated message text', BounceType::Internal],
]);

it('honours custom rules, ordering and the enabled flag', function (): void {
    $haystack = 'the mailbox has exploded'; // matches nothing seeded

    $bounce = new ParsedBounce(isDsn: false, excerpt: $haystack);
    expect(app(BounceClassifier::class)->classify($bounce))->toBe(BounceType::Internal);

    // Custom rule classifies it hard...
    $rule = BounceRule::factory()->create(['pattern' => 'exploded', 'type' => BounceType::Hard, 'sort_order' => 5]);
    expect(app(BounceClassifier::class)->classify($bounce))->toBe(BounceType::Hard);

    // ...an earlier (lower sort_order) rule wins...
    BounceRule::factory()->create(['pattern' => 'exploded', 'type' => BounceType::Soft, 'sort_order' => 1]);
    expect(app(BounceClassifier::class)->classify($bounce))->toBe(BounceType::Soft);

    // ...and disabled rules are skipped.
    BounceRule::query()->update(['enabled' => false]);
    $rule->refresh();
    expect(app(BounceClassifier::class)->classify($bounce))->toBe(BounceType::Internal);
});

// ---------------------------------------------------------------------------
// F-2.9.4-007 — hard bounce: status bounced + fields + per-server action
// ---------------------------------------------------------------------------
it('marks the subscriber bounced on a hard bounce with x-emp attribution', function (): void {
    [, $subscriber, $campaign] = infra_subscriber();

    $server = BounceServer::factory()->system()->create();
    $mailbox = bounce_mailbox([
        1 => infra_fixture('dsn-550-user-unknown.eml', [
            '{{MESSAGE_ID}}' => 'msg-1@platform.test',
            '{{CAMPAIGN_UID}}' => $campaign->uid,
            '{{SUBSCRIBER_UID}}' => $subscriber->uid,
        ]),
    ]);

    $processed = app(BounceProcessor::class)->process($server);

    expect($processed)->toBe(1);

    $subscriber->refresh();
    expect($subscriber->status)->toBe(SubscriberStatus::Bounced)
        ->and($subscriber->bounces_count)->toBe(1)
        ->and($subscriber->last_bounce_at)->not->toBeNull();

    $log = CampaignBounceLog::query()->firstOrFail();
    expect($log->bounce_type)->toBe(BounceType::Hard)
        ->and($log->campaign_id)->toBe($campaign->id)
        ->and($log->subscriber_id)->toBe($subscriber->id)
        ->and($log->customer_id)->toBe($subscriber->customer_id)
        ->and($log->bounce_server_id)->toBe($server->id)
        ->and($log->email)->toBe('victim@dest.example')
        ->and($log->message_id)->toBe('msg-1@platform.test')
        ->and($log->message)->not->toBeEmpty();

    // F-2.9.4-002 delete-after-processing + F-2.9.4-009 last_run_at
    expect($mailbox->deleted)->toBe([1])
        ->and($server->refresh()->last_run_at)->not->toBeNull();
});

it('applies the configured blacklist action on hard bounces', function (): void {
    [, $subscriber, $campaign] = infra_subscriber();

    $server = BounceServer::factory()->system()->create(['hard_bounce_action' => BounceAction::Blacklist]);
    bounce_mailbox([
        1 => infra_fixture('dsn-550-user-unknown.eml', [
            '{{MESSAGE_ID}}' => 'msg-2@platform.test',
            '{{CAMPAIGN_UID}}' => $campaign->uid,
            '{{SUBSCRIBER_UID}}' => $subscriber->uid,
        ]),
    ]);

    app(BounceProcessor::class)->process($server);

    expect($subscriber->refresh()->status)->toBe(SubscriberStatus::Bounced);

    $entry = GlobalBlacklistEmail::query()->where('email', 'victim@dest.example')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->source)->toBe('bounce');
});

it('applies the configured unsubscribe action on hard bounces', function (): void {
    [, $subscriber, $campaign] = infra_subscriber();

    $server = BounceServer::factory()->system()->create(['hard_bounce_action' => BounceAction::Unsubscribe]);
    bounce_mailbox([
        1 => infra_fixture('dsn-550-user-unknown.eml', [
            '{{MESSAGE_ID}}' => 'msg-3@platform.test',
            '{{CAMPAIGN_UID}}' => $campaign->uid,
            '{{SUBSCRIBER_UID}}' => $subscriber->uid,
        ]),
    ]);

    app(BounceProcessor::class)->process($server);

    $subscriber->refresh();
    expect($subscriber->status)->toBe(SubscriberStatus::Unsubscribed)
        ->and($subscriber->unsubscribed_at)->not->toBeNull()
        ->and($subscriber->bounces_count)->toBe(1);
});

it('applies the configured delete action on hard bounces', function (): void {
    [, $subscriber, $campaign] = infra_subscriber();

    $server = BounceServer::factory()->system()->create(['hard_bounce_action' => BounceAction::Delete]);
    bounce_mailbox([
        1 => infra_fixture('dsn-550-user-unknown.eml', [
            '{{MESSAGE_ID}}' => 'msg-4@platform.test',
            '{{CAMPAIGN_UID}}' => $campaign->uid,
            '{{SUBSCRIBER_UID}}' => $subscriber->uid,
        ]),
    ]);

    app(BounceProcessor::class)->process($server);

    expect(Subscriber::withoutTenancy()->find($subscriber->id))->toBeNull()
        ->and(Subscriber::withoutTenancy()->withTrashed()->find($subscriber->id))->not->toBeNull();
});

// ---------------------------------------------------------------------------
// F-2.9.4-008 — N-soft-bounces threshold
// ---------------------------------------------------------------------------
it('records soft bounces without state change until the threshold hits', function (): void {
    [, $subscriber] = infra_subscriber('full@dest.example');

    $server = BounceServer::factory()->system()->create(['soft_bounce_threshold' => 3]);
    bounce_mailbox([
        1 => infra_fixture('dsn-452-mailbox-full.eml', [
            '{{MESSAGE_ID}}' => 'msg-5@platform.test',
            '{{SUBSCRIBER_UID}}' => $subscriber->uid,
        ]),
    ]);

    app(BounceProcessor::class)->process($server);

    $subscriber->refresh();
    expect($subscriber->status)->toBe(SubscriberStatus::Confirmed) // still active
        ->and($subscriber->bounces_count)->toBe(1)
        ->and($subscriber->last_bounce_at)->not->toBeNull();

    expect(CampaignBounceLog::query()->value('bounce_type'))->toBe(BounceType::Soft);
});

it('applies the soft-bounce action once the threshold is reached', function (): void {
    [, $subscriber] = infra_subscriber('full@dest.example');
    $subscriber->forceFill(['bounces_count' => 2])->saveQuietly();

    $server = BounceServer::factory()->system()->create(['soft_bounce_threshold' => 3]);
    bounce_mailbox([
        1 => infra_fixture('dsn-452-mailbox-full.eml', [
            '{{MESSAGE_ID}}' => 'msg-6@platform.test',
            '{{SUBSCRIBER_UID}}' => $subscriber->uid,
        ]),
    ]);

    app(BounceProcessor::class)->process($server);

    $subscriber->refresh();
    expect($subscriber->bounces_count)->toBe(3)
        ->and($subscriber->status)->toBe(SubscriberStatus::Bounced);
});

// ---------------------------------------------------------------------------
// Internal bounces & heuristics
// ---------------------------------------------------------------------------
it('logs auto-replies as internal without touching any subscriber', function (): void {
    [, $subscriber] = infra_subscriber();

    $server = BounceServer::factory()->system()->create();
    bounce_mailbox([1 => infra_fixture('auto-reply.eml')]);

    app(BounceProcessor::class)->process($server);

    expect($subscriber->refresh()->status)->toBe(SubscriberStatus::Confirmed)
        ->and(CampaignBounceLog::query()->value('bounce_type'))->toBe(BounceType::Internal);
});

it('attributes a heuristic bounce by recipient email lookup', function (): void {
    [, $subscriber] = infra_subscriber('grey@dest.example');

    $server = BounceServer::factory()->system()->create();
    bounce_mailbox([1 => infra_fixture('greylist-non-dsn.eml')]);

    app(BounceProcessor::class)->process($server);

    $log = CampaignBounceLog::query()->firstOrFail();
    expect($log->subscriber_id)->toBe($subscriber->id)
        ->and($log->bounce_type)->toBe(BounceType::Soft)
        ->and($subscriber->refresh()->bounces_count)->toBe(1);
});

// VERP/Message-ID mapping through WP-13's campaign_delivery_logs (guarded)
it('attributes via the delivery log message id when the parallel table exists', function (): void {
    [, $subscriber, $campaign] = infra_subscriber('unknown@dest.example');

    // WP-13's table (already merged here); the fallback keeps this test
    // honest in environments where the parallel package has not landed yet.
    if (! Schema::hasTable('campaign_delivery_logs')) {
        Schema::create('campaign_delivery_logs', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->unsignedBigInteger('subscriber_id')->nullable();
            $table->string('status', 16)->default('sent');
            $table->string('message_id')->nullable();
        });
    }

    DB::table('campaign_delivery_logs')->insert([
        'campaign_id' => $campaign->id,
        'subscriber_id' => $subscriber->id,
        'status' => 'sent',
        'message_id' => 'msg-log-7@platform.test',
    ]);

    $server = BounceServer::factory()->system()->create();
    bounce_mailbox([
        1 => infra_fixture('dsn-550-message-id-only.eml', [
            '{{MESSAGE_ID}}' => 'msg-log-7@platform.test',
        ]),
    ]);

    app(BounceProcessor::class)->process($server);

    $log = CampaignBounceLog::query()->firstOrFail();
    expect($log->campaign_id)->toBe($campaign->id)
        ->and($log->subscriber_id)->toBe($subscriber->id)
        ->and($subscriber->refresh()->status)->toBe(SubscriberStatus::Bounced);
});

it('keeps messages in the mailbox when delete-after-processing is off', function (): void {
    $server = BounceServer::factory()->system()->create(['delete_after_processing' => false]);
    $mailbox = bounce_mailbox([1 => infra_fixture('auto-reply.eml')]);

    app(BounceProcessor::class)->process($server);

    expect($mailbox->deleted)->toBe([])
        ->and($mailbox->disconnects)->toBe(1);
});

it('honours the max-per-batch cap', function (): void {
    $server = BounceServer::factory()->system()->create(['max_per_batch' => 1]);
    $mailbox = bounce_mailbox([
        1 => infra_fixture('auto-reply.eml'),
        2 => infra_fixture('auto-reply.eml'),
    ]);

    $processed = app(BounceProcessor::class)->process($server);

    expect($processed)->toBe(1)
        ->and($mailbox->deleted)->toBe([1]);
});

// ---------------------------------------------------------------------------
// bounces:process command (D-40 cron wrapper + toggle)
// ---------------------------------------------------------------------------
it('runs bounces:process for active servers only and records the cron run', function (): void {
    BounceServer::factory()->system()->create();
    BounceServer::factory()->system()->inactive()->create();

    bounce_mailbox([1 => infra_fixture('auto-reply.eml')]);

    $this->artisan('bounces:process')->assertSuccessful();

    $run = CronRun::query()->where('job', 'bounces:process')->firstOrFail();
    expect($run->status)->toBe(CronRun::STATUS_SUCCESS)
        ->and($run->processed)->toBe(1); // one active server, one message

    // Only the active server was polled.
    expect(BounceServer::withoutTenancy()->whereNotNull('last_run_at')->count())->toBe(1);
});

it('skips bounces:process when the job is disabled in cron settings', function (): void {
    $settings = app(CronSettings::class);
    $settings->enabled_jobs = array_values(array_diff($settings->enabled_jobs, ['bounces:process']));
    $settings->save();

    BounceServer::factory()->system()->create();
    $mailbox = bounce_mailbox([1 => infra_fixture('auto-reply.eml')]);

    $this->artisan('bounces:process')->assertSuccessful();

    expect(CronRun::query()->where('job', 'bounces:process')->exists())->toBeFalse()
        ->and($mailbox->connected)->toBeFalse()
        ->and($mailbox->deleted)->toBe([]);
});

it('continues past a failing server and still succeeds', function (): void {
    BounceServer::factory()->system()->create();

    $mailbox = bounce_mailbox([]);
    $mailbox->failConnect = true;

    $this->artisan('bounces:process')->assertSuccessful();

    $run = CronRun::query()->where('job', 'bounces:process')->firstOrFail();
    expect($run->status)->toBe(CronRun::STATUS_SUCCESS)
        ->and($run->processed)->toBe(0);
});
