<?php

declare(strict_types=1);

use App\Mail\QuotaReachedMail;
use App\Mail\QuotaWarningMail;
use App\Models\Campaign;
use App\Models\CampaignDeliveryLog;
use App\Models\Customer;
use App\Services\SystemEmails\QuotaNotifier;
use Illuminate\Support\Facades\Mail;

// WP-20 — §3.15 F-3.15-008: quota warning / quota reached notifications.

function quotaCampaignFor(Customer $customer): Campaign
{
    return Campaign::factory()->create(['customer_id' => $customer->id]);
}

function logSends(Campaign $campaign, int $count, string $status = CampaignDeliveryLog::STATUS_SENT): void
{
    static $sequence = 0;

    for ($i = 1; $i <= $count; $i++) {
        CampaignDeliveryLog::query()->create([
            'campaign_id' => $campaign->id,
            'subscriber_id' => 100_000 + (++$sequence),
            'status' => $status,
        ]);
    }
}

it('does nothing for unlimited customers', function (): void {
    Mail::fake();

    $customer = Customer::factory()->create();
    $customer->limitOverrides()->create(['key' => 'sends_per_month', 'value' => 0]); // 0 = unlimited (D-23)

    expect(app(QuotaNotifier::class)->check($customer))->toBeNull();

    Mail::assertNothingQueued();
});

it('queues the warning mail once when usage crosses the threshold', function (): void {
    Mail::fake();

    $customer = Customer::factory()->create();
    $customer->limitOverrides()->create(['key' => 'sends_per_month', 'value' => 10]);
    logSends(quotaCampaignFor($customer), 8);

    $notifier = app(QuotaNotifier::class);

    expect($notifier->monthlyUsage($customer))->toBe(8)
        ->and($notifier->check($customer))->toBe(QuotaNotifier::LEVEL_WARNING);

    Mail::assertQueued(QuotaWarningMail::class, fn (QuotaWarningMail $mail): bool => $mail->hasTo($customer->email)
        && $mail->used === 8 && $mail->limit === 10 && $mail->percent === 80);

    // Cache-deduped: the second sweep this month stays silent.
    expect($notifier->check($customer))->toBeNull();
    Mail::assertQueued(QuotaWarningMail::class, 1);
});

it('queues the reached mail at 100 percent', function (): void {
    Mail::fake();

    $customer = Customer::factory()->create();
    $customer->limitOverrides()->create(['key' => 'sends_per_month', 'value' => 5]);
    logSends(quotaCampaignFor($customer), 5);

    expect(app(QuotaNotifier::class)->check($customer))->toBe(QuotaNotifier::LEVEL_REACHED);

    Mail::assertQueued(QuotaReachedMail::class, fn (QuotaReachedMail $mail): bool => $mail->hasTo($customer->email));
    Mail::assertNotQueued(QuotaWarningMail::class);
});

it('only counts delivered sends of the customer for the current month', function (): void {
    Mail::fake();

    $customer = Customer::factory()->create();
    $other = Customer::factory()->create();
    $customer->limitOverrides()->create(['key' => 'sends_per_month', 'value' => 100]);

    $campaign = quotaCampaignFor($customer);
    logSends($campaign, 3);
    logSends($campaign, 2, CampaignDeliveryLog::STATUS_FAILED); // not delivered
    logSends(quotaCampaignFor($other), 4); // someone else's traffic

    // Last month's traffic is out of scope.
    CampaignDeliveryLog::query()->create([
        'campaign_id' => $campaign->id,
        'subscriber_id' => 1,
        'status' => CampaignDeliveryLog::STATUS_SENT,
        'created_at' => now()->subMonths(2),
        'updated_at' => now()->subMonths(2),
    ]);

    expect(app(QuotaNotifier::class)->monthlyUsage($customer))->toBe(3)
        ->and(app(QuotaNotifier::class)->check($customer))->toBeNull();

    Mail::assertNothingQueued();
});

it('sweeps every customer via checkAll', function (): void {
    Mail::fake();

    $customer = Customer::factory()->create();
    $customer->limitOverrides()->create(['key' => 'sends_per_month', 'value' => 2]);
    logSends(quotaCampaignFor($customer), 2);

    app(QuotaNotifier::class)->checkAll();

    Mail::assertQueued(QuotaReachedMail::class, 1);
});
