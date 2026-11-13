<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources;

use App\Enums\AbDecisionMode;
use App\Enums\AbTestVariable;
use App\Enums\AbWinnerCriteria;
use App\Enums\AutoresponderDelayUnit;
use App\Enums\AutoresponderTrigger;
use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Enums\RecurringInterval;
use App\Enums\TemplateBuilder;
use App\Filament\Customer\Resources\CampaignResource\Pages;
use App\Jobs\Sending\RefreshSendTimeProfilesJob;
use App\Models\Campaign;
use App\Models\CampaignFilter;
use App\Models\CampaignGroup;
use App\Models\CampaignTrackUrl;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\EmailTemplate;
use App\Models\ListField;
use App\Models\ListSegment;
use App\Models\SpintaxSnippet;
use App\Models\StoreCouponBatch;
use App\Sending\Pipeline\SendTimeOptimizer;
use App\Services\Campaigns\AbTest\AbTestManager;
use App\Services\Campaigns\AbTest\MultivariateDesign;
use App\Services\Campaigns\BlacklistedWordScanner;
use App\Services\Campaigns\CampaignLinkValidator;
use App\Services\Campaigns\CampaignPreflight;
use App\Services\Campaigns\CampaignQuota;
use App\Services\Campaigns\CampaignRecipients;
use App\Services\Campaigns\CampaignSpamScore;
use App\Services\Campaigns\CampaignTagger;
use App\Services\Campaigns\CampaignTestSender;
use App\Services\Campaigns\CampaignWebhookNotifier;
use App\Services\Campaigns\RecurringSchedule;
use App\Services\Content\AmpValidator;
use App\Services\Content\MergeTags;
use App\Services\Content\PlainTextGenerator;
use App\Services\Content\Spintax;
use App\Services\Quota\QuotaService;
use App\Services\Settings\SettingsReader;
use App\Services\Templates\Builders\BuilderRegistry;
use App\Support\Quota\CustomerGroupMatrix;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Campaigns resource (spec §2.3, WP-11/12). The 4-step wizard (§2.3.2–5)
 * is shared between the Create page (HasWizard) and the Edit page (skippable
 * Wizard in form()); the index with tabs/actions lives on ListCampaigns.
 */
class CampaignResource extends Resource
{
    // WP-37 §2.15.8 — AI subject/body/rewrite actions on the wizard. Every
    // one of them is hidden when no AI engine is available.

    protected static ?string $model = Campaign::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $slug = 'campaigns';

    protected static ?int $navigationSort = 20;

    public static function getModelLabel(): string
    {
        return __('campaigns.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('campaigns.resource.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('campaigns.resource.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('campaigns.resource.navigation_group');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // Defence in depth on top of CustomerScope: pin the tenant from the
        // authenticated customer, never from UI state (docs/ARCHITECTURE.md rule 3).
        $user = Filament::auth()->user();

        if ($user instanceof Customer) {
            $query->where('customer_id', $user->id);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        // The Edit page renders the same wizard, skippable so any step is
        // directly reachable (F-2.3.2..5); Create uses HasWizard::getSteps().
        return $form->schema([
            Forms\Components\Wizard::make(self::wizardSteps())
                ->skippable()
                ->columnSpanFull(),
        ]);
    }

    /** @return array<int, Forms\Components\Wizard\Step> */
    public static function wizardSteps(): array
    {
        return [
            Forms\Components\Wizard\Step::make(__('campaigns.steps.details'))
                ->schema(self::detailsSchema()),
            Forms\Components\Wizard\Step::make(__('campaigns.steps.content'))
                ->schema(self::contentSchema()),
            // WP-35 §2.3.6 — the side-by-side variant composer (F-2.3.6-012)
            // sits right after Content, because a content/subject test is
            // written against the campaign body you have just authored.
            Forms\Components\Wizard\Step::make(__('campaigns.ab.step'))
                ->schema(self::abTestSchema()),
            Forms\Components\Wizard\Step::make(__('campaigns.steps.options'))
                ->schema(self::optionsSchema()),
            Forms\Components\Wizard\Step::make(__('campaigns.steps.confirmation'))
                ->schema(self::confirmationSchema()),
        ];
    }

    /* ================================================================ *
     *  Step 1 — Details (§2.3.2)
     * ================================================================ */

    /** @return array<int, Component> */
    public static function detailsSchema(): array
    {
        return [
            Forms\Components\Section::make(__('campaigns.steps.details'))
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label(__('campaigns.fields.name'))
                        ->required()
                        ->maxLength(255),

                    Forms\Components\Select::make('type')
                        ->label(__('campaigns.fields.type'))
                        // §3.5 matrix: only group-allowed types are offered.
                        ->options(fn (): array => collect(app(CampaignQuota::class)->allowedTypes(self::customer()))
                            ->mapWithKeys(fn (CampaignType $type): array => [$type->value => $type->label()])
                            ->all())
                        ->default(CampaignType::Regular->value)
                        ->required()
                        ->live(),

                    Forms\Components\Select::make('campaign_group_id')
                        ->label(__('campaigns.fields.group'))
                        ->options(fn (): array => CampaignGroup::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->nullable(),

                    Forms\Components\Select::make('email_list_id')
                        ->label(__('campaigns.fields.list'))
                        ->options(fn (): array => EmailList::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->required()
                        ->live(),

                    Forms\Components\Select::make('list_segment_id')
                        ->label(__('campaigns.fields.segment'))
                        // Segment options are scoped to the selected list.
                        ->options(fn (Get $get): array => $get('email_list_id')
                            ? ListSegment::query()
                                ->where('list_id', (int) $get('email_list_id'))
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all()
                            : [])
                        ->nullable()
                        ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            if ($value !== null && ! ListSegment::query()
                                ->whereKey((int) $value)
                                ->where('list_id', (int) $get('email_list_id'))
                                ->exists()) {
                                $fail(__('campaigns.errors.segment_list_mismatch'));
                            }
                        })
                        ->visible(fn (): bool => app(QuotaService::class)
                            ->allows(self::customer(), 'campaigns.send_to_segment')),

                    // F-2.3.2-006 — additional target lists. The primary list
                    // above stays authoritative for merge tags and the footer;
                    // these widen the audience and are de-duplicated by email
                    // inside CampaignRecipients.
                    Forms\Components\Select::make('extra_list_ids')
                        ->label(__('campaigns.lists.extra'))
                        ->helperText(__('campaigns.lists.extra_help'))
                        ->multiple()
                        ->options(fn (): array => EmailList::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->live()
                        ->dehydrated(false)
                        ->afterStateHydrated(function (Forms\Components\Select $component, ?Campaign $record): void {
                            $component->state($record?->targetLists()->pluck('lists.id')->all() ?? []);
                        }),

                    // F-2.3.2-004 / F-2.3.10-002 — free-form tags with
                    // autocomplete over the tenant's existing tags.
                    Forms\Components\TagsInput::make('campaign_tags')
                        ->label(__('campaigns.tags.label'))
                        ->placeholder(__('campaigns.tags.placeholder'))
                        ->suggestions(fn (): array => array_values(
                            app(CampaignTagger::class)->options(self::customer()->id)
                        ))
                        ->dehydrated(false)
                        ->afterStateHydrated(function (Forms\Components\TagsInput $component, ?Campaign $record): void {
                            $component->state($record === null
                                ? []
                                : app(CampaignTagger::class)->namesFor($record));
                        }),
                ]),

            Forms\Components\Section::make(__('campaigns.fields.from_name'))
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('from_name')
                        ->label(__('campaigns.fields.from_name'))
                        ->helperText(self::mergeTagHint())
                        ->maxLength(255),
                    Forms\Components\TextInput::make('from_email')
                        ->label(__('campaigns.fields.from_email'))
                        ->rule(fn (): Closure => self::emailWithTagsRule(SettingsReader::campaign()->forbiddenFromDomains()))
                        ->maxLength(255),
                    Forms\Components\TextInput::make('reply_to')
                        ->label(__('campaigns.fields.reply_to'))
                        ->rule(fn (): Closure => self::emailWithTagsRule(SettingsReader::campaign()->forbiddenReplyToDomains()))
                        ->maxLength(255),
                    Forms\Components\TextInput::make('to_name')
                        ->label(__('campaigns.fields.to_name'))
                        ->helperText(self::mergeTagHint())
                        ->maxLength(255),

                    Forms\Components\TextInput::make('subject')
                        ->label(__('campaigns.fields.subject'))
                        ->maxLength(500)
                        ->live(onBlur: true)
                        // Emoji picker (F-2.3.2-008): quick-insert suffix action.
                        ->suffixAction(
                            Forms\Components\Actions\Action::make('emoji')
                                ->icon('heroicon-m-face-smile')
                                ->form([
                                    Forms\Components\Select::make('emoji')
                                        ->label('Emoji')
                                        ->options(array_combine(self::EMOJIS, self::EMOJIS))
                                        ->required(),
                                ])
                                ->action(function (array $data, Get $get, Set $set): void {
                                    $set('subject', (string) $get('subject').$data['emoji']);
                                })
                        )
                        // WP-37 §2.15.8 F-2.15.8-002 — AI subject lines with
                        // predicted performance. hintAction() is free here;
                        // suffixAction() already belongs to the emoji picker.
                        // Inline spam-word warning (F-2.3.2-013).
                        ->hint(function (Get $get): ?HtmlString {
                            $hits = app(BlacklistedWordScanner::class)->subjectMatches($get('subject'));

                            if ($hits === []) {
                                return null;
                            }

                            $key = app(BlacklistedWordScanner::class)->blocks()
                                ? 'campaigns.warnings.spam_words_blocked'
                                : 'campaigns.warnings.spam_words';

                            return new HtmlString('<span class="text-danger-600 dark:text-danger-400">'
                                .e(__($key, ['words' => implode(', ', $hits)])).'</span>');
                        })
                        // Block mode (§3.25.3): a hit is a hard validation error.
                        ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            $scanner = app(BlacklistedWordScanner::class);
                            $hits = $scanner->subjectMatches(is_string($value) ? $value : null);

                            if ($hits !== [] && $scanner->blocks()) {
                                $fail(__('campaigns.warnings.spam_words_blocked', ['words' => implode(', ', $hits)]));
                            }
                        })
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('preheader')
                        ->label(__('campaigns.fields.preheader'))
                        ->maxLength(500)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make(__('campaigns.fields.send_at'))
                ->columns(2)
                ->visible(fn (Get $get): bool => $get('type') === CampaignType::Regular->value)
                ->schema([
                    Forms\Components\Toggle::make('send_immediately')
                        ->label(__('campaigns.fields.send_immediately'))
                        ->live()
                        ->columnSpanFull(),
                    Forms\Components\DateTimePicker::make('send_at')
                        ->label(__('campaigns.fields.send_at'))
                        ->seconds(false)
                        ->hidden(fn (Get $get): bool => (bool) $get('send_immediately'))
                        // §3.25.3 minimum future offset (D-23: 0 = disabled).
                        ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            if ($value === null || (bool) $get('send_immediately')) {
                                return;
                            }

                            $offset = SettingsReader::campaign()->sendAtMinOffsetMinutes();

                            if ($offset > 0 && Carbon::parse((string) $value)->lessThan(Carbon::now()->addMinutes($offset))) {
                                $fail(__('campaigns.errors.send_at_offset', ['minutes' => $offset]));
                            }
                        }),
                    Forms\Components\Select::make('timezone')
                        ->label(__('campaigns.fields.timezone'))
                        ->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                        ->default(fn (): string => self::customer()->timezone ?? 'UTC')
                        ->searchable()
                        ->hidden(fn (Get $get): bool => (bool) $get('send_immediately')),
                ]),

            self::autoresponderSection(),
            self::recurringSection(),
        ];
    }

    private static function autoresponderSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make(__('campaigns.autoresponder.section'))
            ->columns(2)
            ->visible(fn (Get $get): bool => $get('type') === CampaignType::Autoresponder->value)
            ->schema([
                Forms\Components\Select::make('ar_trigger')
                    ->label(__('campaigns.autoresponder.trigger.label'))
                    ->options(AutoresponderTrigger::options())
                    ->required(fn (Get $get): bool => $get('type') === CampaignType::Autoresponder->value)
                    ->live(),

                Forms\Components\Select::make('ar_trigger_campaign_id')
                    ->label(__('campaigns.autoresponder.fields.trigger_campaign'))
                    ->options(fn (?Campaign $record): array => Campaign::query()
                        ->when($record?->exists, fn (Builder $q) => $q->whereKeyNot($record->getKey()))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->visible(fn (Get $get): bool => in_array($get('ar_trigger'), [
                        AutoresponderTrigger::AfterCampaignOpen->value,
                        AutoresponderTrigger::AfterCampaignSent->value,
                    ], true)),

                Forms\Components\Select::make('ar_date_field_id')
                    ->label(__('campaigns.autoresponder.fields.date_field'))
                    ->options(fn (Get $get): array => $get('email_list_id')
                        ? ListField::query()
                            ->where('list_id', (int) $get('email_list_id'))
                            ->whereIn('type', ['date', 'datetime'])
                            ->pluck('label', 'id')
                            ->all()
                        : [])
                    ->visible(fn (Get $get): bool => $get('ar_trigger') === AutoresponderTrigger::OnDateField->value),

                Forms\Components\TextInput::make('ar_delay_value')
                    ->label(__('campaigns.autoresponder.fields.delay_value'))
                    ->numeric()
                    ->minValue(0)
                    ->default(0),

                Forms\Components\Select::make('ar_delay_unit')
                    ->label(__('campaigns.autoresponder.fields.delay_unit'))
                    ->options(AutoresponderDelayUnit::options())
                    ->default(AutoresponderDelayUnit::Hours->value),

                Forms\Components\TimePicker::make('ar_send_time')
                    ->label(__('campaigns.autoresponder.fields.send_time'))
                    ->seconds(false),

                Forms\Components\Radio::make('ar_apply_to')
                    ->label(__('campaigns.autoresponder.fields.apply_to'))
                    ->options([
                        'existing' => __('campaigns.autoresponder.apply_to.existing'),
                        'new' => __('campaigns.autoresponder.apply_to.new'),
                    ])
                    ->default('new'),

                Forms\Components\Toggle::make('ar_once_per_subscriber')
                    ->label(__('campaigns.autoresponder.fields.once_per_subscriber'))
                    ->default(true),
            ]);
    }

    private static function recurringSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make(__('campaigns.recurring.section'))
            ->columns(2)
            ->visible(fn (Get $get): bool => $get('type') === CampaignType::Recurring->value)
            ->schema([
                Forms\Components\Select::make('rec_interval')
                    ->label(__('campaigns.recurring.interval.label'))
                    ->options(RecurringInterval::options())
                    ->required(fn (Get $get): bool => $get('type') === CampaignType::Recurring->value)
                    ->live(),

                Forms\Components\TextInput::make('rec_cron')
                    ->label(__('campaigns.recurring.fields.cron'))
                    ->placeholder('*/30 * * * *')
                    ->visible(fn (Get $get): bool => $get('rec_interval') === RecurringInterval::CustomCron->value)
                    ->live(onBlur: true)
                    // Validated with Cron\CronExpression (F-2.3.2-019).
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (is_string($value) && $value !== '' && ! app(RecurringSchedule::class)->isValidCron($value)) {
                            $fail(__('campaigns.recurring.cron_invalid'));
                        }
                    }),

                Forms\Components\CheckboxList::make('rec_days_of_week')
                    ->label(__('campaigns.recurring.fields.days_of_week'))
                    ->options(__('campaigns.recurring.days'))
                    ->columns(4)
                    ->visible(fn (Get $get): bool => in_array($get('rec_interval'), [
                        RecurringInterval::Daily->value,
                        RecurringInterval::Weekly->value,
                    ], true))
                    ->live()
                    ->columnSpanFull(),

                Forms\Components\TextInput::make('rec_day_of_month')
                    ->label(__('campaigns.recurring.fields.day_of_month'))
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(31)
                    ->live(onBlur: true)
                    ->visible(fn (Get $get): bool => in_array($get('rec_interval'), [
                        RecurringInterval::Monthly->value,
                        RecurringInterval::Yearly->value,
                    ], true)),

                Forms\Components\DateTimePicker::make('rec_starts_at')
                    ->label(__('campaigns.recurring.fields.starts_at'))
                    ->seconds(false)
                    ->live(onBlur: true),

                Forms\Components\DateTimePicker::make('rec_ends_at')
                    ->label(__('campaigns.recurring.fields.ends_at'))
                    ->seconds(false)
                    ->after('rec_starts_at')
                    ->live(onBlur: true),

                Forms\Components\TextInput::make('rec_max_occurrences')
                    ->label(__('campaigns.recurring.fields.max_occurrences'))
                    ->numeric()
                    ->minValue(1),

                Forms\Components\Select::make('timezone')
                    ->label(__('campaigns.fields.timezone'))
                    ->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                    ->default(fn (): string => self::customer()->timezone ?? 'UTC')
                    ->searchable(),

                // Next-run preview (F-2.3.2-022), computed live from state.
                Forms\Components\Placeholder::make('next_run_preview')
                    ->label(__('campaigns.recurring.next_runs'))
                    ->content(function (Get $get, ?Campaign $record): HtmlString {
                        $campaign = self::stateCampaign($get, $record);
                        $runs = app(RecurringSchedule::class)->nextRunPreview($campaign, 3);

                        if ($runs === []) {
                            return new HtmlString(e(__('campaigns.recurring.no_next_runs')));
                        }

                        return new HtmlString(implode('<br>', array_map(
                            fn (Carbon $run): string => e($run->format('D, M j Y H:i (T)')),
                            $runs,
                        )));
                    })
                    ->columnSpanFull(),
            ]);
    }

    /* ================================================================ *
     *  Step 2 — Content (§2.3.3)
     * ================================================================ */

    /** @return array<int, Component> */
    public static function contentSchema(): array
    {
        return [
            Forms\Components\Section::make(__('campaigns.fields.content_source'))
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('content_source')
                        ->label(__('campaigns.fields.content_source'))
                        ->options([
                            'blank' => __('campaigns.content_source.blank'),
                            'template' => __('campaigns.content_source.template'),
                            'gallery' => __('campaigns.content_source.gallery'),
                            'campaign' => __('campaigns.content_source.campaign'),
                            'upload' => __('campaigns.content_source.upload'),
                            'url' => __('campaigns.content_source.url'),
                        ])
                        ->default(fn (string $operation): ?string => $operation === 'create' ? 'blank' : null)
                        ->live()
                        ->dehydrated(false),

                    Forms\Components\Select::make('source_template_id')
                        ->label(__('campaigns.fields.source_template'))
                        ->options(fn (Get $get): array => ($get('content_source') === 'gallery'
                            ? EmailTemplate::browsable()->whereNull('customer_id')
                            : EmailTemplate::query())
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->visible(fn (Get $get): bool => in_array($get('content_source'), ['template', 'gallery'], true))
                        ->dehydrated(false),

                    Forms\Components\Select::make('source_campaign_id')
                        ->label(__('campaigns.fields.source_campaign'))
                        ->options(fn (?Campaign $record): array => Campaign::query()
                            ->when($record?->exists, fn (Builder $q) => $q->whereKeyNot($record->getKey()))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->visible(fn (Get $get): bool => $get('content_source') === 'campaign')
                        ->dehydrated(false),

                    // ZIP import — reuses the WP-10 TemplateZipImporter (F-2.3.3-005).
                    Forms\Components\FileUpload::make('zip_upload')
                        ->label(__('campaigns.fields.zip_file'))
                        ->disk('public')
                        ->directory('tmp/campaign-zips')
                        ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed'])
                        ->maxSize((int) config('emp-campaigns.zip_max_size_mb', 20) * 1024)
                        ->visible(fn (Get $get): bool => $get('content_source') === 'upload'),

                    // URL import — reuses the WP-10 TemplateUrlImporter (F-2.3.3-005).
                    Forms\Components\TextInput::make('source_url')
                        ->label(__('campaigns.fields.source_url'))
                        ->url()
                        ->placeholder('https://example.com/newsletter.html')
                        ->visible(fn (Get $get): bool => $get('content_source') === 'url')
                        ->dehydrated(false),

                    Forms\Components\Select::make('builder')
                        ->label(__('campaigns.fields.builder'))
                        ->options(fn (): array => app(BuilderRegistry::class)->options())
                        ->default(fn (): string => app(BuilderRegistry::class)->defaultFor(self::customer())->value)
                        ->in(array_keys(app(BuilderRegistry::class)->options()))
                        ->live(),

                    Forms\Components\Placeholder::make('open_builder_hint')
                        ->label(__('campaigns.actions.open_builder'))
                        ->content(function (?Campaign $record, string $operation): HtmlString {
                            if ($operation === 'create' || $record === null) {
                                return new HtmlString(e(__('campaigns.help.builder_after_create')));
                            }

                            // Each visual builder has its own page: GrapesJS
                            // (D-11) and the WP-27B basic block builder.
                            $url = self::getUrl(
                                $record->builder === TemplateBuilder::Basic ? 'basic' : 'content',
                                ['record' => $record],
                            );

                            return new HtmlString(
                                '<a href="'.e($url).'" class="fi-link text-primary-600 font-medium">'
                                .e(__('campaigns.actions.open_builder')).' →</a>'
                            );
                        })
                        ->visible(fn (Get $get): bool => in_array(
                            $get('builder'),
                            [TemplateBuilder::GrapesJs->value, TemplateBuilder::Basic->value],
                            true,
                        )),
                ]),

            Forms\Components\Section::make(__('campaigns.fields.content'))
                ->schema([
                    // Raw HTML editor (F-2.3.3-003).
                    Forms\Components\Textarea::make('content_html')
                        ->label(__('campaigns.fields.content'))
                        ->rows(20)
                        ->extraAttributes(['class' => 'font-mono text-xs'])
                        ->nullable()
                        // WP-37 §2.15.8 F-2.15.8-003/004 — draft body copy
                        // from a brief, and rewrite/shorten/lengthen/tone.
                        // Both hide themselves when no AI engine is available.
                        // Builders that own their own editing surface never
                        // expose the raw textarea (it would fight the builder).
                        ->visible(fn (Get $get): bool => ! in_array($get('builder'), [
                            TemplateBuilder::GrapesJs->value,
                            TemplateBuilder::Basic->value,
                            TemplateBuilder::RichText->value,
                        ], true)),

                    // Rich text editor for simple emails (F-2.3.3-004).
                    Forms\Components\RichEditor::make('content_html')
                        ->label(__('campaigns.fields.rich_content'))
                        ->nullable()
                        // Same AI actions on the rich-text surface — a fresh
                        // set, because an Action instance belongs to one
                        // component.
                        ->visible(fn (Get $get): bool => $get('builder') === TemplateBuilder::RichText->value),

                    // Merge-tag inserter panel (F-2.3.3-007).
                    Forms\Components\Placeholder::make('merge_tags_panel')
                        ->label(__('campaigns.fields.merge_tags'))
                        ->content(fn (Get $get): HtmlString => new HtmlString(
                            view('filament.customer.campaigns.merge-tags', [
                                'tags' => self::mergeTagsFor($get('email_list_id') !== null ? (int) $get('email_list_id') : null),
                            ])->render()
                        )),

                    // Spintax snippet library insert (F-2.3.3-023).
                    Forms\Components\Select::make('spintax_snippet_id')
                        ->label(__('campaigns.fields.spintax_snippet'))
                        ->options(fn (): array => SpintaxSnippet::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->dehydrated(false)
                        ->suffixAction(
                            Forms\Components\Actions\Action::make('insert_snippet')
                                ->label(__('campaigns.actions.insert_snippet'))
                                ->icon('heroicon-m-plus')
                                ->action(function (Get $get, Set $set): void {
                                    $snippet = SpintaxSnippet::query()->find((int) $get('spintax_snippet_id'));

                                    if ($snippet !== null) {
                                        $set('content_html', (string) $get('content_html').$snippet->content);
                                    }
                                })
                        ),

                    // Spintax preview/expander (F-2.3.3-022).
                    Forms\Components\Actions::make([
                        Forms\Components\Actions\Action::make('spintax_preview')
                            ->label(__('campaigns.actions.spintax_preview'))
                            ->icon('heroicon-m-sparkles')
                            ->modalHeading(__('campaigns.spintax.preview_title'))
                            ->modalContent(function (Get $get) {
                                $spintax = app(Spintax::class);
                                $text = (string) $get('content_html');

                                return view('filament.customer.campaigns.spintax-preview', [
                                    'permutations' => $spintax->permutationCount($text),
                                    'samples' => $spintax->samples($text, (int) config('emp-campaigns.spintax_preview_samples', 5)),
                                    'hasSpintax' => $spintax->permutationCount($text) > 1,
                                ]);
                            })
                            ->modalSubmitAction(false)
                            ->modalCancelActionLabel(__('campaigns.actions.close')),

                        // WP-46 (§2.3.3 F-2.3.3-009) — smart content by
                        // segment. The customer picks a segment and writes the
                        // two branches; the block syntax is composed here so
                        // nobody has to type `[IF IN SEGMENT '…']` by hand or
                        // guess whether the name needs quoting.
                        Forms\Components\Actions\Action::make('insert_smart_content')
                            ->label(__('campaigns.smart_content.insert'))
                            ->icon('heroicon-m-user-group')
                            ->modalHeading(__('campaigns.smart_content.title'))
                            ->modalDescription(__('campaigns.smart_content.help'))
                            ->form([
                                Forms\Components\Select::make('segment_id')
                                    ->label(__('campaigns.smart_content.segment'))
                                    ->options(fn (Get $get): array => self::smartContentSegments($get('email_list_id')))
                                    ->required()
                                    ->searchable()
                                    ->helperText(__('campaigns.smart_content.segment_help')),
                                Forms\Components\Toggle::make('negate')
                                    ->label(__('campaigns.smart_content.negate'))
                                    ->default(false),
                                Forms\Components\Textarea::make('then_html')
                                    ->label(__('campaigns.smart_content.then'))
                                    ->rows(4)
                                    ->required(),
                                Forms\Components\Textarea::make('else_html')
                                    ->label(__('campaigns.smart_content.else'))
                                    ->rows(4)
                                    ->helperText(__('campaigns.smart_content.else_help')),
                            ])
                            ->action(function (array $data, Get $get, Set $set): void {
                                $segment = ListSegment::query()->find((int) ($data['segment_id'] ?? 0));

                                if ($segment === null) {
                                    return;
                                }

                                $set('content_html', (string) $get('content_html').self::smartContentBlock(
                                    (string) $segment->uid,
                                    (bool) ($data['negate'] ?? false),
                                    (string) ($data['then_html'] ?? ''),
                                    (string) ($data['else_html'] ?? ''),
                                ));
                            }),
                    ]),

                    // WP-46 §2.5.4 F-2.5.4-014 — the AMP for Email body. Its
                    // own editor, its own validator, and NEVER a replacement
                    // for the HTML body: the AMP part is a MIME alternative
                    // most clients walk straight past.
                    Forms\Components\Section::make(__('templates.amp.title'))
                        ->description(__('templates.amp.help'))
                        ->collapsed()
                        ->schema([
                            Forms\Components\Textarea::make('amp_html')
                                ->label(__('templates.amp.body'))
                                ->rows(16)
                                ->extraAttributes(['class' => 'font-mono text-xs'])
                                ->nullable()
                                ->rules([
                                    fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                        $errors = app(AmpValidator::class)->errors(is_string($value) ? $value : null);

                                        if ($errors !== []) {
                                            $fail(__('templates.amp.invalid', ['errors' => implode(' · ', $errors)]));
                                        }
                                    },
                                ])
                                ->hintAction(
                                    Forms\Components\Actions\Action::make('amp_skeleton')
                                        ->label(__('templates.amp.skeleton'))
                                        ->icon('heroicon-m-document-plus')
                                        ->action(function (Get $get, Set $set): void {
                                            if (trim((string) $get('amp_html')) === '') {
                                                $set('amp_html', app(AmpValidator::class)->skeleton());
                                            }
                                        })
                                ),

                            Forms\Components\Placeholder::make('amp_findings')
                                ->label(__('templates.amp.validation'))
                                ->content(fn (Get $get): HtmlString => new HtmlString(
                                    view('filament.customer.campaigns.amp-validation', [
                                        'findings' => app(AmpValidator::class)->findings((string) $get('amp_html')),
                                        'hasBody' => trim((string) $get('amp_html')) !== '',
                                    ])->render()
                                )),
                        ]),

                    // Plain text: auto-generated + manual override (F-2.3.3-006).
                    Forms\Components\Textarea::make('plain_text')
                        ->label(__('campaigns.fields.plain_text'))
                        ->rows(8)
                        ->nullable()
                        ->hintAction(
                            Forms\Components\Actions\Action::make('generate_plain_text')
                                ->label(__('campaigns.actions.generate_plain_text'))
                                ->icon('heroicon-m-arrow-path')
                                ->action(function (Get $get, Set $set): void {
                                    $set('plain_text', app(PlainTextGenerator::class)->generate((string) $get('content_html')));
                                })
                        ),
                ]),
        ];
    }

    /* ================================================================ *
     *  Step 3 — Options (§2.3.4)
     * ================================================================ */

    /** @return array<int, Component> */
    public static function optionsSchema(): array
    {
        $settings = SettingsReader::campaign();

        return [
            Forms\Components\Section::make(__('campaigns.options.tracking'))
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('track_opens')
                        ->label(__('campaigns.options.track_opens'))
                        ->default(fn (): bool => SettingsReader::campaign()->defaultOpenTracking()),
                    Forms\Components\Toggle::make('track_clicks')
                        ->label(__('campaigns.options.track_clicks'))
                        ->default(fn (): bool => SettingsReader::campaign()->defaultClickTracking()),
                    Forms\Components\Textarea::make('url_tracking_exceptions')
                        ->label(__('campaigns.options.url_tracking_exceptions'))
                        ->helperText(__('campaigns.options.url_tracking_exceptions_help'))
                        ->rows(3)
                        // json column ⇄ one-URL-per-line textarea.
                        ->formatStateUsing(fn (mixed $state): string => is_array($state) ? implode("\n", $state) : (string) $state)
                        ->dehydrateStateUsing(fn (mixed $state): array => array_values(array_filter(array_map(
                            'trim',
                            preg_split('/\r?\n/', (string) $state) ?: [],
                        ), fn (string $line): bool => $line !== '')))
                        ->columnSpanFull(),
                    Forms\Components\Toggle::make('utm_enabled')
                        ->label(__('campaigns.options.utm_enabled'))
                        ->live()
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('utm_params.source')->label(__('campaigns.options.utm_source'))
                        ->visible(fn (Get $get): bool => (bool) $get('utm_enabled')),
                    Forms\Components\TextInput::make('utm_params.medium')->label(__('campaigns.options.utm_medium'))
                        ->visible(fn (Get $get): bool => (bool) $get('utm_enabled')),
                    Forms\Components\TextInput::make('utm_params.campaign')->label(__('campaigns.options.utm_campaign'))
                        ->visible(fn (Get $get): bool => (bool) $get('utm_enabled')),
                    Forms\Components\TextInput::make('utm_params.term')->label(__('campaigns.options.utm_term'))
                        ->visible(fn (Get $get): bool => (bool) $get('utm_enabled')),
                    Forms\Components\TextInput::make('utm_params.content')->label(__('campaigns.options.utm_content'))
                        ->visible(fn (Get $get): bool => (bool) $get('utm_enabled')),
                    self::trackingDomainField(),
                ]),

            /*
             * WP-45 §2.3.4 F-2.3.4-024 — goal tracking / conversion pixel.
             *
             * Switching it on makes the click redirect append the signed
             * emp_cid/emp_sid/emp_sig triple to every tracked destination, so
             * the merchant's site-tracking script (or the no-JS pixel below)
             * can report the conversion back and RevenueAttributor can credit
             * it. Off by default: an install that does not use it pays one
             * cached boolean on the hottest endpoint in the product.
             */
            Forms\Components\Section::make(__('ecommerce.goals.section'))
                ->description(__('ecommerce.goals.section_help'))
                // E-commerce is an optional module (goal tracking + coupons).
                ->visible(fn (): bool => class_exists(StoreCouponBatch::class))
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('goal_tracking_enabled')
                        ->label(__('ecommerce.goals.enabled'))
                        ->live()
                        ->columnSpanFull(),
                    Forms\Components\Select::make('goal_type')
                        ->label(__('ecommerce.goals.type'))
                        ->options([
                            'purchase' => __('ecommerce.goals.types.purchase'),
                            'signup' => __('ecommerce.goals.types.signup'),
                            'custom' => __('ecommerce.goals.types.custom'),
                        ])
                        ->default('purchase')
                        ->visible(fn (Get $get): bool => (bool) $get('goal_tracking_enabled')),
                    Forms\Components\TextInput::make('goal_value')
                        ->label(__('ecommerce.goals.value'))
                        ->helperText(__('ecommerce.goals.value_help'))
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->visible(fn (Get $get): bool => (bool) $get('goal_tracking_enabled')),
                    Forms\Components\TextInput::make('goal_window_days')
                        ->label(__('ecommerce.goals.window_days'))
                        ->helperText(__('ecommerce.goals.window_days_help'))
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->visible(fn (Get $get): bool => (bool) $get('goal_tracking_enabled')),
                    // F-2.12-008: the coupon batch {COUPON_CODE} draws from.
                    Forms\Components\Select::make('coupon_batch_id')
                        ->label(__('ecommerce.coupons.label'))
                        ->helperText(__('ecommerce.coupons.merge_hint'))
                        ->options(fn (): array => StoreCouponBatch::query()
                            ->orderByDesc('id')->limit(100)->pluck('name', 'id')->all())
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make(__('campaigns.options.delivery'))
                ->columns(2)
                ->schema([
                    self::deliveryServersField(),
                    Forms\Components\TextInput::make('max_emails')
                        ->label(__('campaigns.options.max_emails'))
                        ->numeric()
                        ->minValue(1)
                        ->nullable(),
                    Forms\Components\TextInput::make('send_rate_per_hour')
                        ->label(__('campaigns.options.send_rate'))
                        ->numeric()
                        ->minValue(1)
                        ->nullable(),
                    Forms\Components\TextInput::make('batch_size')
                        ->label(__('campaigns.options.batch_size'))
                        ->numeric()
                        ->minValue(1)
                        ->nullable(),
                    Forms\Components\TextInput::make('batch_interval_seconds')
                        ->label(__('campaigns.options.batch_interval'))
                        ->numeric()
                        ->minValue(0)
                        ->nullable(),
                    Forms\Components\CheckboxList::make('send_window_days')
                        ->label(__('campaigns.options.send_window_days'))
                        ->options(__('campaigns.recurring.days'))
                        ->columns(4)
                        ->columnSpanFull(),
                    Forms\Components\TimePicker::make('send_window_start')
                        ->label(__('campaigns.options.send_window_start'))
                        ->seconds(false),
                    Forms\Components\TimePicker::make('send_window_end')
                        ->label(__('campaigns.options.send_window_end'))
                        ->seconds(false),
                    self::suppressionField(),
                ]),

            Forms\Components\Section::make(__('campaigns.options.content'))
                ->columns(2)
                ->schema([
                    self::attachmentsField(),
                    Forms\Components\Toggle::make('embed_images')
                        ->label(__('campaigns.options.embed_images')),
                    Forms\Components\Toggle::make('plain_text_only')
                        ->label(__('campaigns.options.plain_text_only')),
                    Forms\Components\Toggle::make('auto_plain_text')
                        ->label(__('campaigns.options.auto_plain_text'))
                        ->default(fn (): bool => SettingsReader::campaign()->defaultPlainTextGeneration()),
                    Forms\Components\Repeater::make('custom_headers')
                        ->label(__('campaigns.options.custom_headers'))
                        ->schema([
                            Forms\Components\TextInput::make('name')
                                ->label(__('campaigns.options.header_name'))
                                ->required()
                                // X-header name format (F-2.3.4-018).
                                ->regex('/^X-[A-Za-z0-9-]+$/')
                                ->validationMessages(['regex' => __('campaigns.errors.header_name_invalid')]),
                            Forms\Components\TextInput::make('value')
                                ->label(__('campaigns.options.header_value'))
                                ->required()
                                ->maxLength(998),
                        ])
                        ->columns(2)
                        ->default([])
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make(__('campaigns.options.extras'))
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('share_reports')
                        ->label(__('campaigns.options.share_reports'))
                        ->helperText(__('campaigns.options.share_reports_help'))
                        ->live(),
                    Forms\Components\Placeholder::make('share_report_url')
                        ->label(__('campaigns.options.share_report_url'))
                        ->content(fn (?Campaign $record): HtmlString => new HtmlString(
                            $record?->shareReportUrl() !== null
                                ? '<code class="text-xs break-all">'.e($record->shareReportUrl()).'</code>'
                                : e(__('campaigns.help.share_secret_after_save'))
                        ))
                        ->visible(fn (Get $get): bool => (bool) $get('share_reports')),
                    Forms\Components\Toggle::make('notify_on_finish')
                        ->label(__('campaigns.options.notify_on_finish')),
                    Forms\Components\Textarea::make('notes')
                        ->label(__('campaigns.options.notes'))
                        ->rows(3)
                        ->columnSpanFull(),
                ]),

            // WP-35 §2.3.7 — engagement filters (F-2.3.7-001..003).
            self::recipientFiltersSection(),

            // WP-35 §2.3.4 P2 send options.
            Forms\Components\Section::make(__('campaigns.options.local_time'))
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('send_at_recipient_local_time')
                        ->label(__('campaigns.options.local_time'))
                        ->helperText(__('campaigns.options.local_time_help')),

                    Forms\Components\TextInput::make('frequency_cap_days')
                        ->label(__('campaigns.options.frequency_cap'))
                        ->helperText(__('campaigns.options.frequency_cap_help'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(365)
                        ->nullable(),

                    // WP-46 §2.3.4 F-2.3.4-011 — Send Time Optimization.
                    Forms\Components\Toggle::make('send_time_optimized')
                        ->label(__('campaigns.options.send_time_optimized'))
                        ->helperText(__('campaigns.options.send_time_optimized_help'))
                        ->live()
                        ->columnSpanFull(),

                    // The read-out: how many recipients the optimizer can
                    // actually speak for and where their predicted hours sit.
                    // Numbers only — no bar is drawn for a bucket with no data.
                    Forms\Components\Placeholder::make('send_time_coverage')
                        ->label(__('campaigns.options.send_time_coverage'))
                        ->visible(fn (Get $get): bool => (bool) $get('send_time_optimized'))
                        ->columnSpanFull()
                        ->content(function (?Campaign $record): HtmlString {
                            if ($record === null) {
                                return new HtmlString(e(__('campaigns.options.send_time_no_data')));
                            }

                            return new HtmlString(view('filament.customer.campaigns.send-time-coverage', [
                                'coverage' => app(SendTimeOptimizer::class)->coverage($record),
                            ])->render());
                        }),

                    Forms\Components\Actions::make([
                        Forms\Components\Actions\Action::make('refresh_send_time_profiles')
                            ->label(__('campaigns.options.send_time_refresh'))
                            ->icon('heroicon-m-arrow-path')
                            ->visible(fn (Get $get): bool => (bool) $get('send_time_optimized'))
                            ->action(function (?Campaign $record): void {
                                if ($record?->customer_id === null) {
                                    return;
                                }

                                // Queued, never inline (docs/ARCHITECTURE.md §5).
                                RefreshSendTimeProfilesJob::dispatch(
                                    (int) $record->customer_id,
                                    $record->email_list_id !== null ? (int) $record->email_list_id : null,
                                );

                                Notification::make()
                                    ->success()
                                    ->title(__('campaigns.options.send_time_queued'))
                                    ->send();
                            }),
                    ])->columnSpanFull(),
                ]),

            // F-2.3.4-022 — per-campaign webhook.
            Forms\Components\Section::make(__('campaigns.options.webhook_url'))
                ->description(__('campaigns.options.webhook_help'))
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('webhook_url')
                        ->label(__('campaigns.options.webhook_url'))
                        ->url()
                        ->maxLength(2048)
                        ->live(onBlur: true)
                        ->columnSpanFull(),

                    Forms\Components\CheckboxList::make('webhook_events')
                        ->label(__('campaigns.options.webhook_events'))
                        ->options(CampaignWebhookNotifier::options())
                        ->columns(3)
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => trim((string) $get('webhook_url')) !== ''),

                    Forms\Components\TextInput::make('webhook_secret')
                        ->label(__('campaigns.options.webhook_secret'))
                        ->maxLength(64)
                        ->visible(fn (Get $get): bool => trim((string) $get('webhook_url')) !== ''),
                ]),
        ];
    }

    /* ================================================================ *
     *  Step 3b — A/B testing (§2.3.6)
     * ================================================================ */

    /**
     * The side-by-side variant composer (F-2.3.6-012): one repeater whose
     * items are the parallel panes, laid out in a grid so two variants sit
     * next to each other, with the traffic split (`weight`) inside the very
     * same panel as the content — which is what "in one panel" asks for.
     *
     * @return array<int, Component>
     */
    public static function abTestSchema(): array
    {
        return [
            Forms\Components\Section::make(__('campaigns.ab.section'))
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('ab_enabled')
                        ->label(__('campaigns.ab.enabled'))
                        ->helperText(__('campaigns.ab.enabled_help'))
                        ->live()
                        ->columnSpanFull(),

                    Forms\Components\CheckboxList::make('ab_variables')
                        ->label(__('campaigns.ab.variables.label'))
                        ->options(AbTestVariable::options())
                        ->default([AbTestVariable::Subject->value])
                        ->columns(3)
                        ->live()
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => (bool) $get('ab_enabled')),

                    // WP-46 §2.3.6 F-2.3.6-004 — multivariate combinations.
                    // One factor per selected variable, its options one per
                    // line; the cross-product replaces the variant list. An
                    // oversized grid is REFUSED with the arithmetic, never cut.
                    Forms\Components\Actions::make([
                        Forms\Components\Actions\Action::make('generate_multivariate')
                            ->label(__('campaigns.mvt.generate'))
                            ->icon('heroicon-m-squares-2x2')
                            ->modalHeading(__('campaigns.mvt.title'))
                            ->modalDescription(__('campaigns.mvt.help'))
                            ->form(fn (Get $get): array => self::multivariateFactorFields($get('ab_variables')))
                            ->action(function (array $data, ?Campaign $record): void {
                                if ($record === null) {
                                    return;
                                }

                                $factors = self::multivariateFactorsFromForm($data);
                                $design = app(MultivariateDesign::class);
                                $problems = $design->problems($factors);

                                if ($problems !== []) {
                                    Notification::make()
                                        ->danger()
                                        ->title(__('campaigns.mvt.title'))
                                        ->body(implode(' · ', $problems))
                                        ->persistent()
                                        ->send();

                                    return;
                                }

                                $variants = $design->generate($record, $factors);

                                Notification::make()
                                    ->success()
                                    ->title(__('campaigns.mvt.generated', [
                                        'count' => count($variants),
                                        'design' => $design->designNotation($factors),
                                    ]))
                                    ->send();
                            }),
                    ])
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => (bool) $get('ab_enabled')
                            && count((array) $get('ab_variables')) > 0),

                    Forms\Components\TextInput::make('ab_test_percent')
                        ->label(__('campaigns.ab.test_percent'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(100)
                        ->default(20)
                        ->live(onBlur: true)
                        ->helperText(fn (Get $get): string => __('campaigns.ab.test_percent_help', [
                            'remainder' => max(0, 100 - (int) ($get('ab_test_percent') ?: 20)),
                        ]))
                        ->visible(fn (Get $get): bool => (bool) $get('ab_enabled')),

                    Forms\Components\Select::make('ab_winner_criteria')
                        ->label(__('campaigns.ab.criteria.label'))
                        ->options(AbWinnerCriteria::options())
                        ->default(AbWinnerCriteria::UniqueOpens->value)
                        ->visible(fn (Get $get): bool => (bool) $get('ab_enabled')),

                    Forms\Components\Select::make('ab_decision_mode')
                        ->label(__('campaigns.ab.decision.label'))
                        ->options(AbDecisionMode::options())
                        ->default(AbDecisionMode::Window->value)
                        ->live()
                        ->visible(fn (Get $get): bool => (bool) $get('ab_enabled')),

                    Forms\Components\TextInput::make('ab_decision_hours')
                        ->label(__('campaigns.ab.decision.hours'))
                        ->helperText(__('campaigns.ab.decision.hours_help'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(720)
                        ->default(4)
                        ->visible(fn (Get $get): bool => (bool) $get('ab_enabled')
                            && $get('ab_decision_mode') !== AbDecisionMode::Manual->value),

                    Forms\Components\Select::make('ab_confidence_level')
                        ->label(__('campaigns.ab.decision.confidence_level'))
                        ->options([90 => '90%', 95 => '95%', 99 => '99%'])
                        ->default(95)
                        ->visible(fn (Get $get): bool => (bool) $get('ab_enabled')
                            && $get('ab_decision_mode') === AbDecisionMode::Confidence->value),

                    Forms\Components\Toggle::make('ab_auto_send_winner')
                        ->label(__('campaigns.ab.decision.auto_send_winner'))
                        ->default(true)
                        ->visible(fn (Get $get): bool => (bool) $get('ab_enabled')),
                ]),

            Forms\Components\Section::make(__('campaigns.ab.variants.label'))
                ->visible(fn (Get $get): bool => (bool) $get('ab_enabled'))
                ->schema([
                    Forms\Components\Repeater::make('variants')
                        ->hiddenLabel()
                        ->relationship('variants')
                        ->orderColumn('position')
                        ->minItems(AbTestManager::MIN_VARIANTS)
                        ->maxItems(AbTestManager::MAX_VARIANTS)
                        ->defaultItems(AbTestManager::MIN_VARIANTS)
                        ->addActionLabel(__('campaigns.ab.variants.add'))
                        ->itemLabel(fn (array $state): string => __('campaigns.ab.variants.item', [
                            'label' => $state['label'] ?? '?',
                        ]))
                        // Two panes side by side — the parallel composer.
                        ->grid(2)
                        ->columnSpanFull()
                        // A placeholder label keeps the unique(campaign_id,
                        // label) index satisfied while the repeater inserts
                        // rows; AbTestManager::normalize() then re-labels the
                        // whole set A..H in position order after the save.
                        ->mutateRelationshipDataBeforeCreateUsing(
                            static fn (array $data): array => $data + [
                                'label' => '#'.Str::random(10),
                                'weight' => 1,
                            ]
                        )
                        ->schema([
                            Forms\Components\TextInput::make('weight')
                                ->label(__('campaigns.ab.variants.weight'))
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(100)
                                ->default(1),

                            Forms\Components\TextInput::make('subject')
                                ->label(__('campaigns.ab.variants.subject'))
                                ->helperText(__('campaigns.ab.variants.inherit_hint'))
                                ->maxLength(500)
                                ->visible(fn (Get $get): bool => self::abVariableOn($get, AbTestVariable::Subject)),

                            Forms\Components\TextInput::make('from_name')
                                ->label(__('campaigns.ab.variants.from_name'))
                                ->helperText(__('campaigns.ab.variants.inherit_hint'))
                                ->maxLength(255)
                                ->visible(fn (Get $get): bool => self::abVariableOn($get, AbTestVariable::FromName)),

                            Forms\Components\TextInput::make('preheader')
                                ->label(__('campaigns.ab.variants.preheader'))
                                ->helperText(__('campaigns.ab.variants.inherit_hint'))
                                ->maxLength(500)
                                ->visible(fn (Get $get): bool => self::abVariableOn($get, AbTestVariable::Preheader)),

                            Forms\Components\Textarea::make('content_html')
                                ->label(__('campaigns.ab.variants.content'))
                                ->helperText(__('campaigns.ab.variants.inherit_hint'))
                                ->rows(8)
                                ->visible(fn (Get $get): bool => self::abVariableOn($get, AbTestVariable::Content)),

                            Forms\Components\TextInput::make('send_delay_minutes')
                                ->label(__('campaigns.ab.variants.send_delay'))
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(10080)
                                ->default(0)
                                ->visible(fn (Get $get): bool => self::abVariableOn($get, AbTestVariable::SendTime)),
                        ]),
                ]),
        ];
    }

    /** Is a test variable enabled? Reads the repeater's PARENT state. */
    private static function abVariableOn(Get $get, AbTestVariable $variable): bool
    {
        $selected = $get('../../ab_variables');

        if (! is_array($selected)) {
            $selected = $get('ab_variables');
        }

        return in_array($variable->value, (array) $selected, true);
    }

    /* ================================================================ *
     *  §2.3.7 recipient filters + §2.3.4 P2 send options
     * ================================================================ */

    /** @return array<int, Component> */
    private static function recipientFiltersSection(): Component
    {
        return Forms\Components\Section::make(__('campaigns.filters.section'))
            ->description(__('campaigns.filters.help'))
            ->columns(2)
            ->schema([
                Forms\Components\Select::make('filter_match')
                    ->label(__('campaigns.filters.match'))
                    ->options([
                        CampaignFilter::MATCH_ALL => __('campaigns.filters.match_all'),
                        CampaignFilter::MATCH_ANY => __('campaigns.filters.match_any'),
                    ])
                    ->default(CampaignFilter::MATCH_ALL),

                Forms\Components\Repeater::make('filters')
                    ->label(__('campaigns.filters.add'))
                    ->relationship('filters')
                    ->orderColumn('position')
                    ->maxItems(10)
                    ->defaultItems(0)
                    ->addActionLabel(__('campaigns.filters.add'))
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        Forms\Components\Select::make('mode')
                            ->label(__('campaigns.filters.mode'))
                            ->options([
                                CampaignFilter::MODE_INCLUDE => __('campaigns.filters.mode_include'),
                                CampaignFilter::MODE_EXCLUDE => __('campaigns.filters.mode_exclude'),
                            ])
                            ->default(CampaignFilter::MODE_INCLUDE)
                            ->required(),

                        Forms\Components\Select::make('type')
                            ->label(__('campaigns.filters.type'))
                            ->options(collect(CampaignFilter::TYPES)
                                ->mapWithKeys(fn (string $type): array => [$type => __('campaigns.filters.types.'.$type)])
                                ->all())
                            ->default(CampaignFilter::TYPE_OPENED)
                            ->required()
                            ->live(),

                        Forms\Components\Select::make('target_campaign_id')
                            ->label(__('campaigns.filters.target_campaign'))
                            ->options(fn (): array => Campaign::query()
                                ->whereNotNull('send_started_at')
                                ->orderByDesc('id')
                                ->limit(200)
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->required()
                            ->live(),

                        Forms\Components\Select::make('campaign_track_url_id')
                            ->label(__('campaigns.filters.link'))
                            ->placeholder(__('campaigns.filters.any_link'))
                            ->options(function (Get $get): array {
                                $targetId = $get('target_campaign_id');

                                if ($targetId === null) {
                                    return [];
                                }

                                $uid = Campaign::query()->whereKey((int) $targetId)->value('uid');

                                return $uid === null ? [] : CampaignTrackUrl::query()
                                    ->where('campaign_uid', $uid)
                                    ->orderBy('id')
                                    ->pluck('url', 'id')
                                    ->all();
                            })
                            ->nullable()
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => in_array($get('type'), [
                                CampaignFilter::TYPE_CLICKED,
                                CampaignFilter::TYPE_NOT_CLICKED,
                            ], true)),
                    ]),
            ]);
    }

    private static function trackingDomainField(): Component
    {
        // WP-15 seam: the tracking_domains table may not exist yet.
        if (! Schema::hasTable('tracking_domains')) {
            return Forms\Components\Placeholder::make('tracking_domain_missing')
                ->label(__('campaigns.options.tracking_domain'))
                ->content(__('campaigns.options.tracking_domain_missing'));
        }

        return Forms\Components\Select::make('tracking_domain_id')
            ->label(__('campaigns.options.tracking_domain'))
            ->options(fn (): array => DB::table('tracking_domains')
                ->when(
                    Schema::hasColumn('tracking_domains', 'customer_id'),
                    fn ($q) => $q->where('customer_id', self::customer()->id),
                )
                ->pluck(
                    Schema::hasColumn('tracking_domains', 'name') ? 'name' : 'id',
                    'id',
                )
                ->all())
            ->nullable();
    }

    private static function deliveryServersField(): Component
    {
        // WP-13/14 seam (DECOUPLING RULE): store plain ids in the
        // delivery_server_ids json column; the table is owned by the
        // parallel delivery-servers agent and may not exist yet.
        if (! Schema::hasTable('delivery_servers')) {
            return Forms\Components\Placeholder::make('delivery_servers_missing')
                ->label(__('campaigns.options.delivery_servers'))
                ->content(__('campaigns.options.delivery_servers_missing'));
        }

        return Forms\Components\Select::make('delivery_server_ids')
            ->label(__('campaigns.options.delivery_servers'))
            ->helperText(__('campaigns.options.delivery_servers_help'))
            ->multiple()
            ->options(fn (): array => DB::table('delivery_servers')
                ->when(
                    Schema::hasColumn('delivery_servers', 'customer_id'),
                    fn ($q) => $q->where(fn ($w) => $w->where('customer_id', self::customer()->id)->orWhereNull('customer_id')),
                )
                ->pluck(
                    Schema::hasColumn('delivery_servers', 'name') ? 'name' : 'id',
                    'id',
                )
                ->all());
    }

    private static function suppressionField(): Component
    {
        // WP-16 seam: suppression lists arrive later.
        if (! Schema::hasTable('suppression_lists')) {
            return Forms\Components\Placeholder::make('suppression_missing')
                ->label(__('campaigns.options.suppression_lists'))
                ->content(__('campaigns.options.suppression_missing'));
        }

        return Forms\Components\Select::make('suppression_list_ids')
            ->label(__('campaigns.options.suppression_lists'))
            ->multiple()
            ->options(fn (): array => DB::table('suppression_lists')
                ->when(
                    Schema::hasColumn('suppression_lists', 'customer_id'),
                    fn ($q) => $q->where('customer_id', self::customer()->id),
                )
                ->pluck(
                    Schema::hasColumn('suppression_lists', 'name') ? 'name' : 'id',
                    'id',
                )
                ->all());
    }

    private static function attachmentsField(): Component
    {
        $settings = SettingsReader::campaign();
        $customer = fn (): Customer => self::customer();

        if (! $settings->attachmentsEnabled()
            || ! app(QuotaService::class)->allows(self::customer(), 'campaigns.attachments')) {
            return Forms\Components\Placeholder::make('attachments_disabled')
                ->label(__('campaigns.options.attachments'))
                ->content(__('campaigns.options.attachments_disabled'))
                ->columnSpanFull();
        }

        return Forms\Components\FileUpload::make('attachments_upload')
            ->label(__('campaigns.options.attachments'))
            ->helperText(__('campaigns.options.attachments_help', [
                'count' => $settings->maxAttachmentCount(),
                'size' => $settings->maxAttachmentSizeMb(),
                'types' => implode(', ', $settings->attachmentAllowedExtensions()),
            ]))
            ->multiple()
            ->disk('public')
            ->directory(fn (?Campaign $record): string => $record !== null
                ? $record->assetDirectory().'/attachments'
                : 'tmp/campaign-attachments')
            ->maxFiles($settings->maxAttachmentCount())
            ->maxSize($settings->maxAttachmentSizeMb() * 1024)
            ->acceptedFileTypes($settings->attachmentAllowedMimeTypes())
            // §3.5 per-extension whitelist + §3.25.3 extension list.
            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                if (! $value instanceof UploadedFile) {
                    return;
                }

                $ext = Str::lower($value->getClientOriginalExtension());
                $allowed = SettingsReader::campaign()->attachmentAllowedExtensions();

                if (! in_array($ext, $allowed, true)
                    || ! app(QuotaService::class)->allows(self::customer(), CustomerGroupMatrix::attachmentTypeKey($ext))) {
                    $fail(__('campaigns.errors.attachment_type', ['type' => $ext]));
                }
            })
            ->formatStateUsing(fn (?Campaign $record): array => $record !== null
                ? $record->attachments()->pluck('path')->all()
                : [])
            ->columnSpanFull();
    }

    /* ================================================================ *
     *  Step 4 — Confirmation (§2.3.5)
     * ================================================================ */

    /** @return array<int, Component> */
    public static function confirmationSchema(): array
    {
        return [
            Forms\Components\Section::make(__('campaigns.confirmation.summary'))
                ->schema([
                    Forms\Components\Placeholder::make('summary')
                        ->hiddenLabel()
                        ->content(fn (Get $get, ?Campaign $record): HtmlString => new HtmlString(
                            view('filament.customer.campaigns.summary', [
                                'campaign' => self::stateCampaign($get, $record),
                                'listName' => $get('email_list_id')
                                    ? EmailList::query()->whereKey((int) $get('email_list_id'))->value('name')
                                    : null,
                                'segmentName' => $get('list_segment_id')
                                    ? ListSegment::query()->whereKey((int) $get('list_segment_id'))->value('name')
                                    : null,
                                'groupName' => $get('campaign_group_id')
                                    ? CampaignGroup::query()->whereKey((int) $get('campaign_group_id'))->value('name')
                                    : null,
                            ])->render()
                        )),
                ]),

            Forms\Components\Section::make(__('campaigns.confirmation.recipients'))
                ->schema([
                    // Live recipient count (F-2.3.5-002).
                    Forms\Components\Placeholder::make('recipient_count')
                        ->hiddenLabel()
                        ->content(function (Get $get, ?Campaign $record): string {
                            if (! $get('email_list_id')) {
                                return __('campaigns.confirmation.no_list');
                            }

                            $count = app(CampaignRecipients::class)->count(self::stateCampaign($get, $record));

                            return __('campaigns.confirmation.recipients_estimate', ['count' => number_format($count)]);
                        }),
                ]),

            Forms\Components\Section::make(__('campaigns.confirmation.preflight'))
                ->schema([
                    // Pre-flight checklist (F-2.3.5-008).
                    Forms\Components\Placeholder::make('preflight')
                        ->hiddenLabel()
                        ->content(fn (Get $get, ?Campaign $record): HtmlString => new HtmlString(
                            view('filament.customer.campaigns.preflight', [
                                'checks' => app(CampaignPreflight::class)->checks(self::stateCampaign($get, $record)),
                            ])->render()
                        )),
                ]),

            // Send test email (F-2.3.5-003) — record required (queued send).
            Forms\Components\Section::make(__('campaigns.actions.send_test'))
                ->schema([
                    Forms\Components\TagsInput::make('test_recipients')
                        ->label(__('campaigns.actions.test_recipients'))
                        ->placeholder('name@example.com')
                        ->nestedRecursiveRules(['email'])
                        ->dehydrated(false),
                    Forms\Components\Actions::make([
                        Forms\Components\Actions\Action::make('send_test')
                            ->label(__('campaigns.actions.send_test'))
                            ->icon('heroicon-m-paper-airplane')
                            ->action(function (Get $get, ?Campaign $record): void {
                                $addresses = array_values(array_filter(
                                    (array) $get('test_recipients'),
                                    fn (mixed $email): bool => is_string($email)
                                        && filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
                                ));

                                if ($record === null || $addresses === []) {
                                    return;
                                }

                                // Test the exact state on screen, not the stale row.
                                $campaign = self::stateCampaign($get, $record);

                                app(CampaignTestSender::class)->send($campaign, $addresses);

                                Notification::make()
                                    ->success()
                                    ->title(__('campaigns.notifications.test_sent'))
                                    ->send();
                            }),
                    ])->visible(fn (?Campaign $record): bool => $record !== null),
                ])
                ->visible(fn (string $operation): bool => $operation === 'edit'),

            // WP-35 §2.3.5 — spam score (F-2.3.5-004) + link validator
            // (F-2.3.5-005). Both are on-demand: neither should fire an HTTP
            // request or a spamd round-trip on every wizard re-render.
            Forms\Components\Section::make(__('campaigns.spam_check.title'))
                ->columns(2)
                ->schema([
                    Forms\Components\Actions::make([
                        Forms\Components\Actions\Action::make('spam_score')
                            ->label(__('campaigns.spam_check.action'))
                            ->icon('heroicon-m-shield-exclamation')
                            ->modalHeading(__('campaigns.spam_check.title'))
                            ->modalSubmitAction(false)
                            ->modalContent(fn (Get $get, ?Campaign $record): HtmlString => new HtmlString(
                                view('filament.customer.campaigns.spam-score', [
                                    'result' => app(CampaignSpamScore::class)
                                        ->check(self::stateCampaign($get, $record)),
                                ])->render()
                            )),

                        Forms\Components\Actions\Action::make('validate_links')
                            ->label(__('campaigns.link_validator.action'))
                            ->icon('heroicon-m-link')
                            ->modalHeading(__('campaigns.link_validator.title'))
                            ->modalSubmitAction(false)
                            ->modalContent(fn (Get $get, ?Campaign $record): HtmlString => new HtmlString(
                                view('filament.customer.campaigns.link-validator', [
                                    'result' => app(CampaignLinkValidator::class)
                                        ->validate(self::stateCampaign($get, $record)),
                                ])->render()
                            )),
                    ])->columnSpanFull(),
                ]),

            Forms\Components\Section::make(__('campaigns.confirmation.action'))
                ->schema([
                    Forms\Components\Radio::make('confirm_action')
                        ->hiddenLabel()
                        ->options([
                            'draft' => __('campaigns.confirmation.save_draft'),
                            'schedule' => __('campaigns.confirmation.schedule'),
                        ])
                        ->default('draft')
                        ->helperText(fn (): ?string => SettingsReader::campaign()->approvalRequired()
                            ? __('campaigns.confirmation.approval_note')
                            : null)
                        ->dehydrated(false),
                ])
                ->visible(fn (?Campaign $record): bool => $record === null || $record->status === CampaignStatus::Draft),
        ];
    }

    /* ================================================================ *
     *  Shared helpers
     * ================================================================ */

    public static function customer(): Customer
    {
        /** @var Customer $customer */
        $customer = Filament::auth()->user();

        return $customer;
    }

    /**
     * Build a transient Campaign from the live wizard state (merged over the
     * saved record when editing) for previews: recipient counts, pre-flight,
     * next-run preview. Never persisted.
     */
    public static function stateCampaign(Get $get, ?Campaign $record): Campaign
    {
        $campaign = $record !== null ? $record->replicate(['uid']) : new Campaign;
        $campaign->uid = $record->uid ?? 'preview';
        $campaign->customer_id = $record->customer_id ?? self::customer()->id;

        $state = [
            'name' => $get('name'),
            'type' => $get('type') ?: CampaignType::Regular->value,
            'email_list_id' => $get('email_list_id') !== null ? (int) $get('email_list_id') : null,
            'list_segment_id' => $get('list_segment_id') !== null ? (int) $get('list_segment_id') : null,
            'subject' => $get('subject'),
            'from_name' => $get('from_name'),
            'from_email' => $get('from_email'),
            'send_at' => $get('send_at'),
            'timezone' => $get('timezone'),
            'send_immediately' => (bool) $get('send_immediately'),
            'ar_trigger' => $get('ar_trigger'),
            'ar_delay_value' => $get('ar_delay_value') !== null ? (int) $get('ar_delay_value') : null,
            'ar_delay_unit' => $get('ar_delay_unit'),
            'rec_interval' => $get('rec_interval'),
            'rec_cron' => $get('rec_cron'),
            'rec_days_of_week' => $get('rec_days_of_week'),
            'rec_day_of_month' => $get('rec_day_of_month') !== null ? (int) $get('rec_day_of_month') : null,
            'rec_starts_at' => $get('rec_starts_at'),
            'rec_ends_at' => $get('rec_ends_at'),
            'rec_max_occurrences' => $get('rec_max_occurrences') !== null ? (int) $get('rec_max_occurrences') : null,

            // WP-35: the P2 targeting options the live recipient count depends
            // on (§2.3.7 combination mode, §2.3.4 frequency cap).
            'filter_match' => $get('filter_match') ?: CampaignFilter::MATCH_ALL,
            'frequency_cap_days' => $get('frequency_cap_days') !== null && $get('frequency_cap_days') !== ''
                ? (int) $get('frequency_cap_days')
                : null,
            'ab_enabled' => (bool) $get('ab_enabled'),
            'ab_test_percent' => $get('ab_test_percent') !== null ? (int) $get('ab_test_percent') : null,
        ];

        // Content fields exist in the form only for html/richtext builders;
        // fall back to the stored content (grapesjs edits via the builder).
        if ($get('content_html') !== null && $get('content_html') !== '') {
            $state['content_html'] = $get('content_html');
        }

        $campaign->forceFill(array_filter(
            $state,
            fn (mixed $value): bool => $value !== null,
        ) + ['send_immediately' => (bool) $get('send_immediately')]);

        // WP-35: relations the count preview reads. Set explicitly (not
        // lazy-loaded) so an unsaved wizard state produces the same number the
        // send wave will, through the same CampaignRecipients choke point.
        $extra = array_values(array_filter(array_map(intval(...), (array) $get('extra_list_ids'))));

        $campaign->setRelation('targetLists', $extra === []
            ? new Collection
            : EmailList::query()->whereIn('id', $extra)->get());

        $campaign->setRelation('filters', collect(
            array_values(array_filter((array) $get('filters'), is_array(...)))
        )->map(fn (array $row): CampaignFilter => new CampaignFilter([
            'type' => (string) ($row['type'] ?? CampaignFilter::TYPE_OPENED),
            'mode' => (string) ($row['mode'] ?? CampaignFilter::MODE_INCLUDE),
            'target_campaign_id' => isset($row['target_campaign_id']) && $row['target_campaign_id'] !== ''
                ? (int) $row['target_campaign_id']
                : null,
            'campaign_track_url_id' => isset($row['campaign_track_url_id']) && $row['campaign_track_url_id'] !== ''
                ? (int) $row['campaign_track_url_id']
                : null,
        ]))->values());

        return $campaign;
    }

    /**
     * Merge tags offered in the inserter panel: enabled template tags
     * (§3.25.3) + the target list's subscriber field tags (F-2.3.3-007).
     *
     * @return list<string>
     */
    public static function mergeTagsFor(?int $listId): array
    {
        // Canonical §4.1 registry (F-4.1-011), still honoring the admin enable-list
        // for the seeded template tags (D-22), plus the target list's field tags.
        $tags = array_values(array_unique(array_merge(
            SettingsReader::campaign()->enabledTemplateTags(),
            array_keys(MergeTags::TAGS),
        )));

        if ($listId !== null) {
            $tags = array_merge($tags, ListField::query()
                ->where('list_id', $listId)
                ->orderBy('sort_order')
                ->pluck('tag')
                ->all());
        }

        return array_values(array_unique($tags));
    }

    /* ================================================================ *
     *  §2.3.6 F-2.3.6-004 — multivariate combinations (WP-46)
     * ================================================================ */

    /**
     * One textarea per selected A/B variable: its options, one per line.
     *
     * @param  mixed  $variables  the ab_variables checkbox state
     * @return array<int, Component>
     */
    public static function multivariateFactorFields(mixed $variables): array
    {
        $fields = [];

        foreach ((array) $variables as $value) {
            $variable = AbTestVariable::tryFrom((string) $value);

            if ($variable === null) {
                continue;
            }

            $fields[] = Forms\Components\Textarea::make('factor_'.$variable->value)
                ->label($variable->label())
                ->placeholder(__('campaigns.mvt.levels'))
                ->helperText(__('campaigns.mvt.levels_help'))
                ->rows(4);
        }

        return $fields;
    }

    /**
     * Turn the modal's `factor_*` textareas into the factor => levels map
     * MultivariateDesign consumes. Blank lines are dropped; nothing else is
     * interpreted, so a level is exactly what the customer typed.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, list<string>>
     */
    public static function multivariateFactorsFromForm(array $data): array
    {
        $factors = [];

        foreach ($data as $key => $raw) {
            if (! str_starts_with((string) $key, 'factor_')) {
                continue;
            }

            $variable = substr((string) $key, strlen('factor_'));

            if (AbTestVariable::tryFrom($variable) === null) {
                continue;
            }

            $levels = array_values(array_filter(array_map(
                static fn (string $line): string => trim($line),
                preg_split('/\r\n|\r|\n/', (string) $raw) ?: [],
            ), static fn (string $line): bool => $line !== ''));

            if ($levels !== []) {
                $factors[$variable] = $levels;
            }
        }

        return $factors;
    }

    /* ================================================================ *
     *  §2.3.3 F-2.3.3-009 — smart content by segment (WP-46)
     * ================================================================ */

    /**
     * Segments the customer may reference. Scoped by the CustomerScope global
     * scope (D-05) and narrowed to the campaign's target list when one is
     * chosen, because a segment belongs to exactly one list and referencing a
     * segment of another list would match nobody at send time.
     *
     * @return array<int, string>
     */
    public static function smartContentSegments(mixed $listId): array
    {
        $query = ListSegment::query()->orderBy('name');

        if ($listId !== null && $listId !== '') {
            $query->where('list_id', (int) $listId);
        }

        return $query->pluck('name', 'id')->all();
    }

    /**
     * Compose one smart-content block. The UID is written rather than the
     * name so renaming a segment never silently breaks a campaign already in
     * flight — the name form stays supported for people who type it by hand.
     */
    public static function smartContentBlock(string $segmentUid, bool $negate, string $then, string $else): string
    {
        $block = "\n[IF ".($negate ? 'NOT ' : '')."IN SEGMENT '".$segmentUid."']\n".trim($then)."\n";

        if (trim($else) !== '') {
            $block .= "[ELSE]\n".trim($else)."\n";
        }

        return $block."[ENDIF]\n";
    }

    /** Common quick-insert emoji for the subject line picker. */
    private const EMOJIS = [
        '🎉', '🔥', '✨', '🚀', '❤️', '👍', '💡', '📣', '🎁', '⏰',
        '☀️', '🌟', '✅', '💰', '📈', '🛒', '🎯', '🙌', '😊', '⚡',
    ];

    private static function mergeTagHint(): string
    {
        return __('campaigns.help.merge_tag_capable');
    }

    /** Validation for merge-tag-capable email fields + forbidden domains (§3.25.3). */
    private static function emailWithTagsRule(array $forbiddenDomains): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($forbiddenDomains): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            $email = trim($value);

            if (preg_match('/^\{[A-Z][A-Z0-9_]*\}$/u', $email) === 1) {
                return; // fully tag-driven
            }

            $resolved = (string) preg_replace('/\{[A-Z][A-Z0-9_]*\}/u', 'example.com', $email);

            if (filter_var($resolved, FILTER_VALIDATE_EMAIL) === false) {
                $fail(__('validation.email', ['attribute' => $attribute]));

                return;
            }

            $domain = Str::lower(Str::afterLast($email, '@'));

            if (in_array($domain, array_map(Str::lower(...), $forbiddenDomains), true)) {
                $fail(__('campaigns.errors.forbidden_domain', ['domain' => $domain]));
            }
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCampaigns::route('/'),
            'create' => Pages\CreateCampaign::route('/create'),
            'edit' => Pages\EditCampaign::route('/{record}/edit'),
            'content' => Pages\BuildCampaignContent::route('/{record}/content'),
            // F-2.5.3-001 (WP-27B): the simple built-in block builder, the
            // same block document/renderer the templates module uses.
            'basic' => Pages\EditCampaignWithBasicBuilder::route('/{record}/basic'),
            'report' => Pages\CampaignReport::route('/{record}/report'),
        ];
    }
}
