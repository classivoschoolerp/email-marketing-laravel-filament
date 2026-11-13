<?php

declare(strict_types=1);

use App\Enums\BounceAction;
use App\Enums\SubscriberStatus;
use App\Models\BounceServer;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\FblServer;
use App\Models\GlobalBlacklistEmail;
use App\Models\Subscriber;
use App\Services\Blacklist\BlacklistMatcher;
use App\Services\Bounces\BounceProcessor;
use App\Services\Fbl\FblProcessor;
use App\Services\Mailboxes\MailboxClientInterface;
use Tests\Fakes\FakeMailbox;

/*
 * WP-16 F-3.17-007 — auto-blacklist rules, verified against the WP-15
 * processors (read-only): hard bounce → source=bounce, complaint (FBL) →
 * source=fbl, N soft bounces → per-server configurable threshold + action.
 * Plus the WP-16 half: those rows immediately bind the enforcement matcher.
 */

function wp16_bounce_fixture(string $name, array $replacements = []): string
{
    $raw = (string) file_get_contents(base_path('tests/Fixtures/bounces/'.$name));

    return str_replace(array_keys($replacements), array_values($replacements), $raw);
}

/** @return array{0: Customer, 1: Subscriber, 2: Campaign} */
function wp16_bounce_subscriber(string $email): array
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

function wp16_mailbox(array $messages): FakeMailbox
{
    $fake = new FakeMailbox($messages);
    app()->instance(MailboxClientInterface::class, $fake);

    return $fake;
}

it('auto-blacklists hard bounces (source=bounce) and the row binds the matcher', function (): void {
    [$customer, $subscriber, $campaign] = wp16_bounce_subscriber('hard@dest.example');

    $server = BounceServer::factory()->system()->create(['hard_bounce_action' => BounceAction::Blacklist]);
    wp16_mailbox([
        1 => wp16_bounce_fixture('dsn-550-user-unknown.eml', [
            '{{MESSAGE_ID}}' => 'wp16-msg-1@platform.test',
            '{{CAMPAIGN_UID}}' => $campaign->uid,
            '{{SUBSCRIBER_UID}}' => $subscriber->uid,
        ]),
    ]);

    app(BounceProcessor::class)->process($server);

    $entry = GlobalBlacklistEmail::query()->where('email', 'hard@dest.example')->firstOrFail();
    expect($entry->source)->toBe(GlobalBlacklistEmail::SOURCE_BOUNCE)
        ->and($entry->customer_id)->toBe($customer->id);

    // WP-16 enforcement: the auto-row now blocks import + send matching.
    expect(app(BlacklistMatcher::class)->matchType('hard@dest.example', $customer->id))
        ->toBe(BlacklistMatcher::MATCH_EXACT);
});

it('auto-blacklists FBL complaints (source=fbl)', function (): void {
    [$customer, $subscriber, $campaign] = wp16_bounce_subscriber('complainer@dest.example');

    $server = FblServer::factory()->system()->create();
    wp16_mailbox([
        1 => wp16_bounce_fixture('arf-abuse.eml', [
            '{{MESSAGE_ID}}' => 'wp16-arf-1@platform.test',
            '{{CAMPAIGN_UID}}' => $campaign->uid,
            '{{SUBSCRIBER_UID}}' => $subscriber->uid,
        ]),
    ]);

    app(FblProcessor::class)->process($server);

    $entry = GlobalBlacklistEmail::query()->where('email', 'complainer@dest.example')->firstOrFail();
    expect($entry->source)->toBe(GlobalBlacklistEmail::SOURCE_FBL)
        ->and(app(BlacklistMatcher::class)->matches('complainer@dest.example', $customer->id))->toBeTrue();
});

it('auto-blacklists after N soft bounces per the configurable threshold', function (): void {
    [, $subscriber] = wp16_bounce_subscriber('soft@dest.example');
    $subscriber->forceFill(['bounces_count' => 2])->saveQuietly();

    // Threshold 3, action = blacklist (the configurable "N soft bounces").
    $server = BounceServer::factory()->system()->create([
        'soft_bounce_threshold' => 3,
        'soft_bounce_action' => BounceAction::Blacklist,
    ]);
    wp16_mailbox([
        1 => wp16_bounce_fixture('dsn-452-mailbox-full.eml', [
            '{{MESSAGE_ID}}' => 'wp16-msg-2@platform.test',
            '{{SUBSCRIBER_UID}}' => $subscriber->uid,
        ]),
    ]);

    app(BounceProcessor::class)->process($server);

    expect($subscriber->refresh()->bounces_count)->toBe(3)
        ->and(GlobalBlacklistEmail::query()->where('email', 'soft@dest.example')->value('source'))
        ->toBe(GlobalBlacklistEmail::SOURCE_BOUNCE);
});

it('does not blacklist soft bounces below the threshold', function (): void {
    [, $subscriber] = wp16_bounce_subscriber('under@dest.example');

    $server = BounceServer::factory()->system()->create([
        'soft_bounce_threshold' => 3,
        'soft_bounce_action' => BounceAction::Blacklist,
    ]);
    wp16_mailbox([
        1 => wp16_bounce_fixture('dsn-452-mailbox-full.eml', [
            '{{MESSAGE_ID}}' => 'wp16-msg-3@platform.test',
            '{{SUBSCRIBER_UID}}' => $subscriber->uid,
        ]),
    ]);

    app(BounceProcessor::class)->process($server);

    expect($subscriber->refresh()->bounces_count)->toBe(1)
        ->and(GlobalBlacklistEmail::query()->count())->toBe(0);
});
