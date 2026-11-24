<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\LandingPageResource\Concerns;

use App\Models\LandingPage;
use App\Models\LandingPageRevision;
use App\Services\Landing\LandingPageRevisions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Revision-history UI for landing pages (spec §2.6 F-2.6-005) — the shape
 * the §2.5.4 template builder uses (HasTemplateRevisions): a modal listing
 * the stored revisions with a restore button per row.
 */
trait HasLandingRevisions
{
    public function restoreRevision(int $revisionId): void
    {
        /** @var LandingPage $record */
        $record = $this->getRecord();

        abort_unless(auth('customer')->user()?->can('update', $record) ?? false, 403);

        /** @var LandingPageRevision $revision */
        $revision = LandingPageRevision::query()
            ->where('landing_page_id', $record->id)
            ->whereKey($revisionId)
            ->firstOrFail();

        app(LandingPageRevisions::class)->restore($record, $revision);

        Notification::make()->success()
            ->title(__('landing.notifications.revision_restored'))
            ->send();

        $this->redirect(request()->header('Referer') ?? static::getResource()::getUrl('index'));
    }

    protected function revisionsAction(): Action
    {
        return Action::make('revisions')
            ->label(__('landing.actions.revisions'))
            ->icon('heroicon-m-clock')
            ->modalHeading(__('landing.revisions.heading'))
            ->modalContent(fn () => view('filament.customer.landing.revisions', [
                'revisions' => LandingPageRevision::query()
                    ->where('landing_page_id', $this->getRecord()->id)
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->get(),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('landing.actions.close'));
    }
}
