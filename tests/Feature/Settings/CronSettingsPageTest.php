<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\Settings\CronSettingsPage;
use App\Models\Customer;
use App\Models\User;
use App\Services\Settings\SettingsReader;
use App\Settings\CronSettings;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(), 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

// F-3.25.11-001
it('renders the cron settings page for an admin', function (): void {
    $this->get(CronSettingsPage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('settings.cron.title'));
});

it('denies the customer guard access to the cron settings page', function (): void {
    auth('admin')->logout();
    $this->actingAs(Customer::factory()->create(), 'customer');

    $this->get(CronSettingsPage::getUrl(panel: 'admin'))->assertRedirect();
});

it('shows the seeded cron defaults with all jobs enabled', function (): void {
    Livewire::test(CronSettingsPage::class)
        ->assertFormSet([
            'emails_per_batch' => 500,
            'subscribers_per_batch' => 1000,
            'parallel_processes' => 1,
            'enabled_jobs' => CronSettings::KNOWN_JOBS,
            'delivery_concurrency' => 2,
            'bounce_concurrency' => 1,
        ]);
});

// F-3.25.11-001 / F-3.25.11-002 / F-3.25.11-004
it('persists cron settings', function (): void {
    Livewire::test(CronSettingsPage::class)
        ->fillForm([
            'emails_per_batch' => 250,
            'subscribers_per_batch' => 2000,
            'parallel_processes' => 4,
            'enabled_jobs' => ['campaigns:send', 'bounces:process'],
            'delivery_concurrency' => 8,
            'bounce_concurrency' => 2,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(CronSettings::class)->refresh();

    expect($settings->emails_per_batch)->toBe(250)
        ->and($settings->subscribers_per_batch)->toBe(2000)
        ->and($settings->parallel_processes)->toBe(4)
        ->and(array_values($settings->enabled_jobs))->toBe(['campaigns:send', 'bounces:process'])
        ->and($settings->delivery_concurrency)->toBe(8)
        ->and($settings->bounce_concurrency)->toBe(2);
});

it('rejects invalid batch limits and unknown jobs', function (): void {
    Livewire::test(CronSettingsPage::class)
        ->fillForm([
            'emails_per_batch' => 0,
            'parallel_processes' => 0,
            'enabled_jobs' => ['not-a-job'],
        ])
        ->call('save')
        ->assertHasFormErrors(['emails_per_batch', 'parallel_processes', 'enabled_jobs.0']);
});

// F-3.25.11-002 — per-job enablement through the reader
it('exposes cron settings through the reader', function (): void {
    $settings = app(CronSettings::class);
    $settings->enabled_jobs = ['campaigns:send'];
    $settings->save();

    $reader = SettingsReader::cron();

    expect($reader->emailsPerBatch())->toBe(500)
        ->and($reader->jobEnabled('campaigns:send'))->toBeTrue()
        ->and($reader->jobEnabled('bounces:process'))->toBeFalse()
        ->and($reader->jobEnabled('unknown:job'))->toBeFalse()
        ->and($reader->deliveryConcurrency())->toBe(2)
        ->and($reader->bounceConcurrency())->toBe(1);
});
