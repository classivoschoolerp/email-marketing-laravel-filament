<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\WorkerNodesPage;
use App\Listeners\Ops\WorkerRegistryListener;
use App\Models\WorkerNode;
use App\Services\Ops\WorkerRegistry;
use App\Settings\OpsSettings;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Support\AdminTestHelpers;

// §6.6 F-6.6-008 worker/node registry + F-6.6-009 graceful restart/drain.

function wp24FakeJob(string $name, string $id = 'job-1', string $queue = 'campaigns-send'): Job
{
    return new class($name, $id, $queue) implements Job
    {
        public function __construct(
            private string $name,
            private string $id,
            private string $queueName,
        ) {}

        public function resolveName(): string
        {
            return $this->name;
        }

        public function getJobId(): string
        {
            return $this->id;
        }

        public function getQueue(): string
        {
            return $this->queueName;
        }

        public function uuid(): ?string
        {
            return $this->id;
        }

        public function getConnectionName(): string
        {
            return 'redis';
        }

        // Unused Job contract members.
        public function fire(): void {}

        public function release($delay = 0): void {}

        public function isReleased(): bool
        {
            return false;
        }

        public function delete(): void {}

        public function isDeleted(): bool
        {
            return false;
        }

        public function isDeletedOrReleased(): bool
        {
            return false;
        }

        public function attempts(): int
        {
            return 1;
        }

        public function hasFailed(): bool
        {
            return false;
        }

        public function markAsFailed(): void {}

        public function fail($e = null): void {}

        public function maxTries(): ?int
        {
            return 3;
        }

        public function maxExceptions(): ?int
        {
            return null;
        }

        public function backoff(): ?int
        {
            return null;
        }

        public function retryUntil(): ?int
        {
            return null;
        }

        public function timeout(): ?int
        {
            return null;
        }

        public function getRawBody(): string
        {
            return '{}';
        }

        public function payload(): array
        {
            return ['displayName' => $this->name];
        }

        public function resolveQueuedJobClass(): string
        {
            return $this->name;
        }

        public function getName(): string
        {
            return $this->name;
        }

        public function getResolvedName(): string
        {
            return $this->name;
        }

        public function setConnectionName($name): static
        {
            return $this;
        }
    };
}

/* ================================================================ *
 *  Registry from queue events (F-6.6-008)
 * ================================================================ */

it('records what each worker process is doing from queue events', function (): void {
    $listener = app(WorkerRegistryListener::class);
    $job = wp24FakeJob('App\\Jobs\\Sending\\SendCampaignBatchJob');

    $listener->handleJobProcessing(new JobProcessing('redis', $job));

    $worker = WorkerNode::query()->firstOrFail();

    expect($worker->node)->toBe(WorkerRegistry::nodeName())
        ->and($worker->pid)->toBe(WorkerRegistry::pid())
        ->and($worker->queues)->toBe('campaigns-send')
        ->and($worker->connection)->toBe('redis')
        ->and($worker->current_job)->toBe('App\\Jobs\\Sending\\SendCampaignBatchJob')
        ->and($worker->current_job_id)->toBe('job-1')
        ->and($worker->started_at)->not->toBeNull()
        ->and($worker->alive())->toBeTrue()
        ->and($worker->queueList())->toBe(['campaigns-send']);

    $listener->handleJobProcessed(new JobProcessed('redis', $job));

    $worker->refresh();

    expect($worker->current_job)->toBeNull()
        ->and($worker->jobs_processed)->toBe(1)
        ->and($worker->jobs_failed)->toBe(0);

    $listener->handleJobFailed(new JobFailed('redis', $job, new RuntimeException('boom')));

    $worker->refresh();

    expect($worker->jobs_failed)->toBe(1)
        ->and($worker->jobs_processed)->toBe(1)
        // One row per (node, pid) — events never duplicate the process.
        ->and(WorkerNode::query()->count())->toBe(1);
});

it('keeps idle nodes visible through queue:heartbeat and marks stale rows', function (): void {
    $this->artisan('queue:heartbeat --once --queues=exports,maintenance --connection=redis')
        ->assertSuccessful();

    $worker = WorkerNode::query()->firstOrFail();

    expect($worker->queues)->toBe('exports,maintenance')
        ->and($worker->connection)->toBe('redis')
        ->and($worker->alive())->toBeTrue();

    // Staleness is settings-driven.
    $settings = app(OpsSettings::class);
    $settings->worker_stale_seconds = 60;
    $settings->save();

    $worker->forceFill(['last_heartbeat_at' => now()->subMinutes(5)])->save();

    expect(WorkerNode::query()->alive()->count())->toBe(0)
        ->and(WorkerNode::query()->stale()->count())->toBe(1)
        ->and($worker->refresh()->alive())->toBeFalse();

    // Pruning removes rows past the prune window, not merely stale ones.
    expect(app(WorkerRegistry::class)->prune())->toBe(0);

    $worker->forceFill(['last_heartbeat_at' => now()->subDays(2)])->save();

    expect(app(WorkerRegistry::class)->prune())->toBe(1)
        ->and(WorkerNode::query()->count())->toBe(0);
});

/* ================================================================ *
 *  Graceful restart / drain (F-6.6-009)
 * ================================================================ */

it('writes and clears the drain flag, and the heartbeat honours it', function (): void {
    $registry = app(WorkerRegistry::class);

    expect($registry->draining())->toBeFalse();

    $registry->drain('ops@example.test');

    expect($registry->draining())->toBeTrue()
        ->and($registry->drainState()['by'])->toBe('ops@example.test')
        ->and(Cache::get(WorkerRegistry::drainKey()))->not->toBeNull();

    // A worker heartbeat exits instead of registering while draining.
    $this->artisan('queue:heartbeat --once')
        ->expectsOutputToContain(__('ops.workers.draining_exit'))
        ->assertSuccessful();

    expect(WorkerNode::query()->count())->toBe(0);

    $registry->cancelDrain();

    expect($registry->draining())->toBeFalse()
        ->and($registry->drainState())->toBeNull();

    $this->artisan('queue:heartbeat --once')->assertSuccessful();

    expect(WorkerNode::query()->count())->toBe(1);
});

it('drives restart, drain and prune from the admin worker page', function (): void {
    app(WorkerRegistry::class)->heartbeat('campaigns-send', 'redis');

    AdminTestHelpers::actingAdmin(['logs.view']);

    $this->get(WorkerNodesPage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(WorkerRegistry::nodeName())
        ->assertSee('campaigns-send')
        ->assertSee(__('ops.workers.alive'));

    // queue:restart writes Laravel's own restart broadcast timestamp.
    Cache::forget('illuminate:queue:restart');

    Livewire::test(WorkerNodesPage::class)->callAction('restart');

    expect(Cache::get('illuminate:queue:restart'))->not->toBeNull();

    Livewire::test(WorkerNodesPage::class)->callAction('drain');

    expect(app(WorkerRegistry::class)->draining())->toBeTrue();

    $this->get(WorkerNodesPage::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee(__('ops.workers.drain_active'));

    Livewire::test(WorkerNodesPage::class)->callAction('cancelDrain');

    expect(app(WorkerRegistry::class)->draining())->toBeFalse();

    WorkerNode::query()->update(['last_heartbeat_at' => now()->subDays(3)]);

    Livewire::test(WorkerNodesPage::class)->callAction('prune');

    expect(WorkerNode::query()->count())->toBe(0);

    Artisan::call('queue:heartbeat', ['--once' => true]); // page stays usable after
});
