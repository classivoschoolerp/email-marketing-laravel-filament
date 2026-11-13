<?php

declare(strict_types=1);

use App\Enums\RecurringInterval;
use App\Models\Campaign;
use App\Services\Campaigns\RecurringSchedule;
use Illuminate\Support\Carbon;

// §2.3.2 F-2.3.2-019..022 — recurring schedule + next-run preview.

beforeEach(function (): void {
    $this->schedule = new RecurringSchedule;
    Carbon::setTestNow('2026-08-08 10:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** Unsaved campaign carrying only schedule state — no DB needed. */
function recurringCampaign(array $attributes): Campaign
{
    $campaign = new Campaign;
    $campaign->forceFill($attributes + ['timezone' => 'UTC']);

    return $campaign;
}

it('validates cron expressions via Cron\CronExpression', function (): void {
    expect($this->schedule->isValidCron('*/30 * * * *'))->toBeTrue()
        ->and($this->schedule->isValidCron('0 9 * * 1-5'))->toBeTrue()
        ->and($this->schedule->isValidCron('not a cron'))->toBeFalse()
        ->and($this->schedule->isValidCron(''))->toBeFalse()
        ->and($this->schedule->isValidCron(null))->toBeFalse();
});

it('previews daily runs at the start time', function (): void {
    $campaign = recurringCampaign([
        'rec_interval' => RecurringInterval::Daily,
        'rec_starts_at' => '2026-08-10 09:30:00',
    ]);

    $runs = $this->schedule->nextRunPreview($campaign, 3);

    expect(array_map(fn (Carbon $run): string => $run->toDateTimeString(), $runs))->toBe([
        '2026-08-10 09:30:00',
        '2026-08-11 09:30:00',
        '2026-08-12 09:30:00',
    ]);
});

it('previews weekly runs on the chosen days of week', function (): void {
    // 2026-08-08 is a Saturday. Mondays (1) and Thursdays (4) at 08:00.
    $campaign = recurringCampaign([
        'rec_interval' => RecurringInterval::Weekly,
        'rec_starts_at' => '2026-08-08 08:00:00',
        'rec_days_of_week' => [1, 4],
    ]);

    $runs = $this->schedule->nextRunPreview($campaign, 3);

    expect(array_map(fn (Carbon $run): string => $run->toDateTimeString(), $runs))->toBe([
        '2026-08-10 08:00:00', // Monday
        '2026-08-13 08:00:00', // Thursday
        '2026-08-17 08:00:00', // Monday
    ]);
});

it('previews monthly runs on the chosen day of month', function (): void {
    $campaign = recurringCampaign([
        'rec_interval' => RecurringInterval::Monthly,
        'rec_starts_at' => '2026-08-01 12:00:00',
        'rec_day_of_month' => 15,
    ]);

    $runs = $this->schedule->nextRunPreview($campaign, 2);

    expect(array_map(fn (Carbon $run): string => $run->toDateTimeString(), $runs))->toBe([
        '2026-08-15 12:00:00',
        '2026-09-15 12:00:00',
    ]);
});

it('previews hourly and yearly runs', function (): void {
    $hourly = recurringCampaign([
        'rec_interval' => RecurringInterval::Hourly,
        'rec_starts_at' => '2026-08-08 09:15:00',
    ]);

    $runs = $this->schedule->nextRunPreview($hourly, 2);
    expect(array_map(fn (Carbon $run): string => $run->toDateTimeString(), $runs))->toBe([
        '2026-08-08 10:15:00',
        '2026-08-08 11:15:00',
    ]);

    $yearly = recurringCampaign([
        'rec_interval' => RecurringInterval::Yearly,
        'rec_starts_at' => '2026-09-01 00:30:00',
    ]);

    $runs = $this->schedule->nextRunPreview($yearly, 2);
    expect(array_map(fn (Carbon $run): string => $run->toDateTimeString(), $runs))->toBe([
        '2026-09-01 00:30:00',
        '2027-09-01 00:30:00',
    ]);
});

it('supports custom cron expressions', function (): void {
    $campaign = recurringCampaign([
        'rec_interval' => RecurringInterval::CustomCron,
        'rec_cron' => '*/30 * * * *',
    ]);

    $runs = $this->schedule->nextRunPreview($campaign, 2);

    expect(array_map(fn (Carbon $run): string => $run->toDateTimeString(), $runs))->toBe([
        '2026-08-08 10:30:00',
        '2026-08-08 11:00:00',
    ]);
});

it('returns nothing for an invalid custom cron or missing interval', function (): void {
    expect($this->schedule->nextRunPreview(recurringCampaign([
        'rec_interval' => RecurringInterval::CustomCron,
        'rec_cron' => 'garbage',
    ])))->toBe([]);

    expect($this->schedule->nextRunPreview(new Campaign))->toBe([]);
});

it('stops at the end date', function (): void {
    $campaign = recurringCampaign([
        'rec_interval' => RecurringInterval::Daily,
        'rec_starts_at' => '2026-08-09 09:00:00',
        'rec_ends_at' => '2026-08-10 23:59:59',
    ]);

    $runs = $this->schedule->nextRunPreview($campaign, 5);

    expect($runs)->toHaveCount(2)
        ->and($runs[1]->toDateTimeString())->toBe('2026-08-10 09:00:00');
});

it('respects the remaining max occurrences', function (): void {
    $campaign = recurringCampaign([
        'rec_interval' => RecurringInterval::Daily,
        'rec_starts_at' => '2026-08-09 09:00:00',
        'rec_max_occurrences' => 5,
        'rec_occurrences' => 3,
    ]);

    expect($this->schedule->nextRunPreview($campaign, 10))->toHaveCount(2);

    $exhausted = recurringCampaign([
        'rec_interval' => RecurringInterval::Daily,
        'rec_starts_at' => '2026-08-09 09:00:00',
        'rec_max_occurrences' => 3,
        'rec_occurrences' => 3,
    ]);

    expect($this->schedule->nextRunPreview($exhausted, 10))->toBe([]);
});

it('computes runs in the campaign timezone and stores next_run_at in UTC', function (): void {
    $campaign = recurringCampaign([
        'rec_interval' => RecurringInterval::Daily,
        'rec_starts_at' => '2026-08-10 09:00:00', // stored UTC
        'timezone' => 'Asia/Tokyo',               // UTC+9
    ]);

    $next = $this->schedule->nextRunAt($campaign);

    expect($next)->not->toBeNull()
        ->and($next->timezoneName)->toBe('UTC')
        // 18:00 Tokyo = 09:00 UTC.
        ->and($next->toDateTimeString())->toBe('2026-08-10 09:00:00');

    $preview = $this->schedule->nextRunPreview($campaign, 1);
    expect($preview[0]->timezoneName)->toBe('Asia/Tokyo');
});

it('never schedules before the start date', function (): void {
    $campaign = recurringCampaign([
        'rec_interval' => RecurringInterval::Hourly,
        'rec_starts_at' => '2026-08-20 00:10:00',
    ]);

    $runs = $this->schedule->nextRunPreview($campaign, 1);

    expect($runs[0]->toDateTimeString())->toBe('2026-08-20 00:10:00');
});
