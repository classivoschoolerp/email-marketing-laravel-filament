<?php

declare(strict_types=1);

namespace App\Jobs\Verification;

use App\Enums\SubscriberStatus;
use App\Enums\VerificationStatus;
use App\Jobs\Concerns\IdempotentJob;
use App\Models\Customer;
use App\Models\GlobalBlacklistEmail;
use App\Models\Subscriber;
use App\Models\VerificationJob;
use App\Models\VerificationResult as VerificationResultRow;
use App\Services\Segments\SegmentQueryBuilder;
use App\Services\Verification\Credentials;
use App\Services\Verification\VerificationCredits;
use App\Services\Verification\VerificationProvider;
use App\Support\Queue\Queues;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Csv\Writer;

/**
 * Executes one §2.9.12 bulk verification run on the maintenance queue
 * (docs/ARCHITECTURE.md rule 5): chunks through the target (whole list / segment /
 * selected subscribers / single address, F-2.9.12-004), verifies each
 * address through the customer's provider, stores verification_results,
 * tags the subscriber (F-2.9.12-006), applies the configured auto-action to
 * undeliverable results (F-2.9.12-007), consumes credits (stopping cleanly
 * when the balance runs dry) and flushes counters per chunk so the Filament
 * table renders live progress (F-2.9.12-008). On completion the result CSV
 * is written to the tenant's private directory for download.
 */
class VerifyBatchJob implements ShouldQueue
{
    use Dispatchable;
    use IdempotentJob;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 3600;

    public function __construct(public readonly int $verificationJobId)
    {
        $this->onQueue(Queues::MAINTENANCE);
    }

    public function handle(CurrentCustomer $current): void
    {
        /** @var VerificationJob|null $job */
        $job = VerificationJob::withoutTenancy()->find($this->verificationJobId);

        if ($job === null || $job->isFinished()) {
            return;
        }

        $customer = Customer::query()->find($job->customer_id);

        if ($customer === null) {
            return;
        }

        $current->runAs($customer, function () use ($job, $customer): void {
            try {
                $this->run($job, $customer);
            } catch (\Throwable $exception) {
                Log::error('Verification run failed', ['verification_job_id' => $job->id, 'exception' => $exception]);

                $job->forceFill([
                    'status' => VerificationJob::STATUS_FAILED,
                    'error' => __('verification.errors.unexpected'),
                    'finished_at' => now(),
                ])->save();
            }
        });
    }

    private function run(VerificationJob $job, Customer $customer): void
    {
        $credentials = app(Credentials::class);
        $credits = app(VerificationCredits::class);

        $provider = $credentials->providerFor($customer, $job->provider);

        $job->forceFill([
            'provider' => $provider->key(),
            'status' => VerificationJob::STATUS_RUNNING,
            'started_at' => $job->started_at ?? now(),
            'total' => $this->targetTotal($job),
        ])->save();

        $chunk = max(1, (int) config('emp-verification.batch.chunk', 100));
        $inChunk = 0;
        $exhausted = false;

        foreach ($this->targets($job) as $target) {
            [$email, $subscriber] = $target;

            // WP-26: description lands on the F-3.26.1-014 consumption ledger row.
            if (! $credits->consume($customer, 1, __('billing.credits.verification_batch'))) {
                $exhausted = true;

                break;
            }

            $this->verifyOne($job, $provider, $email, $subscriber);
            $job->credits_used++;
            $job->processed++;
            $inChunk++;

            if ($inChunk >= $chunk) {
                $job->save();
                $inChunk = 0;
            }
        }

        $job->result_path = $this->writeResultCsv($job);
        $job->status = VerificationJob::STATUS_COMPLETED;
        $job->error = $exhausted ? __('verification.errors.credits_exhausted') : null;
        $job->finished_at = now();
        $job->save();
    }

    private function verifyOne(
        VerificationJob $job,
        VerificationProvider $provider,
        string $email,
        ?Subscriber $subscriber,
    ): void {
        $result = $provider->verify($email);

        VerificationResultRow::query()->create([
            'verification_job_id' => $job->id,
            'subscriber_id' => $subscriber?->id,
            'email' => $email,
            'status' => $result->status->value,
            'reason' => $result->reason,
            'provider' => $provider->key(),
        ]);

        $column = $result->status->value.'_count';
        $job->{$column}++;

        if ($subscriber === null) {
            return;
        }

        Subscriber::withoutTenancy()->whereKey($subscriber->id)->update([
            'verification_status' => $result->status->value,
            'verified_at' => now(),
        ]);

        if ($result->status === VerificationStatus::Undeliverable) {
            $this->applyAutoAction($job, $subscriber->refresh());
        }
    }

    /** F-2.9.12-007: auto-action per (undeliverable) result. */
    private function applyAutoAction(VerificationJob $job, Subscriber $subscriber): void
    {
        switch ($job->auto_action) {
            case VerificationJob::ACTION_DELETE:
                $subscriber->delete();
                break;

            case VerificationJob::ACTION_UNSUBSCRIBE:
                $subscriber->status = SubscriberStatus::Unsubscribed;
                $subscriber->save();
                break;

            case VerificationJob::ACTION_BLACKLIST:
                GlobalBlacklistEmail::query()->firstOrCreate(
                    ['email' => $subscriber->email],
                    [
                        'reason' => __('verification.blacklist_reason'),
                        'source' => GlobalBlacklistEmail::SOURCE_VERIFICATION,
                        'customer_id' => $subscriber->customer_id,
                    ],
                );

                $subscriber->status = SubscriberStatus::Blacklisted;
                $subscriber->save();
                break;

            default: // ACTION_TAG — tagging already happened.
                break;
        }
    }

    /** @return \Generator<int, array{0: string, 1: Subscriber|null}> */
    private function targets(VerificationJob $job): \Generator
    {
        if ($job->target === VerificationJob::TARGET_SINGLE) {
            yield [mb_strtolower(trim((string) $job->single_email)), null];

            return;
        }

        $query = $this->subscriberQuery($job);

        if ($query === null) {
            return;
        }

        foreach ($query->orderBy('subscribers.id')->cursor() as $subscriber) {
            yield [(string) $subscriber->email, $subscriber];
        }
    }

    private function targetTotal(VerificationJob $job): int
    {
        if ($job->target === VerificationJob::TARGET_SINGLE) {
            return 1;
        }

        return (int) ($this->subscriberQuery($job)?->count() ?? 0);
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Subscriber>|null */
    private function subscriberQuery(VerificationJob $job): ?\Illuminate\Database\Eloquent\Builder
    {
        if ($job->target === VerificationJob::TARGET_SEGMENT && $job->segment_id !== null) {
            $segment = $job->segment()->withoutGlobalScopes()->first();

            return $segment === null
                ? null
                : app(SegmentQueryBuilder::class)->subscribers($segment)
                    ->where('subscribers.customer_id', $job->customer_id);
        }

        if ($job->target === VerificationJob::TARGET_SUBSCRIBERS) {
            $ids = array_values(array_map(intval(...), (array) ($job->subscriber_ids ?? [])));

            return Subscriber::withoutTenancy()
                ->where('customer_id', $job->customer_id)
                ->whereIn('id', $ids);
        }

        if ($job->list_id !== null) {
            return Subscriber::withoutTenancy()
                ->where('customer_id', $job->customer_id)
                ->where('list_id', $job->list_id);
        }

        return null;
    }

    /** Downloadable result CSV (F-2.9.12-008), league/csv like exports. */
    private function writeResultCsv(VerificationJob $job): string
    {
        $disk = Storage::disk('local');
        $directory = $job->storageDirectory();
        $disk->makeDirectory($directory);

        $path = $directory.'/'.$job->uid.'-results.csv';

        $writer = Writer::createFromPath($disk->path($path), 'w+');
        $writer->insertOne([
            __('verification.csv.email'),
            __('verification.csv.status'),
            __('verification.csv.reason'),
            __('verification.csv.provider'),
            __('verification.csv.checked_at'),
        ]);

        VerificationResultRow::query()
            ->where('verification_job_id', $job->id)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($writer): void {
                foreach ($rows as $row) {
                    $writer->insertOne([
                        $row->email,
                        $row->status->value,
                        (string) $row->reason,
                        $row->provider,
                        (string) $row->created_at,
                    ]);
                }
            });

        return $path;
    }

    public function idempotencyKey(): string
    {
        return 'verify-run:'.$this->verificationJobId;
    }
}
