<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\CampaignResource\Pages;

use App\Enums\CampaignType;
use App\Enums\TemplateBuilder;
use App\Filament\Customer\Resources\CampaignResource;
use App\Filament\Customer\Resources\CampaignResource\Concerns\HandlesCampaignContent;
use App\Jobs\Content\RunInboxPreviewJob;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Models\InboxPreviewRun;
use App\Services\Campaigns\CampaignQuota;
use App\Services\Campaigns\CampaignRevisions;
use App\Services\Campaigns\RecurringSchedule;
use App\Services\Content\CampaignPreviewRenderer;
use App\Services\Content\InboxPreview\InboxPreviewRegistry;
use App\Services\Templates\TemplateQuota;
use App\Services\Templates\TemplateRevisions;
use App\Services\Templates\TemplateThumbnails;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

/**
 * Edit wizard (spec §2.3.2–§2.3.5, skippable steps). Campaigns are editable
 * until sending starts (§2.3.9): non-editable statuses bounce back to the
 * index. Header actions: live desktop/mobile preview, save-as-template
 * (F-2.3.3-018), open builder, delete.
 */
class EditCampaign extends EditRecord
{
    use HandlesCampaignContent;

    protected static string $resource = CampaignResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var Campaign $campaign */
        $campaign = $this->getRecord();

        if (! $campaign->isEditable()) {
            Notification::make()
                ->danger()
                ->title(__('campaigns.errors.not_editable'))
                ->send();

            $this->redirect(CampaignResource::getUrl('index'));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $user = Filament::auth()->user();

        if ($user instanceof Customer && isset($data['type'])) {
            app(CampaignQuota::class)->assertTypeAllowed($user, CampaignType::from((string) $data['type']));
        }

        $data = $this->stashTransientState($data);

        return $this->applyContentSource($data);
    }

    protected function afterSave(): void
    {
        /** @var Campaign $record */
        $record = $this->getRecord();

        $this->importPendingZip($record);
        $this->syncAttachments($record);
        $this->ensureShareSecret($record);
        // WP-35: extra lists, tags and the §2.3.6 variant invariants.
        $this->syncCampaignRelations($record);
        $this->recordCampaignRevision($record);

        // Keep the stored next run in sync for recurring drafts (F-2.3.2-022).
        if ($record->type === CampaignType::Recurring) {
            $record->forceFill([
                'next_run_at' => app(RecurringSchedule::class)->nextRunAt($record),
            ])->saveQuietly();
        }

        $this->warnOnMissingUnsubscribe($record);
        $this->applyConfirmAction($record);
    }

    /* ================================================================ *
     *  §2.3.5 F-2.3.5-006 — inbox rendering preview (WP-46)
     * ================================================================ */

    /** The most recent run for this campaign, or null. */
    public function latestInboxPreviewRun(): ?InboxPreviewRun
    {
        return InboxPreviewRun::query()
            ->where('campaign_id', $this->getRecord()->id)
            ->latest('id')
            ->first();
    }

    /**
     * Create a run and hand it to the queue. Nothing is rendered here — the
     * HTTP request must not wait on a rendering farm.
     */
    public function startInboxPreview(): void
    {
        $campaign = $this->getRecord();

        abort_unless(static::getResource()::canView($campaign), 403);

        $provider = app(InboxPreviewRegistry::class)->default();

        if (! $provider->configured()) {
            Notification::make()
                ->warning()
                ->title(__('inbox_preview.errors.not_configured'))
                ->send();

            return;
        }

        $run = InboxPreviewRun::create([
            'customer_id' => $campaign->customer_id,
            'campaign_id' => $campaign->id,
            'provider' => $provider->key(),
            'status' => InboxPreviewRun::STATUS_QUEUED,
            'subject' => (string) $campaign->subject,
        ]);

        RunInboxPreviewJob::dispatch((int) $run->id);

        Notification::make()->success()->title(__('inbox_preview.queued'))->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            // Live desktop/mobile preview (F-2.3.3-020).
            Actions\Action::make('preview')
                ->label(__('campaigns.actions.preview'))
                ->icon('heroicon-m-eye')
                ->modalHeading(fn (): string => (string) $this->getRecord()->name)
                // WP-46 (§2.3.3 F-2.3.3-009): the CANONICAL engine, not the
                // sampler — a preview must resolve [IF …] blocks (including
                // smart content by segment) or the customer sees raw syntax.
                ->modalContent(fn () => view('filament.customer.campaigns.preview', [
                    'html' => app(CampaignPreviewRenderer::class)->render($this->getRecord()),
                    'segmentReferences' => app(CampaignPreviewRenderer::class)->segmentReferences($this->getRecord()),
                ]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('campaigns.actions.close')),

            // WP-46 (§2.3.5 F-2.3.5-006) — real client renders, next to the
            // simulated preview above. Submitting is QUEUED; the modal shows
            // the latest run, whatever state it is in.
            Actions\Action::make('inbox_preview')
                ->label(__('inbox_preview.action'))
                ->icon('heroicon-m-device-phone-mobile')
                ->modalHeading(__('inbox_preview.title'))
                ->modalContent(fn () => view('filament.customer.campaigns.inbox-preview', [
                    'run' => $this->latestInboxPreviewRun(),
                    'configured' => app(InboxPreviewRegistry::class)->default()->configured(),
                ]))
                ->modalSubmitActionLabel(__('inbox_preview.run'))
                ->action(fn () => $this->startInboxPreview())
                ->modalCancelActionLabel(__('campaigns.actions.close')),

            // WP-35 F-2.3.3-019 — content version history with restore.
            Actions\Action::make('revisions')
                ->label(__('campaigns.revisions.label'))
                ->icon('heroicon-m-clock')
                ->modalHeading(__('campaigns.revisions.label'))
                ->modalSubmitActionLabel(__('campaigns.revisions.restore'))
                ->form(fn (): array => [
                    Forms\Components\Radio::make('revision_id')
                        ->hiddenLabel()
                        ->options(fn (): array => app(CampaignRevisions::class)
                            ->history($this->getRecord())
                            ->mapWithKeys(fn ($revision): array => [
                                $revision->id => $revision->created_at?->toDayDateTimeString()
                                    .' — '.__('campaigns.revisions.origin_'.$revision->origin),
                            ])
                            ->all()),
                ])
                ->action(function (array $data): void {
                    /** @var Campaign $record */
                    $record = $this->getRecord();

                    $revision = $record->revisions()->whereKey((int) ($data['revision_id'] ?? 0))->first();

                    if ($revision === null) {
                        return;
                    }

                    app(CampaignRevisions::class)->restore($record, $revision);

                    Notification::make()->success()->title(__('campaigns.revisions.restored'))->send();

                    $this->fillForm();
                })
                ->visible(fn (): bool => $this->getRecord()->revisions()->exists()),

            Actions\Action::make('open_builder')
                ->label(__('campaigns.actions.open_builder'))
                ->icon('heroicon-m-paint-brush')
                ->url(fn (): string => CampaignResource::getUrl('content', ['record' => $this->getRecord()]))
                ->visible(fn (): bool => $this->getRecord()->builder === TemplateBuilder::GrapesJs),

            // Save as template (F-2.3.3-018).
            Actions\Action::make('save_as_template')
                ->label(__('campaigns.actions.save_as_template'))
                ->icon('heroicon-m-document-duplicate')
                ->form([
                    Forms\Components\TextInput::make('name')
                        ->label(__('campaigns.actions.template_name'))
                        ->required()
                        ->maxLength(255),
                ])
                ->action(function (array $data): void {
                    /** @var Campaign $record */
                    $record = $this->getRecord();

                    try {
                        app(TemplateQuota::class)->assertCanCreate(CampaignResource::customer());
                    } catch (ValidationException $e) {
                        Notification::make()->danger()
                            ->title(collect($e->errors())->flatten()->first())
                            ->send();

                        return;
                    }

                    $template = EmailTemplate::query()->create([
                        'customer_id' => $record->customer_id,
                        'name' => $data['name'],
                        'builder' => $record->builder?->value ?? TemplateBuilder::Html->value,
                        'content_html' => $record->content_html,
                        'content_json' => $record->content_json,
                    ]);

                    app(TemplateRevisions::class)->record($template);
                    app(TemplateThumbnails::class)->refreshFromContent($template);

                    Notification::make()->success()
                        ->title(__('campaigns.notifications.saved_as_template'))
                        ->send();
                })
                ->visible(fn (): bool => trim((string) $this->getRecord()->content_html) !== ''),

            Actions\DeleteAction::make(),
        ];
    }
}
