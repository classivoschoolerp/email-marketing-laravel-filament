<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\SubscriberResource\Pages;

use App\Enums\ListFieldType;
use App\Enums\SubscriberStatus;
use App\Filament\Customer\Resources\EmailListResource;
use App\Filament\Customer\Resources\SubscriberResource;
use App\Filament\Customer\Support\ListFieldInputs;
use App\Jobs\Subscribers\GdprEraseSubscriberJob;
use App\Jobs\Subscribers\SendSingleEmailJob;
use App\Models\Automation;
use App\Models\CampaignTrackClick;
use App\Models\CampaignTrackOpen;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Models\SubscriberTag;
use App\Services\Automations\Enroller;
use App\Services\Geo\IpLocationResolver;
use App\Services\Lists\FieldValueService;
use App\Services\Subscribers\EngagementScorer;
use App\Services\Subscribers\SubscriberActivityTimeline;
use App\Services\Subscribers\SubscriberListMemberships;
use App\Services\Subscribers\SubscriberTagService;
use App\Support\Geo\Countries;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Subscriber profile (spec §2.2.5): inline-editable standard + custom field
 * values, status/IP/date panels, consent record, deliverability notes and
 * profile actions. The campaign activity timeline renders as an explicit
 * placeholder until the campaigns work package records delivery events.
 */
class ViewSubscriber extends Page
{
    use InteractsWithRecord;

    protected static string $resource = SubscriberResource::class;

    protected static string $view = 'filament.customer.resources.subscriber-resource.pages.view-subscriber';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 403);

        $this->fillFieldForm();
    }

    public function getTitle(): string|Htmlable
    {
        return __('subscribers.profile.title', ['email' => $this->getSubscriber()->email]);
    }

    public function getSubscriber(): Subscriber
    {
        /** @var Subscriber $record */
        $record = $this->record;

        return $record;
    }

    /** Inline-editable standard + custom fields (F-2.2.5-001). */
    public function form(Form $form): Form
    {
        $fields = [
            TextInput::make('email')
                ->label(__('subscribers.form.email'))
                ->email()
                ->required()
                ->maxLength(191),
            // Tags (F-2.2.5-004) — WP-21's subscriber_tags, edited here.
            TagsInput::make('tags')
                ->label(__('subscribers.tags.label'))
                ->placeholder(__('subscribers.tags.placeholder'))
                ->helperText(__('subscribers.tags.help'))
                ->suggestions(fn (): array => SubscriberResource::tagOptions()),
            // Internal notes (F-2.2.5-011) — operator-only, full width.
            Textarea::make('notes')
                ->label(__('subscribers.notes.label'))
                ->helperText(__('subscribers.notes.help'))
                ->rows(4)
                // 10k CHARACTERS, not bytes: MySQL TEXT holds 65,535 BYTES and
                // a CJK/emoji note costs up to 4 bytes a character (D-03).
                ->maxLength(10000)
                ->columnSpanFull(),
        ];

        foreach ($this->profileFields() as $field) {
            $fields[] = ListFieldInputs::make($field);
        }

        return $form->schema($fields)->columns(2)->statePath('data');
    }

    public function saveFields(): void
    {
        abort_unless(static::getResource()::canEdit($this->getSubscriber()), 403);

        $data = $this->form->getState();

        $subscriber = $this->getSubscriber();
        $subscriber->email = mb_strtolower(trim((string) $data['email']));
        $subscriber->notes = filled($data['notes'] ?? null) ? (string) $data['notes'] : null;
        $subscriber->save();

        $this->syncTags($subscriber, (array) ($data['tags'] ?? []));

        $service = app(FieldValueService::class);
        $values = (array) ($data['field_values'] ?? []);

        foreach ($this->profileFields() as $field) {
            if (array_key_exists($field->id, $values)) {
                $service->set($subscriber, $field, $values[$field->id]);
            }
        }

        Notification::make()->title(__('subscribers.profile.saved'))->success()->send();
    }

    /**
     * Apply the tag editor's state as a diff (F-2.2.5-004).
     *
     * Every add and every removal goes through SubscriberTagService, never a
     * bulk write, because the tag model's created/deleted events are what the
     * F-2.4.2-007 automation triggers listen to: a tag added by an operator
     * has to start the same automation a tag added by a workflow would.
     *
     * @param  array<int, mixed>  $submitted
     */
    private function syncTags(Subscriber $subscriber, array $submitted): void
    {
        $service = app(SubscriberTagService::class);

        $wanted = [];

        foreach ($submitted as $name) {
            $normalized = SubscriberTag::normalize((string) $name);

            if ($normalized !== '') {
                $wanted[$normalized] = true;
            }
        }

        $current = $service->tagsFor($subscriber);

        foreach (array_diff($current, array_keys($wanted)) as $stale) {
            $service->detach($subscriber, $stale);
        }

        foreach (array_diff(array_keys($wanted), $current) as $fresh) {
            $service->attach($subscriber, $fresh);
        }
    }

    /* ================================================================ *
     *  Profile panels (F-2.2.5-005 / -006 / -012)
     * ================================================================ */

    /**
     * Merged reverse-chronological campaign activity (F-2.2.5-005).
     *
     * @return list<array{type: string, at: Carbon|null, campaign_id: int|null, campaign_name: string|null, detail: string|null, id: int}>
     */
    public function timeline(): array
    {
        return app(SubscriberActivityTimeline::class)->events($this->getSubscriber());
    }

    /** @return array{delivered: int, opens: int, clicks: int, unsubscribes: int, bounces: int, complaints: int} */
    public function timelineSummary(): array
    {
        return app(SubscriberActivityTimeline::class)->summary($this->getSubscriber());
    }

    /**
     * Engagement score + rating (F-2.2.5-006).
     *
     * @return array{score: int, rating: string, points: float, opens: int, clicks: int, bounces: int, complaints: int, unsubscribes: int, weighted_opens: float, weighted_clicks: float}
     */
    public function engagement(): array
    {
        return app(EngagementScorer::class)->score($this->getSubscriber());
    }

    /**
     * Every list of THIS customer carrying this email address (F-2.2.5-012).
     *
     * @return list<array{subscriber_id: int, list_id: int, list_name: string, status: SubscriberStatus, subscribed_at: Carbon|null, current: bool}>
     */
    public function listMemberships(): array
    {
        return app(SubscriberListMemberships::class)->for($this->getSubscriber());
    }

    /** @return array<int, Actions\Action> */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('edit')
                ->label(__('subscribers.actions.edit'))
                ->url(fn (): string => SubscriberResource::getUrl('edit', ['record' => $this->getSubscriber()])),
            Actions\Action::make('unsubscribe')
                ->label(__('subscribers.actions.unsubscribe'))
                ->icon('heroicon-m-minus-circle')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->getSubscriber()->status !== SubscriberStatus::Unsubscribed)
                ->action(function (): void {
                    SubscriberResource::transition($this->getSubscriber(), SubscriberStatus::Unsubscribed);
                }),
            Actions\Action::make('blacklist')
                ->label(__('subscribers.actions.blacklist'))
                ->icon('heroicon-m-no-symbol')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->getSubscriber()->status !== SubscriberStatus::Blacklisted)
                ->action(function (): void {
                    SubscriberResource::transition($this->getSubscriber(), SubscriberStatus::Blacklisted);
                }),
            $this->sendSingleEmailAction(),
            $this->addToAutomationAction(),
            $this->addToSegmentAction(),
            // Export all data as JSON (F-2.2.5-010, GDPR portability).
            Actions\Action::make('export_json')
                ->label(__('subscribers.actions.export_json'))
                ->icon('heroicon-m-arrow-down-tray')
                ->action(fn (): StreamedResponse => response()->streamDownload(
                    function (): void {
                        echo json_encode($this->exportPayload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    },
                    'subscriber-'.$this->getSubscriber()->uid.'.json',
                    ['Content-Type' => 'application/json'],
                )),
            // GDPR right-to-erasure as a queued job (F-2.2.5-010).
            Actions\Action::make('gdpr_erase')
                ->label(__('subscribers.actions.gdpr_erase'))
                ->icon('heroicon-m-shield-exclamation')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(__('subscribers.profile.gdpr_heading'))
                ->modalDescription(__('subscribers.profile.gdpr_description'))
                ->action(function (): void {
                    $subscriber = $this->getSubscriber();

                    GdprEraseSubscriberJob::dispatch(
                        (int) $subscriber->customer_id,
                        (int) $subscriber->id,
                        (string) Str::uuid(),
                    );

                    Notification::make()->title(__('subscribers.actions.queued'))->success()->send();
                }),
            Actions\DeleteAction::make()
                ->record($this->getSubscriber())
                ->successRedirectUrl(SubscriberResource::getUrl('index')),
        ];
    }

    /**
     * Consent record (F-2.2.5-008): what was consented to, when, where from.
     *
     * @return array{text: ?string, logging: bool, granted: list<array{label: string, value: string}>, at: ?Carbon, ip: ?string}
     */
    public function consentRecord(): array
    {
        $subscriber = $this->getSubscriber();
        $list = $subscriber->list;
        $service = app(FieldValueService::class);

        $granted = [];

        foreach ($list?->fields ?? [] as $field) {
            if ($field->type === ListFieldType::Consent) {
                $value = $service->get($subscriber, $field);
                $granted[] = [
                    'label' => $field->label,
                    'value' => $value === true
                        ? __('subscribers.profile.consent_granted')
                        : __('subscribers.profile.consent_not_granted'),
                ];
            }
        }

        return [
            'text' => $list?->gdpr_consent_text,
            'logging' => (bool) ($list?->gdpr_consent_logging ?? false),
            'granted' => $granted,
            'at' => $subscriber->subscribed_at,
            'ip' => $subscriber->subscribe_ip ?? $subscriber->ip,
        ];
    }

    /* ================================================================ *
     *  Profile actions (F-2.2.5-010)
     * ================================================================ */

    /**
     * Send one ad-hoc email to this subscriber. Queued (docs/ARCHITECTURE.md rule 5) and
     * rendered by the canonical merge-tag engine inside the job, so the body
     * a subscriber receives is built exactly like a campaign's.
     *
     * Offered only for ACTIVE subscribers (confirmed/unconfirmed): mailing an
     * unsubscribed or blacklisted address from a one-off button would be the
     * easiest possible way to break CAN-SPAM/GDPR compliance.
     */
    private function sendSingleEmailAction(): Actions\Action
    {
        return Actions\Action::make('send_email')
            ->label(__('subscribers.actions.send_email'))
            ->icon('heroicon-m-paper-airplane')
            ->visible(fn (): bool => $this->getSubscriber()->status->isActive())
            ->form([
                TextInput::make('subject')
                    ->label(__('subscribers.actions.email_subject'))
                    ->required()
                    ->maxLength(500),
                Textarea::make('html')
                    ->label(__('subscribers.actions.email_body'))
                    ->helperText(__('subscribers.actions.email_body_help'))
                    ->rows(8)
                    ->required(),
            ])
            ->action(function (array $data): void {
                $subscriber = $this->getSubscriber();

                SendSingleEmailJob::dispatch(
                    (int) $subscriber->customer_id,
                    (int) $subscriber->id,
                    (string) $data['subject'],
                    (string) $data['html'],
                    (string) Str::uuid(),
                );

                Notification::make()->title(__('subscribers.actions.email_queued'))->success()->send();
            });
    }

    /**
     * Enrol this subscriber into one of the tenant's automations through the
     * WP-21 Enroller, so the F-2.4.2-043 re-entry rules apply exactly as they
     * do for a real trigger. A refused entry (already enrolled, paused
     * automation, cooldown) is reported honestly instead of faked as success.
     */
    private function addToAutomationAction(): Actions\Action
    {
        return Actions\Action::make('add_to_automation')
            ->label(__('subscribers.actions.add_to_automation'))
            ->icon('heroicon-m-bolt')
            ->visible(fn (): bool => class_exists(Enroller::class) && Automation::query()->exists())
            ->form([
                Select::make('automation_id')
                    ->label(__('subscribers.actions.automation'))
                    ->options(fn (): array => Automation::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->required(),
            ])
            ->action(function (array $data): void {
                // Tenant-scoped find: a forged id resolves to null.
                $automation = Automation::query()->find((int) $data['automation_id']);

                if ($automation === null) {
                    Notification::make()->title(__('subscribers.actions.automation_missing'))->danger()->send();

                    return;
                }

                $run = app(Enroller::class)->enter($automation, $this->getSubscriber(), [], 'manual');

                if ($run === null) {
                    Notification::make()->title(__('subscribers.actions.automation_refused'))->warning()->send();

                    return;
                }

                Notification::make()->title(__('subscribers.actions.automation_entered'))->success()->send();
            });
    }

    /**
     * "Add to segment" (F-2.2.5-010) — deliberately NOT an add.
     *
     * Per D-32 segments in this platform are condition-based and dynamic
     * (F-2.2.10-012): membership is recomputed at read time and never stored,
     * so there is no row to insert a subscriber into. Static/frozen segments
     * are the P2 row F-2.2.10-013. Rather than pretend, this action explains
     * the model and sends the operator to the two things that DO work:
     * the list's segment manager (build a condition that matches this person)
     * and the profile's own field editor.
     */
    private function addToSegmentAction(): Actions\Action
    {
        return Actions\Action::make('add_to_segment')
            ->label(__('subscribers.actions.add_to_segment'))
            ->icon('heroicon-m-funnel')
            ->modalHeading(__('subscribers.actions.add_to_segment_heading'))
            ->modalDescription(__('subscribers.actions.add_to_segment_explanation'))
            ->modalSubmitActionLabel(__('subscribers.actions.add_to_segment_open'))
            ->visible(fn (): bool => $this->getSubscriber()->list !== null)
            ->action(function (): void {
                $list = $this->getSubscriber()->list;

                if ($list === null) {
                    return;
                }

                $this->redirect(EmailListResource::getUrl('segments', ['record' => $list]));
            });
    }

    /**
     * Resolved geo location + timezone for the profile (F-2.2.5-003).
     *
     * Order of truth, most authoritative first:
     *  1. a live IpLocationResolver lookup of the subscriber's captured IP
     *     (subscribe → generic → confirm), when a provider is configured;
     *  2. the geo columns already stamped on this subscriber's most recent
     *     tracking hit (WP-24 writes them at capture time) — real recorded
     *     data, so it stays useful after a provider is switched off;
     *  3. nothing.
     *
     * Timezone only ever comes from (1): no tracking column stores it.
     * `declared` is the subscriber's self-declared country custom field, which
     * is a claim rather than a measurement and is therefore labelled apart.
     *
     * When no provider is configured every resolved field stays null and the
     * page says so — D-10: an honest "unknown" beats an invented location.
     *
     * @return array{configured: bool, ip: ?string, country: ?string, region: ?string, city: ?string, timezone: ?string, declared: ?string}
     */
    public function geoLocation(): array
    {
        $subscriber = $this->getSubscriber();
        $resolver = app(IpLocationResolver::class);

        $ip = $subscriber->subscribe_ip ?? $subscriber->ip ?? $subscriber->confirm_ip;

        $result = [
            'configured' => $resolver->enabled(),
            'ip' => $ip,
            'country' => null,
            'region' => null,
            'city' => null,
            'timezone' => null,
            'declared' => $this->geoHint(),
        ];

        if ($result['configured'] && $ip !== null && $ip !== '') {
            $location = $resolver->locate($ip);

            if ($location !== null) {
                $result['country'] = $location->countryName ?? $location->countryCode;
                $result['region'] = $location->regionName ?? $location->regionCode;
                $result['city'] = $location->city;
                $result['timezone'] = $location->timezone;
            }
        }

        if ($result['country'] === null) {
            $tracked = $this->latestTrackedLocation($subscriber->uid);

            $result['country'] = $tracked['country'];
            $result['region'] ??= $tracked['region'];
            $result['city'] ??= $tracked['city'];
        }

        return $result;
    }

    /**
     * Newest geo-tagged open or click for this subscriber uid.
     *
     * @return array{country: ?string, region: ?string, city: ?string}
     */
    private function latestTrackedLocation(string $subscriberUid): array
    {
        $empty = ['country' => null, 'region' => null, 'city' => null];

        $newest = null;

        foreach ([CampaignTrackOpen::query(), CampaignTrackClick::query()] as $query) {
            $row = $query->where('subscriber_uid', $subscriberUid)
                ->whereNotNull('country')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first(['country', 'region', 'city', 'created_at']);

            if ($row === null) {
                continue;
            }

            if ($newest === null || $row->created_at > $newest->created_at) {
                $newest = $row;
            }
        }

        if ($newest === null) {
            return $empty;
        }

        return [
            'country' => $newest->country,
            'region' => $newest->region,
            'city' => $newest->city,
        ];
    }

    /**
     * Self-declared country from the list's Country custom field
     * (F-2.2.5-003) — a claim, not a measurement.
     */
    public function geoHint(): ?string
    {
        $subscriber = $this->getSubscriber();
        $service = app(FieldValueService::class);

        foreach ($subscriber->list?->fields ?? [] as $field) {
            if ($field->type === ListFieldType::Country) {
                $value = $service->get($subscriber, $field);

                if (is_string($value) && $value !== '') {
                    return Countries::all()[$value] ?? $value;
                }
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function exportPayload(): array
    {
        $subscriber = $this->getSubscriber();

        return [
            'uid' => $subscriber->uid,
            'email' => $subscriber->email,
            'list' => $subscriber->list?->name,
            'status' => $subscriber->status->value,
            'source' => $subscriber->source?->value,
            'ip' => $subscriber->ip,
            'subscribed_at' => $subscriber->subscribed_at?->toIso8601String(),
            'subscribe_ip' => $subscriber->subscribe_ip,
            'confirmed_at' => $subscriber->confirmed_at?->toIso8601String(),
            'confirm_ip' => $subscriber->confirm_ip,
            'unsubscribed_at' => $subscriber->unsubscribed_at?->toIso8601String(),
            'unsubscribe_ip' => $subscriber->unsubscribe_ip,
            'unsubscribe_reason' => $subscriber->unsubscribe_reason,
            'tags' => app(SubscriberTagService::class)->tagsFor($subscriber),
            'lists' => array_map(
                fn (array $row): array => [
                    'list' => $row['list_name'],
                    'status' => $row['status']->value,
                    'subscribed_at' => $row['subscribed_at']?->toIso8601String(),
                ],
                $this->listMemberships(),
            ),
            'fields' => collect(app(FieldValueService::class)->byTag($subscriber))
                ->map(fn (mixed $value): mixed => $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value)
                ->all(),
        ];
    }

    private function fillFieldForm(): void
    {
        $subscriber = $this->getSubscriber();
        $service = app(FieldValueService::class);

        $values = [];

        foreach ($this->profileFields() as $field) {
            $values[$field->id] = $service->formValue($field, $service->raw($subscriber, $field));
        }

        $this->form->fill([
            'email' => $subscriber->email,
            'tags' => app(SubscriberTagService::class)->tagsFor($subscriber),
            'notes' => $subscriber->notes,
            'field_values' => $values,
        ]);
    }

    /** @return list<ListField> */
    private function profileFields(): array
    {
        return $this->getSubscriber()->list
            ?->fields()->where('visible_on_profile', true)->with('options')->get()->all() ?? [];
    }
}
