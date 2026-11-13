<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsAsCronJob;
use App\Enums\SubscriberStatus;
use App\Models\CronRun;
use App\Models\EmailList;
use App\Models\Subscriber;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * `subscribers:clean` (spec §0.6 F-0.6-013, nightly) enforces the three
 * per-list Advanced-tab maintenance settings. Each is independent and each
 * treats 0 as "disabled" (D-23):
 *
 *  - `autodelete_unconfirmed_days` (F-2.2.2-019) — unconfirmed subscribers
 *    older than N DAYS are removed;
 *  - `optin_confirm_expiry_hours` (F-2.2.2-021) — the double opt-in confirm
 *    link is signed with this expiry (PublicPageRenderer::confirmUrl), so once
 *    it is past, that pending subscriber can never confirm and is removed. The
 *    window is in HOURS and is normally much shorter than the day-based one
 *    above; whichever fires first wins, and a row already removed by one pass
 *    is simply not seen by the next;
 *  - `autoremove_bounces_count` (F-2.2.2-020) — subscribers with N or more
 *    HARD bounces are removed. The count is the greater of the per-message
 *    hard-bounce log rows (WP-15 `campaign_bounce_logs`) and the subscriber's
 *    own `bounces_count` when its recorded type is hard, so subscribers
 *    imported with a bounce history count the same as ones bounced here.
 *
 * "Removed" is the model's soft delete throughout: the row leaves every
 * query, count and send immediately, while the bounce/consent history it is
 * referenced by stays intact. Hard removal follows the retention machinery
 * (logs:prune / WP-22 GDPR erasure).
 */
class SubscribersCleanCommand extends Command
{
    use RunsAsCronJob;

    public const JOB_NAME = 'subscribers:clean';

    protected $signature = 'subscribers:clean';

    protected $description = 'Enforce each list\'s auto-delete, opt-in expiry and hard-bounce removal settings.';

    protected function process(CronRun $run): int
    {
        return $this->purgeUnconfirmed()
            + $this->purgeExpiredOptIns()
            + $this->removeHardBounced();
    }

    /** F-2.2.2-019: unconfirmed older than `autodelete_unconfirmed_days`. */
    private function purgeUnconfirmed(): int
    {
        $purged = 0;

        $lists = EmailList::withoutTenancy()
            ->where('autodelete_unconfirmed_days', '>', 0)
            ->get(['id', 'autodelete_unconfirmed_days']);

        foreach ($lists as $list) {
            $cutoff = Carbon::now()->subDays((int) $list->autodelete_unconfirmed_days);

            $purged += $this->deleteUnconfirmedBefore((int) $list->id, $cutoff);
        }

        return $purged;
    }

    /**
     * F-2.2.2-021: unconfirmed whose signed confirmation link has expired
     * (`optin_confirm_expiry_hours` past their subscribe time).
     */
    private function purgeExpiredOptIns(): int
    {
        $purged = 0;

        $lists = EmailList::withoutTenancy()
            ->where('optin_confirm_expiry_hours', '>', 0)
            ->get(['id', 'optin_confirm_expiry_hours']);

        foreach ($lists as $list) {
            $cutoff = Carbon::now()->subHours((int) $list->optin_confirm_expiry_hours);

            $purged += $this->deleteUnconfirmedBefore((int) $list->id, $cutoff);
        }

        return $purged;
    }

    /**
     * Soft-delete this list's Unconfirmed subscribers whose subscribe time
     * (falling back to created_at for legacy rows) is before $cutoff.
     */
    private function deleteUnconfirmedBefore(int $listId, Carbon $cutoff): int
    {
        $removed = 0;

        $stale = Subscriber::withoutTenancy()
            ->where('list_id', $listId)
            ->where('status', SubscriberStatus::Unconfirmed)
            ->where(function (Builder $query) use ($cutoff): void {
                $query->where('subscribed_at', '<', $cutoff)
                    ->orWhere(function (Builder $q) use ($cutoff): void {
                        $q->whereNull('subscribed_at')->where('created_at', '<', $cutoff);
                    });
            })
            ->get();

        foreach ($stale as $subscriber) {
            $subscriber->delete();
            $removed++;
        }

        return $removed;
    }

    /** F-2.2.2-020: N or more hard bounces on a list that enables removal. */
    private function removeHardBounced(): int
    {
        $removed = 0;
        $hasBounceLogs = Schema::hasTable('campaign_bounce_logs');

        $lists = EmailList::withoutTenancy()
            ->where('autoremove_bounces_count', '>', 0)
            ->get(['id', 'autoremove_bounces_count']);

        foreach ($lists as $list) {
            $threshold = (int) $list->autoremove_bounces_count;

            $query = Subscriber::withoutTenancy()
                ->where('list_id', $list->id)
                ->where(function (Builder $outer) use ($threshold, $hasBounceLogs): void {
                    // Bounce counters recorded on the subscriber itself.
                    $outer->where(fn (Builder $q) => $q
                        ->where('bounce_type', 'hard')
                        ->where('bounces_count', '>=', $threshold));

                    if ($hasBounceLogs) {
                        // Per-message hard-bounce log rows (WP-15).
                        $outer->orWhereRaw(
                            '(select count(*) from campaign_bounce_logs'
                            .' where campaign_bounce_logs.subscriber_id = subscribers.id'
                            .' and campaign_bounce_logs.bounce_type = ?) >= ?',
                            ['hard', $threshold],
                        );
                    }
                });

            foreach ($query->get() as $subscriber) {
                $subscriber->delete();
                $removed++;
            }
        }

        return $removed;
    }
}
