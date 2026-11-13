<?php

declare(strict_types=1);

use App\Console\Commands\DemoSeedCommand;
use App\Models\Campaign;
use App\Models\CampaignDeliveryLog;
use App\Models\CampaignTrackClick;
use App\Models\CampaignTrackOpen;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\EmailTemplate;
use App\Models\Subscriber;

// §6.9 F-6.9-006 — seeded demo data option.

it('creates a complete demo dataset with plausible campaign stats', function (): void {
    $this->artisan('emp:demo-seed --subscribers=20')->assertSuccessful();

    $customer = Customer::query()->where('email', DemoSeedCommand::DEMO_EMAIL)->firstOrFail();
    $list = EmailList::withoutTenancy()->where('customer_id', $customer->id)->firstOrFail();
    $campaign = Campaign::withoutTenancy()->where('customer_id', $customer->id)->firstOrFail();

    expect($list->name)->toBe('Demo Newsletter')
        ->and(Subscriber::withoutTenancy()->where('list_id', $list->id)->count())->toBe(20)
        ->and(EmailTemplate::withoutTenancy()->where('customer_id', $customer->id)->count())->toBe(1);

    // A *sent* campaign with real delivery/open/click rows behind its counters.
    expect($campaign->status->value)->toBe('sent')
        ->and($campaign->delivery_total)->toBe(20)
        ->and($campaign->delivery_sent + $campaign->delivery_failed)->toBe(20)
        ->and($campaign->send_finished_at)->not->toBeNull();

    $logs = CampaignDeliveryLog::query()->where('campaign_id', $campaign->id);

    expect($logs->count())->toBe(20)
        ->and((clone $logs)->where('status', 'sent')->count())->toBe($campaign->delivery_sent)
        ->and((clone $logs)->where('status', 'failed')->count())->toBe($campaign->delivery_failed);

    $opens = CampaignTrackOpen::query()->where('campaign_uid', $campaign->uid)->count();
    $clicks = CampaignTrackClick::query()->where('campaign_uid', $campaign->uid)->count();

    expect($opens)->toBeGreaterThan(0)
        ->and($opens)->toBeLessThan(20)
        ->and($clicks)->toBeGreaterThan(0)
        ->and($clicks)->toBeLessThanOrEqual($opens);
});

it('is idempotent unless --fresh is passed', function (): void {
    $this->artisan('emp:demo-seed --subscribers=5')->assertSuccessful();

    $this->artisan('emp:demo-seed --subscribers=5')
        ->expectsOutputToContain(__('ops.demo.exists', ['email' => DemoSeedCommand::DEMO_EMAIL]))
        ->assertSuccessful();

    expect(Customer::query()->where('email', DemoSeedCommand::DEMO_EMAIL)->count())->toBe(1)
        ->and(Subscriber::withoutTenancy()->count())->toBe(5);

    $this->artisan('emp:demo-seed --subscribers=7 --fresh')->assertSuccessful();

    expect(Customer::query()->where('email', DemoSeedCommand::DEMO_EMAIL)->count())->toBe(1)
        ->and(Subscriber::withoutTenancy()->count())->toBe(7)
        ->and(Campaign::withoutTenancy()->count())->toBe(1)
        ->and(CampaignDeliveryLog::query()->count())->toBe(7);
});

it('uses no dev-only faker dependency', function (): void {
    $source = file_get_contents(app_path('Console/Commands/DemoSeedCommand.php'));

    // fakerphp/faker is require-dev: a production install (--no-dev) would fatal.
    expect($source)->not->toContain('fake()')
        ->and($source)->not->toContain('Factory::');
});
