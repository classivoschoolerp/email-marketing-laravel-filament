<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\EmailTemplateResource\Concerns;

use App\Models\EmailTemplate;
use App\Services\Templates\TemplateRevisions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Revision-history UI shared by the edit and builder pages (spec §2.5.4
 * F-2.5.4-008): a modal listing the stored revisions with a restore button.
 */
trait HasTemplateRevisions
{
    public function restoreRevision(int $revisionId): void
    {
        /** @var EmailTemplate $record */
        $record = $this->getRecord();

        abort_unless(auth('customer')->user()?->can('update', $record) ?? false, 403);

        $revision = $record->revisions()->whereKey($revisionId)->firstOrFail();

        app(TemplateRevisions::class)->restore($record, $revision);

        Notification::make()->success()
            ->title(__('templates.notifications.revision_restored'))
            ->send();

        $this->redirect(request()->header('Referer') ?? static::getResource()::getUrl('index'));
    }

    protected function revisionsAction(): Action
    {
        return Action::make('revisions')
            ->label(__('templates.actions.revisions'))
            ->icon('heroicon-m-clock')
            ->modalHeading(__('templates.revisions.heading'))
            ->modalContent(fn () => view('filament.customer.templates.revisions', [
                'revisions' => $this->getRecord()->revisions()->get(),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('templates.actions.close'));
    }
}
