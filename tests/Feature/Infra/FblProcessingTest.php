<?php

declare(strict_types=1);

use App\Enums\SubscriberStatus;
use App\Models\Campaign;
use App\Models\CampaignComplaintLog;
use App\Models\CronRun;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\FblServer;
use App\Models\GlobalBlacklistEmail;
use App\Models\Subscriber;
use App\Services\Fbl\FblProcessor;
use App\Services\Mailboxes\MailboxClientInterface;
use App\Settings\CronSettings;
use Tests\Fakes\FakeMailbox;

function fbl_fixture(string $name, array $replacements = []): string
{
    $raw = (string) file_get_contents(base_path('tests/Fixtures/bounces/'.$name));

    return str_replace(array_keys($replacements), array_values($replacements), $raw);
}

/** @return array{0: Subscriber, 1: Campaign} */
function fbl_subscriber(): array
{
    $customer = Customer::factory()->create();
    $list = EmailList::factory()->for($customer)->create();
    $subscriber = Subscriber::factory()
        ->for($customer)
        ->for($list, 'list')
        ->create(['email' => 'complainer@dest.example', 'status' => SubscriberStatus::Confirmed]);
    $campaign = Campaign::factory()->create([
        'customer_id' => $customer->id,
        'email_list_id' => $list->id,
    ]);

    return [$subscriber, $campaign];
}

function fbl_mailbox(array $messages): FakeMailbox
{
    $fake = new FakeMailbox($messages);
    app()->instance(MailboxClientInterface::class, $fake);

    return $fake;
}

// F-2.9.5-002/003/004 — ARF parsing + default action + complaint log
it('unsubscribes and blacklists the complaining subscriber from an arf report', function (): void {
    [$subscriber, $campaign] = fbl_subscriber();

    $server = FblServer::factory()->system()->create();
    $mailbox = fbl_mailbox([
        1 => fbl_fixture('arf-abuse.eml', [
            '{{MESSAGE_ID}}' => 'msg-arf-10@platform.test',
            '{{CAMPAIGN_UID}}' => $campaign->uid,
            '{{SUBSCRIBER_UID}}' => $subscriber->uid,
        ]),
    ]);

    $processed = app(FblProcessor::class)->process($server);

    expect($processed)->toBe(1);

    // Default complaint action (F-2.9.5-003): unsubscribe THROUGH the model...
    $subscriber->refresh();
    expect($subscriber->status)->toBe(SubscriberStatus::Unsubscribed)
        ->and($subscriber->unsubscribed_at)->not->toBeNull()
        ->and($subscriber->complaints_count)->toBe(1);

    // ...plus global blacklist with source=fbl.
    $entry = GlobalBlacklistEmail::query()->where('email', 'complainer@dest.example')->firstOrFail();
    expect($entry->source)->toBe('fbl');

    // F-2.9.5-004 — complaint log per campaign/subscriber
    $log = CampaignComplaintLog::query()->firstOrFail();
    expect($log->campaign_id)->toBe($campaign->id)
        ->and($log->subscriber_id)->toBe($subscriber->id)
        ->and($log->customer_id)->toBe($subscriber->customer_id)
        ->and($log->fbl_server_id)->toBe($server->id)
        ->and($log->feedback_type)->toBe('abuse')
        ->and($log->message_id)->toBe('msg-arf-10@platform.test');

    expect($mailbox->deleted)->toBe([1])
        ->and($server->refresh()->last_run_at)->not->toBeNull();
});

it('skips non-arf mail without touching subscribers or logs', function (): void {
    [$subscriber] = fbl_subscriber();

    $server = FblServer::factory()->system()->create();
    fbl_mailbox([1 => fbl_fixture('auto-reply.eml')]);

    app(FblProcessor::class)->process($server);

    expect($subscriber->refresh()->status)->toBe(SubscriberStatus::Confirmed)
        ->and(CampaignComplaintLog::query()->count())->toBe(0)
        ->and(GlobalBlacklistEmail::query()->count())->toBe(0);
});

it('honours delete-after-processing off for fbl mailboxes', function (): void {
    fbl_subscriber();

    $server = FblServer::factory()->system()->create(['delete_after_processing' => false]);
    $mailbox = fbl_mailbox([1 => fbl_fixture('arf-abuse.eml', [
        '{{MESSAGE_ID}}' => 'msg-arf-11@platform.test',
        '{{CAMPAIGN_UID}}' => 'nope',
        '{{SUBSCRIBER_UID}}' => 'nope',
    ])]);

    app(FblProcessor::class)->process($server);

    expect($mailbox->deleted)->toBe([]);
});

it('attributes a complaint by recipient email when headers are missing', function (): void {
    [$subscriber] = fbl_subscriber();

    $server = FblServer::factory()->system()->create();
    fbl_mailbox([1 => fbl_fixture('arf-abuse.eml', [
        '{{MESSAGE_ID}}' => 'msg-arf-12@platform.test',
        // X-EMP headers reference nothing that exists
        '{{CAMPAIGN_UID}}' => 'unknown-uid',
        '{{SUBSCRIBER_UID}}' => 'unknown-uid',
    ])]);

    app(FblProcessor::class)->process($server);

    // Fell back to Original-Rcpt-To → email lookup.
    expect($subscriber->refresh()->status)->toBe(SubscriberStatus::Unsubscribed)
        ->and(CampaignComplaintLog::query()->value('subscriber_id'))->toBe($subscriber->id);
});

// fbl:process command (D-40 wrapper + toggle)
it('runs fbl:process for active servers and records the cron run', function (): void {
    fbl_subscriber();

    FblServer::factory()->system()->create();
    FblServer::factory()->system()->inactive()->create();

    fbl_mailbox([1 => fbl_fixture('arf-abuse.eml', [
        '{{MESSAGE_ID}}' => 'msg-arf-13@platform.test',
        '{{CAMPAIGN_UID}}' => 'x',
        '{{SUBSCRIBER_UID}}' => 'x',
    ])]);

    $this->artisan('fbl:process')->assertSuccessful();

    $run = CronRun::query()->where('job', 'fbl:process')->firstOrFail();
    expect($run->status)->toBe(CronRun::STATUS_SUCCESS)
        ->and($run->processed)->toBe(1)
        ->and(FblServer::withoutTenancy()->whereNotNull('last_run_at')->count())->toBe(1);
});

it('skips fbl:process when disabled in cron settings', function (): void {
    $settings = app(CronSettings::class);
    $settings->enabled_jobs = array_values(array_diff($settings->enabled_jobs, ['fbl:process']));
    $settings->save();

    FblServer::factory()->system()->create();
    $mailbox = fbl_mailbox([1 => fbl_fixture('auto-reply.eml')]);

    $this->artisan('fbl:process')->assertSuccessful();

    expect(CronRun::query()->where('job', 'fbl:process')->exists())->toBeFalse()
        ->and($mailbox->deleted)->toBe([]);
});
