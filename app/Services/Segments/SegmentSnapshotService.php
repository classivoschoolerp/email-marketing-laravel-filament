<?php

declare(strict_types=1);

namespace App\Services\Segments;

use App\Models\ListSegment;
use App\Models\ListSegmentSnapshotMember;
use App\Models\Subscriber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Materialises a static segment's membership (spec §2.2.10 F-2.2.10-013).
 *
 * Freezing evaluates the segment's conditions exactly once — through the same
 * SegmentQueryBuilder every other consumer uses — and writes the matching
 * subscriber ids into list_segment_snapshot_members. From then on
 * SegmentQueryBuilder::subscribers() resolves the segment from those rows, so
 * campaign targeting, SMS targeting, exports and automations all see the frozen
 * set without a single call site changing.
 *
 * This is bulk work (a whole list can match): it is only ever invoked from the
 * queued RefreshSegmentSnapshotJob, never inline from a request (docs/ARCHITECTURE.md
 * rule 5).
 *
 * The replace runs in one transaction so a send that fires mid-refresh sees
 * either the old snapshot or the new one — never an empty half-written table.
 */
class SegmentSnapshotService
{
    private const CHUNK = 1000;

    public function __construct(private readonly SegmentQueryBuilder $builder) {}

    /**
     * Freeze / re-freeze the segment. Returns the number of frozen members.
     */
    public function refresh(ListSegment $segment): int
    {
        $count = 0;

        DB::transaction(function () use ($segment, &$count): void {
            $this->deleteMembers($segment);

            $now = now();

            $this->builder->dynamicSubscribers($segment)
                ->select(['subscribers.id'])
                ->chunkById(self::CHUNK, function (Collection $subscribers) use ($segment, $now, &$count): void {
                    DB::table(ListSegmentSnapshotMember::TABLE)->insert(
                        $subscribers->map(fn (Subscriber $subscriber): array => [
                            'customer_id' => (int) $segment->customer_id,
                            'list_segment_id' => (int) $segment->id,
                            'subscriber_id' => (int) $subscriber->id,
                            'created_at' => $now,
                        ])->all(),
                    );

                    $count += $subscribers->count();
                }, 'subscribers.id', 'id');

            $segment->forceFill([
                'snapshot_taken_at' => $now,
                'snapshot_count' => $count,
            ])->save();
        });

        return $count;
    }

    /**
     * Drop the snapshot and the "frozen" stamp — used when a segment goes back
     * to dynamic, so re-freezing later can never resurrect a stale set.
     */
    public function clear(ListSegment $segment): void
    {
        DB::transaction(function () use ($segment): void {
            $this->deleteMembers($segment);

            $segment->forceFill([
                'snapshot_taken_at' => null,
                'snapshot_count' => 0,
            ])->save();
        });
    }

    private function deleteMembers(ListSegment $segment): void
    {
        DB::table(ListSegmentSnapshotMember::TABLE)
            ->where('list_segment_id', (int) $segment->id)
            ->delete();
    }
}
