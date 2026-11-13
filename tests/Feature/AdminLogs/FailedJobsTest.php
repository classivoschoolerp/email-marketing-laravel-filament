<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\FailedJobResource;
use App\Filament\Admin\Resources\FailedJobResource\Pages\ListFailedJobs;
use App\Models\Customer;
use App\Models\FailedJob;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\AdminTestHelpers;

// §0.4 F-0.4-003 + §3.27 F-3.27-012 — failed queue jobs: inspect payload +
// exception, retry via queue:retry, delete; policy-gated + D-28 bulk.

function wp17_failed_job(string $uuid = 'wp17-uuid-0001'): FailedJob
{
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'exports',
        'payload' => json_encode([
            'uuid' => $uuid,
            'displayName' => 'App\\Jobs\\Reports\\RunCampaignReportExportJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'attempts' => 1,
        ]),
        'exception' => "RuntimeException: boom at line 1\n#0 stack-frame-one\n#1 stack-frame-two",
        'failed_at' => now(),
    ]);

    return FailedJob::query()->where('uuid', $uuid)->firstOrFail();
}

it('lists failed jobs with name, queue and exception summary', function (): void {
    $job = wp17_failed_job();

    AdminTestHelpers::actingAdmin(['logs.view']);

    $this->get(FailedJobResource::getUrl('index', panel: 'admin'))->assertOk();

    Livewire::test(ListFailedJobs::class)
        ->assertCanSeeTableRecords([$job])
        ->assertSee('RunCampaignReportExportJob')
        ->assertSee('exports')
        ->assertSee('RuntimeException: boom at line 1');

    // Inspection accessors (payload + exception, F-3.27-012).
    expect($job->jobName())->toBe('App\Jobs\Reports\RunCampaignReportExportJob')
        ->and($job->exceptionSummary())->toBe('RuntimeException: boom at line 1')
        ->and($job->prettyPayload())->toContain('"displayName"');
});

// F-3.27-012 — retry pushes the payload back onto its queue and removes the row.
it('retries a failed job back onto the jobs table', function (): void {
    $job = wp17_failed_job();

    AdminTestHelpers::actingAdmin(['logs.view', 'logs.manage']);

    expect(DB::table('jobs')->count())->toBe(0);

    Livewire::test(ListFailedJobs::class)
        ->callTableAction('retry', $job);

    expect(DB::table('jobs')->where('queue', 'exports')->count())->toBe(1)
        ->and(FailedJob::query()->count())->toBe(0);
});

it('deletes a failed job', function (): void {
    $job = wp17_failed_job();

    AdminTestHelpers::actingAdmin(['logs.view', 'logs.manage']);

    Livewire::test(ListFailedJobs::class)
        ->callTableAction('delete', $job);

    expect(FailedJob::query()->count())->toBe(0);
});

it('hides retry from admins without logs.manage and denies unpermitted access', function (): void {
    $job = wp17_failed_job();

    AdminTestHelpers::actingAdmin(['logs.view']); // view only

    Livewire::test(ListFailedJobs::class)
        ->assertTableActionHidden('retry', $job);

    expect(FailedJob::query()->count())->toBe(1);

    AdminTestHelpers::actingAdmin(); // nothing
    $this->get(FailedJobResource::getUrl('index', panel: 'admin'))->assertForbidden();
});

// A customer session never reaches the admin panel (bounced to admin login).
it('denies failed jobs to customer-guard users', function (): void {
    $this->actingAs(Customer::factory()->create(), 'customer');
    $this->get(FailedJobResource::getUrl('index', panel: 'admin'))->assertRedirect();
});
