<?php

declare(strict_types=1);

namespace App\Services\Templates;

use App\Models\EmailTemplate;
use App\Models\TemplateRevision;
use Illuminate\Support\Carbon;

/**
 * Revision history (spec §2.5.4 F-2.5.4-008): one row per content save,
 * pruned to the newest config('emp-templates.revisions_keep') rows.
 */
class TemplateRevisions
{
    public function record(EmailTemplate $template): TemplateRevision
    {
        $revision = $template->revisions()->create([
            'content_html' => $template->content_html,
            'content_json' => $template->content_json,
            'created_at' => Carbon::now(),
        ]);

        $this->prune($template);

        return $revision;
    }

    public function prune(EmailTemplate $template): void
    {
        $keep = max(1, (int) config('emp-templates.revisions_keep', 20));

        $staleIds = $template->revisions()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->skip($keep)
            ->take(PHP_INT_MAX)
            ->pluck('id');

        if ($staleIds->isNotEmpty()) {
            TemplateRevision::query()->whereIn('id', $staleIds)->delete();
        }
    }

    /**
     * Restore the template content from a revision. The pre-restore content is
     * recorded first so a restore is itself undoable.
     */
    public function restore(EmailTemplate $template, TemplateRevision $revision): void
    {
        abort_unless((int) $revision->email_template_id === (int) $template->id, 404);

        $this->record($template);

        $template->forceFill([
            'content_html' => $revision->content_html,
            'content_json' => $revision->content_json,
        ])->save();
    }
}
