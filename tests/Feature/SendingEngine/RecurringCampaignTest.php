<?php

declare(strict_types=1);

use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Enums\RecurringInterval;
use App\Models\Campaign;
use App\Models\DeliveryServer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Sending\DriverRegistry;
use Illuminate\Support\Carbon;
use Tests\Fakes\RecordingSendingDriver;

/*
 * §0.6 F-0.6-003 — campaigns:process-recurring: MailWizz child-copy
 * semantics per due occurrence, schedule advancement, max occurrences.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-08 09:00:30');

    $this->list = EmailList::factory()->create();

    Subscriber::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->list->customer_id,
    ]);

    DeliveryServer::factory()->create([
        'customer_id' => $this->list->customer_id,
        'type' => 'smtp',
    ]);

    $this->driver = new RecordingSendingDriver('smtp');
    app(DriverRegistry::class)->register('smtp', $this->driver);

    $this->parent = Campaign::factory()->create([
        'customer_id' => $this->list->customer_id,
        'email_list_id' => $this->list->id,
        'type' => CampaignType::Recurring,
        'status' => CampaignStatus::Scheduled,
        'rec_interval' => RecurringInterval::Daily,
        'rec_starts_at' => Carbon::parse('2026-08-01 09:00:00'),
        'timezone' => 'UTC',
        'next_run_at' => Carbon::parse('2026-08-08 09:00:00'),
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('spawns a one-shot child run and advances the parent schedule', function (): void {
    $this->artisan('campaigns:process-recurring')->assertSuccessful();

    $child = Campaign::withoutTenancy()
        ->where('type', CampaignType::Regular)
        ->where('id', '!=', $this->parent->id)
        ->firstOrFail();

    expect($child->name)->toContain($this->parent->name)
        ->and($child->status)->toBe(CampaignStatus::Scheduled)
        ->and($child->content_html)->toBe($this->parent->content_html)
        ->and($child->send_at)->not->toBeNull()
        ->and($child->next_run_at)->toBeNull()
        ->and($child->rec_interval)->toBeNull();

    $parent = $this->parent->refresh();

    expect($parent->status)->toBe(CampaignStatus::Scheduled)
        ->and((int) $parent->rec_occurrences)->toBe(1)
        ->and($parent->next_run_at)->not->toBeNull()
        ->and($parent->next_run_at->toDateTimeString())->toBe('2026-08-09 09:00:00');

    // The child is a normal due campaign — campaigns:send delivers it.
    $this->artisan('campaigns:send')->assertSuccessful();

    expect($this->driver->sent)->toHaveCount(1)
        ->and($child->refresh()->status)->toBe(CampaignStatus::Sent)
        ->and($this->parent->refresh()->status)->toBe(CampaignStatus::Scheduled);
});

it('does not run again before next_run_at', function (): void {
    $this->artisan('campaigns:process-recurring')->assertSuccessful();
    $this->artisan('campaigns:process-recurring')->assertSuccessful();

    expect(Campaign::withoutTenancy()->where('type', CampaignType::Regular)->where('id', '!=', $this->parent->id)->count())->toBe(1);
});

it('stops after rec_max_occurrences (schedule exhausted)', function (): void {
    $this->parent->forceFill(['rec_max_occurrences' => 1])->saveQuietly();

    $this->artisan('campaigns:process-recurring')->assertSuccessful();

    $parent = $this->parent->refresh();

    expect((int) $parent->rec_occurrences)->toBe(1)
        ->and($parent->next_run_at)->toBeNull();

    Carbon::setTestNow('2026-08-09 09:01:00');
    $this->artisan('campaigns:process-recurring')->assertSuccessful();

    expect(Campaign::withoutTenancy()->where('type', CampaignType::Regular)->where('id', '!=', $this->parent->id)->count())->toBe(1);
});

it('stops after rec_ends_at', function (): void {
    $this->parent->forceFill(['rec_ends_at' => Carbon::parse('2026-08-08 12:00:00')])->saveQuietly();

    $this->artisan('campaigns:process-recurring')->assertSuccessful();

    expect($this->parent->refresh()->next_run_at)->toBeNull();
});
