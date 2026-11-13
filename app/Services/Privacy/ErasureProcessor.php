<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Jobs\Subscribers\GdprEraseSubscriberJob;
use App\Models\ErasureRequest;
use App\Models\Subscriber;
use Illuminate\Support\Str;

/**
 * Executes a confirmed right-to-erasure request (spec §3.25.16
 * F-3.25.16-003, §4.5 F-4.5-001).
 *
 * Reuses the existing WP-06/07 erase job — anonymise in place (D-31) — for
 * every subscriber row carrying the requested address, across every list and
 * every tenant. Soft-deleted rows are included: a deleted subscriber's
 * personal data is still on disk until it is anonymised.
 */
final class ErasureProcessor
{
    /** @return int number of subscriber rows queued for erasure */
    public function process(ErasureRequest $request): int
    {
        $operationId = (string) Str::uuid();

        $subscribers = Subscriber::withoutTenancy()
            ->withTrashed()
            ->where('email', mb_strtolower(trim((string) $request->email)))
            ->get(['id', 'customer_id']);

        foreach ($subscribers as $subscriber) {
            GdprEraseSubscriberJob::dispatch(
                (int) $subscriber->customer_id,
                (int) $subscriber->id,
                $operationId,
            );
        }

        $request->forceFill([
            'status' => ErasureRequest::STATUS_COMPLETED,
            'erased_count' => $subscribers->count(),
            'processed_at' => now(),
        ])->save();

        return $subscribers->count();
    }

    public function reject(ErasureRequest $request): void
    {
        $request->forceFill([
            'status' => ErasureRequest::STATUS_REJECTED,
            'processed_at' => now(),
        ])->save();
    }
}
