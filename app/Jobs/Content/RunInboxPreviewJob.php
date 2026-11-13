<?php

declare(strict_types=1);

namespace App\Jobs\Content;

use App\Models\InboxPreviewRun;
use App\Services\Content\InboxPreview\InboxPreviewException;
use App\Services\Content\InboxPreview\InboxPreviewProvider;
use App\Services\Content\InboxPreview\InboxPreviewRegistry;
use App\Support\Queue\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Submit one inbox rendering preview and poll it to completion (spec §2.3.5
 * F-2.3.5-006, WP-46), on the maintenance queue.
 *
 * QUEUED, and re-queued rather than looped: a rendering farm answers per
 * client over minutes, so the job submits (first run) or polls once (later
 * runs) and re-dispatches itself with a delay until every screen has left the
 * processing state or {@see MAX_POLLS} is reached. Sleeping inside a worker
 * for ten minutes would hold a queue slot hostage for a screenshot.
 *
 * Giving up is recorded as `failed` with the reason on the row — never as an
 * empty "completed" run, which would read as "your email renders fine
 * everywhere".
 */
class RunInboxPreviewJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Roughly 20 minutes of polling at POLL_DELAY_SECONDS. */
    public const MAX_POLLS = 40;

    public const POLL_DELAY_SECONDS = 30;

    public function __construct(public readonly int $runId)
    {
        $this->onQueue(Queues::MAINTENANCE);
    }

    public function handle(InboxPreviewRegistry $registry): void
    {
        $run = InboxPreviewRun::withoutTenancy()->find($this->runId);

        if ($run === null || $run->isFinished()) {
            return;
        }

        $provider = $registry->get((string) $run->provider);

        if (! $provider->configured()) {
            $this->fail($run, __('inbox_preview.errors.not_configured'));

            return;
        }

        try {
            if ($run->remote_id === null) {
                $this->submit($run, $provider);

                return;
            }

            $this->poll($run, $provider);
        } catch (InboxPreviewException $exception) {
            $this->fail($run, $exception->getMessage());
        } catch (Throwable $exception) {
            $this->fail($run, $exception->getMessage());
        }
    }

    private function submit(InboxPreviewRun $run, InboxPreviewProvider $provider): void
    {
        $content = $this->content($run);

        if ($content === null) {
            $this->fail($run, __('inbox_preview.errors.no_content'));

            return;
        }

        $remoteId = $provider->submit(
            (string) ($run->subject ?? ''),
            $content['html'],
            $content['text'],
            (array) ($run->clients ?? []),
        );

        $run->forceFill([
            'remote_id' => $remoteId,
            'status' => InboxPreviewRun::STATUS_RUNNING,
            'requested_at' => Carbon::now(),
        ])->save();

        self::dispatch($run->id)->delay(self::POLL_DELAY_SECONDS);
    }

    private function poll(InboxPreviewRun $run, InboxPreviewProvider $provider): void
    {
        $result = $provider->results((string) $run->remote_id);

        $run->forceFill([
            'results' => $result->toArray(),
            'polls' => (int) $run->polls + 1,
        ])->save();

        if ($result->error !== null) {
            $this->fail($run, $result->error);

            return;
        }

        if ($result->complete) {
            $run->forceFill([
                'status' => InboxPreviewRun::STATUS_COMPLETED,
                'completed_at' => Carbon::now(),
            ])->save();

            return;
        }

        if ((int) $run->polls >= self::MAX_POLLS) {
            $this->fail($run, __('inbox_preview.errors.timed_out', ['minutes' => (int) round(
                self::MAX_POLLS * self::POLL_DELAY_SECONDS / 60,
            )]));

            return;
        }

        self::dispatch($run->id)->delay(self::POLL_DELAY_SECONDS);
    }

    /**
     * The HTML/text actually submitted. Rendered with SAMPLE values through
     * the canonical engine — a rendering farm has no recipient, and shipping
     * unrendered `{FNAME}` to it would produce screenshots of merge tags.
     *
     * @return array{html: string, text: ?string}|null
     */
    private function content(InboxPreviewRun $run): ?array
    {
        $campaign = $run->campaign_id !== null
            ? \App\Models\Campaign::withoutTenancy()->find($run->campaign_id)
            : null;

        if ($campaign !== null) {
            $html = app(\App\Services\Content\CampaignPreviewRenderer::class)->render($campaign);

            return trim($html) === ''
                ? null
                : ['html' => $html, 'text' => app(\App\Services\Content\PlainTextGenerator::class)->generate($html)];
        }

        $template = $run->email_template_id !== null
            ? \App\Models\EmailTemplate::withoutTenancy()->find($run->email_template_id)
            : null;

        if ($template === null) {
            return null;
        }

        $html = app(\App\Services\Templates\MergeTags::class)->renderSample($template->content_html);

        return trim($html) === ''
            ? null
            : ['html' => $html, 'text' => app(\App\Services\Content\PlainTextGenerator::class)->generate($html)];
    }

    private function fail(InboxPreviewRun $run, string $error): void
    {
        $run->forceFill([
            'status' => InboxPreviewRun::STATUS_FAILED,
            'error' => $error,
            'completed_at' => Carbon::now(),
        ])->save();
    }
}
