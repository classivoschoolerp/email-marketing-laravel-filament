<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\AutomationResource\Support;

use App\Enums\AutomationNodeType;
use App\Enums\CrmTaskType;
use App\Enums\ListFieldType;
use App\Enums\SegmentOperator;
use App\Enums\SubscriberStatus;
use App\Enums\SubUserStatus;
use App\Models\Automation;
use App\Models\Campaign;
use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use App\Models\EmailList;
use App\Models\EmailTemplate;
use App\Models\Form;
use App\Models\ListField;
use App\Models\ListSegment;
use App\Models\SmsGatewayConfig;
use App\Models\StoreConnection;
use App\Models\SubUser;
use App\Models\SuppressionList;
use App\Models\Survey;
use App\Models\WhatsAppGatewayConfig;
use App\Models\WhatsAppTemplate;
use App\Services\Automations\AutomationEvents;
use App\Services\Crm\LeadScorer;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Forms\Get;
use Illuminate\Support\Str;

/**
 * Per-node-type Filament form schemas for the builder's add/edit node
 * modals (spec §2.4.2). UI-layer only — the engine (app/Services/
 * Automations) never touches this class (§H purity).
 */
class NodeForms
{
    /** @return array<string, array<string, string>> grouped type options */
    public static function typeOptions(bool $triggersOnly = false, bool $includeTriggers = false): array
    {
        if ($triggersOnly) {
            return [__('automations.node_group.trigger') => AutomationNodeType::optionsFor(AutomationNodeType::triggers())];
        }

        $groups = [];

        if ($includeTriggers) {
            $groups[__('automations.node_group.trigger')] = AutomationNodeType::optionsFor(AutomationNodeType::triggers());
        }

        $groups[__('automations.node_group.action')] = AutomationNodeType::optionsFor(AutomationNodeType::actions());
        $groups[__('automations.node_group.logic')] = AutomationNodeType::optionsFor(AutomationNodeType::logic());

        return $groups;
    }

    /**
     * Config schema — every component keyed under config.* and shown per
     * selected type.
     *
     * @param  callable(): array<string, string>  $nodeOptions  existing node uid => label (jump targets)
     * @return list<Component>
     */
    public static function configSchema(callable $nodeOptions): array
    {
        $is = fn (string ...$types): \Closure => fn (Get $get): bool => in_array($get('type'), $types, true);

        return [
            Forms\Components\TextInput::make('label')
                ->label(__('automations.fields.label'))
                ->maxLength(120),

            // ---- Triggers -------------------------------------------------
            Forms\Components\Select::make('config.list_id')
                ->label(__('automations.fields.list'))
                ->options(fn (): array => EmailList::query()->orderBy('name')->pluck('name', 'id')->all())
                ->placeholder(__('automations.fields.any_list'))
                ->visible($is(
                    AutomationNodeType::TriggerSubscribed->value,
                    AutomationNodeType::TriggerUnsubscribed->value,
                    AutomationNodeType::TriggerFormSubmitted->value,
                    AutomationNodeType::TriggerWebhook->value,
                )),

            Forms\Components\Select::make('config.segment_id')
                ->label(__('automations.fields.segment'))
                ->options(fn (): array => static::segmentOptions())
                ->visible($is(AutomationNodeType::TriggerSubscribed->value))
                ->placeholder('—'),

            // WP-31 (F-2.4.2-011): the §2.7 forms module raises
            // form_submitted with its form id, so a trigger can name ONE
            // form. Empty = any form, which keeps the WP-21 behaviour
            // (form-sourced subscriptions from the §2.2.11 embed).
            Forms\Components\Select::make('config.form_id')
                ->label(__('forms.resource.label'))
                ->options(fn (): array => Form::query()->orderBy('name')->pluck('name', 'id')->all())
                ->placeholder(__('forms.automations.any_form'))
                ->visible($is(AutomationNodeType::TriggerFormSubmitted->value)),

            // WP-31 (F-2.4.2-012): same for §2.8 surveys.
            Forms\Components\Select::make('config.survey_id')
                ->label(__('surveys.resource.label'))
                ->options(fn (): array => Survey::query()->orderBy('name')->pluck('name', 'id')->all())
                ->placeholder(__('surveys.automations.any_survey'))
                ->visible($is(AutomationNodeType::TriggerSurveyCompleted->value)),

            Forms\Components\Select::make('config.segment_id')
                ->label(__('automations.fields.segment'))
                ->options(fn (): array => static::segmentOptions())
                ->required()
                ->visible($is(
                    AutomationNodeType::TriggerSegmentEntered->value,
                    AutomationNodeType::TriggerSegmentExited->value,
                )),

            Forms\Components\TextInput::make('config.tag')
                ->label(__('automations.fields.tag'))
                ->required()
                ->maxLength(191)
                ->visible($is(
                    AutomationNodeType::TriggerTagAdded->value,
                    AutomationNodeType::TriggerTagRemoved->value,
                    AutomationNodeType::ActionAddTag->value,
                    AutomationNodeType::ActionRemoveTag->value,
                )),

            Forms\Components\Select::make('config.list_field_id')
                ->label(__('automations.fields.field'))
                ->options(fn (): array => static::fieldOptions())
                ->required()
                ->visible($is(
                    AutomationNodeType::TriggerFieldChanged->value,
                    AutomationNodeType::ActionUpdateField->value,
                )),

            Forms\Components\Select::make('config.operator')
                ->label(__('automations.fields.operator'))
                ->options([
                    '' => __('automations.options.field_changed_any'),
                    'is' => __('segments.operator.is'),
                    'is_not' => __('segments.operator.is_not'),
                    'contains' => __('segments.operator.contains'),
                    'is_empty' => __('segments.operator.is_empty'),
                    'not_empty' => __('segments.operator.not_empty'),
                ])
                ->visible($is(AutomationNodeType::TriggerFieldChanged->value)),

            Forms\Components\TextInput::make('config.value')
                ->label(__('automations.fields.value'))
                ->visible(fn (Get $get): bool => $get('type') === AutomationNodeType::TriggerFieldChanged->value
                    && in_array($get('config.operator'), ['is', 'is_not', 'contains'], true)),

            Forms\Components\Select::make('config.campaign_id')
                ->label(__('automations.fields.campaign'))
                ->options(fn (): array => Campaign::query()->orderByDesc('id')->limit(200)->pluck('name', 'id')->all())
                ->placeholder(__('automations.fields.any_campaign'))
                ->visible($is(
                    AutomationNodeType::TriggerCampaignOpened->value,
                    AutomationNodeType::TriggerLinkClicked->value,
                )),

            Forms\Components\TextInput::make('config.url')
                ->label(__('automations.fields.url_contains'))
                ->visible($is(AutomationNodeType::TriggerLinkClicked->value)),

            Forms\Components\Select::make('config.campaign_id')
                ->label(__('automations.fields.campaign'))
                ->options(fn (): array => Campaign::query()->orderByDesc('id')->limit(200)->pluck('name', 'id')->all())
                ->required()
                ->visible($is(AutomationNodeType::TriggerCampaignNotOpened->value)),

            Forms\Components\TextInput::make('config.days')
                ->label(__('automations.fields.days'))
                ->numeric()->minValue(1)->default(3)
                ->required()
                ->visible($is(AutomationNodeType::TriggerCampaignNotOpened->value)),

            Forms\Components\Select::make('config.list_field_id')
                ->label(__('automations.fields.field'))
                ->options(fn (): array => static::fieldOptions(dateOnly: true))
                ->required()
                ->visible($is(AutomationNodeType::TriggerDate->value)),

            Forms\Components\TextInput::make('config.offset_days')
                ->label(__('automations.fields.offset_days'))
                ->numeric()->default(0)
                ->visible($is(AutomationNodeType::TriggerDate->value)),

            Forms\Components\Toggle::make('config.annual')
                ->label(__('automations.fields.annual'))
                ->default(true)
                ->visible($is(AutomationNodeType::TriggerDate->value)),

            Forms\Components\TextInput::make('config.token')
                ->label(__('automations.fields.webhook_token'))
                ->default(fn (): string => Str::random(32))
                ->readOnly()
                ->dehydrated()
                ->visible($is(AutomationNodeType::TriggerWebhook->value)),

            // ---- WP-45 §2.12 ecommerce + site tracking triggers ------------
            // Every key is optional: blank/0 means "any", the convention
            // TriggerMatcher applies to every other trigger config key.
            Forms\Components\Select::make('config.store_connection_id')
                ->label(__('ecommerce.automations.store'))
                ->options(fn (): array => StoreConnection::query()
                    ->orderBy('id')->pluck('name', 'id')->all())
                ->placeholder(__('ecommerce.automations.any_store'))
                ->visible($is(
                    AutomationNodeType::TriggerCartAbandoned->value,
                    AutomationNodeType::TriggerOrderPlaced->value,
                    AutomationNodeType::TriggerOrderShipped->value,
                    AutomationNodeType::TriggerProductViewed->value,
                    AutomationNodeType::TriggerBackInStock->value,
                    AutomationNodeType::TriggerPageVisited->value,
                )),

            Forms\Components\TextInput::make('config.min_total')
                ->label(__('ecommerce.automations.min_total'))
                ->helperText(__('ecommerce.automations.min_total_help'))
                ->numeric()->minValue(0)->default(0)
                ->visible($is(
                    AutomationNodeType::TriggerCartAbandoned->value,
                    AutomationNodeType::TriggerOrderPlaced->value,
                    AutomationNodeType::TriggerOrderShipped->value,
                )),

            Forms\Components\TextInput::make('config.category')
                ->label(__('ecommerce.automations.category'))
                ->helperText(__('ecommerce.automations.category_help'))
                ->visible($is(
                    AutomationNodeType::TriggerOrderPlaced->value,
                    AutomationNodeType::TriggerOrderShipped->value,
                    AutomationNodeType::TriggerProductViewed->value,
                    AutomationNodeType::TriggerBackInStock->value,
                )),

            Forms\Components\TextInput::make('config.product_external_id')
                ->label(__('ecommerce.automations.product'))
                ->helperText(__('ecommerce.automations.product_help'))
                ->visible($is(
                    AutomationNodeType::TriggerCartAbandoned->value,
                    AutomationNodeType::TriggerOrderPlaced->value,
                    AutomationNodeType::TriggerOrderShipped->value,
                    AutomationNodeType::TriggerProductViewed->value,
                    AutomationNodeType::TriggerBackInStock->value,
                )),

            Forms\Components\TextInput::make('config.path')
                ->label(__('ecommerce.automations.path'))
                ->helperText(__('ecommerce.automations.path_help'))
                ->visible($is(AutomationNodeType::TriggerPageVisited->value)),

            // ---- Send email ----------------------------------------------
            Forms\Components\Radio::make('config.mode')
                ->label(__('automations.fields.mode'))
                ->options([
                    'inline' => __('automations.options.mode_inline'),
                    'template' => __('automations.options.mode_template'),
                ])
                ->default('inline')->inline()->live()
                ->visible($is(AutomationNodeType::ActionSendEmail->value)),

            Forms\Components\Select::make('config.template_id')
                ->label(__('automations.fields.template'))
                ->options(fn (): array => EmailTemplate::query()->browsable()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->required()
                ->visible(fn (Get $get): bool => $get('type') === AutomationNodeType::ActionSendEmail->value
                    && $get('config.mode') === 'template'),

            Forms\Components\TextInput::make('config.subject')
                ->label(__('automations.fields.subject'))
                ->required()->maxLength(500)
                ->visible($is(AutomationNodeType::ActionSendEmail->value, AutomationNodeType::ActionNotifyTeam->value)),

            Forms\Components\Textarea::make('config.content_html')
                ->label(__('automations.fields.content_html'))
                ->rows(8)
                ->required()
                ->visible(fn (Get $get): bool => $get('type') === AutomationNodeType::ActionSendEmail->value
                    && ($get('config.mode') ?? 'inline') === 'inline'),

            Forms\Components\TextInput::make('config.from_name')
                ->label(__('automations.fields.from_name'))
                ->visible($is(AutomationNodeType::ActionSendEmail->value)),

            Forms\Components\TextInput::make('config.from_email')
                ->label(__('automations.fields.from_email'))
                ->email()
                ->visible($is(AutomationNodeType::ActionSendEmail->value)),

            Forms\Components\Toggle::make('config.track')
                ->label(__('automations.fields.track'))
                ->default(true)
                ->visible($is(AutomationNodeType::ActionSendEmail->value)),

            // ---- Notify team ---------------------------------------------
            Forms\Components\TextInput::make('config.recipients')
                ->label(__('automations.fields.recipients'))
                ->required()
                ->visible($is(AutomationNodeType::ActionNotifyTeam->value)),

            Forms\Components\Textarea::make('config.body')
                ->label(__('automations.fields.body'))
                ->rows(4)
                ->visible($is(AutomationNodeType::ActionNotifyTeam->value)),

            // ---- Update field value --------------------------------------
            Forms\Components\TextInput::make('config.value')
                ->label(__('automations.fields.value'))
                ->visible($is(AutomationNodeType::ActionUpdateField->value)),

            // ---- List ops ------------------------------------------------
            Forms\Components\Select::make('config.op')
                ->label(__('automations.fields.list_op'))
                ->options([
                    'add' => __('automations.options.list_add'),
                    'remove' => __('automations.options.list_remove'),
                    'move' => __('automations.options.list_move'),
                ])
                ->default('add')->required()
                ->visible($is(AutomationNodeType::ActionList->value)),

            Forms\Components\Select::make('config.list_id')
                ->label(__('automations.fields.list'))
                ->options(fn (): array => EmailList::query()->orderBy('name')->pluck('name', 'id')->all())
                ->required()
                ->visible($is(AutomationNodeType::ActionList->value)),

            // ---- Suppress ------------------------------------------------
            Forms\Components\Radio::make('config.mode')
                ->label(__('automations.fields.suppress_mode'))
                ->options([
                    'blacklist' => __('automations.options.suppress_blacklist'),
                    'suppression' => __('automations.options.suppress_suppression'),
                ])
                ->default('blacklist')->inline()->live()
                ->visible($is(AutomationNodeType::ActionSuppress->value)),

            Forms\Components\Select::make('config.suppression_list_id')
                ->label(__('automations.fields.suppression_list'))
                ->options(fn (): array => SuppressionList::query()->orderBy('name')->pluck('name', 'id')->all())
                ->required()
                ->visible(fn (Get $get): bool => $get('type') === AutomationNodeType::ActionSuppress->value
                    && $get('config.mode') === 'suppression'),

            // ---- Cross automation ----------------------------------------
            Forms\Components\Select::make('config.op')
                ->label(__('automations.fields.cross_op'))
                ->options([
                    'subscribe' => __('automations.options.cross_subscribe'),
                    'unsubscribe' => __('automations.options.cross_unsubscribe'),
                ])
                ->default('subscribe')->required()
                ->visible($is(AutomationNodeType::ActionCrossAutomation->value)),

            Forms\Components\Select::make('config.automation_id')
                ->label(__('automations.fields.automation'))
                ->options(fn ($livewire): array => Automation::query()
                    ->when(isset($livewire->record), fn ($q) => $q->whereKeyNot($livewire->record->getKey()))
                    ->orderBy('name')->pluck('name', 'id')->all())
                ->required()
                ->visible($is(AutomationNodeType::ActionCrossAutomation->value)),

            // ---- Webhook -------------------------------------------------
            Forms\Components\TextInput::make('config.url')
                ->label(__('automations.fields.webhook_url'))
                ->url()->required()
                ->visible($is(AutomationNodeType::ActionWebhook->value)),

            Forms\Components\Textarea::make('config.payload')
                ->label(__('automations.fields.webhook_payload'))
                ->rows(3)
                ->visible($is(AutomationNodeType::ActionWebhook->value)),

            // ---- SMS (WP-32 §2.10 F-2.10-012 multichannel node) -----------
            Forms\Components\Textarea::make('config.body')
                ->label(__('automations.fields.sms_body'))
                ->helperText(__('automations.fields.sms_body_help'))
                ->rows(4)->required()
                ->visible($is(AutomationNodeType::ActionSms->value)),

            Forms\Components\TextInput::make('config.sender_id')
                ->label(__('automations.fields.sms_sender_id'))
                ->maxLength(32)
                ->visible($is(AutomationNodeType::ActionSms->value)),

            Forms\Components\Select::make('config.sms_gateway_id')
                ->label(__('automations.fields.sms_gateway'))
                ->options(fn (): array => SmsGatewayConfig::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->helperText(__('automations.fields.sms_gateway_help'))
                ->visible($is(AutomationNodeType::ActionSms->value)),

            // Shared by the SMS and WhatsApp nodes — both read the contact's
            // number from a list phone field, so this is ONE component with a
            // wider visibility rather than two components fighting over the
            // same state path.
            Forms\Components\Select::make('config.phone_field_id')
                ->label(__('automations.fields.sms_phone_field'))
                ->options(fn (): array => self::phoneFieldOptions())
                ->helperText(__('automations.fields.sms_phone_field_help'))
                ->visible($is(
                    AutomationNodeType::ActionSms->value,
                    AutomationNodeType::ActionWhatsApp->value,
                )),

            Forms\Components\Toggle::make('config.shorten_links')
                ->label(__('automations.fields.sms_shorten_links'))
                ->default(true)
                ->visible($is(AutomationNodeType::ActionSms->value)),

            // ---- WhatsApp (WP-46 §2.10, F-2.4.2-022) ---------------------
            // Only APPROVED templates are offered: the provider will not
            // deliver anything else, so listing a draft would be a trap.
            Forms\Components\Select::make('config.whatsapp_template_id')
                ->label(__('automations.fields.whatsapp_template'))
                ->options(fn (): array => WhatsAppTemplate::query()
                    ->where('status', WhatsAppTemplate::STATUS_APPROVED)
                    ->orderBy('name')
                    ->get()
                    ->mapWithKeys(fn (WhatsAppTemplate $t): array => [$t->id => $t->name.' ('.$t->language.')'])
                    ->all())
                ->helperText(__('automations.fields.whatsapp_template_help'))
                ->required()
                ->visible($is(AutomationNodeType::ActionWhatsApp->value)),

            Forms\Components\Repeater::make('config.variables')
                ->label(__('automations.fields.whatsapp_variables'))
                ->helperText(__('automations.fields.whatsapp_variables_help'))
                ->schema([
                    Forms\Components\TextInput::make('position')
                        ->label(__('automations.fields.whatsapp_variable_position'))
                        ->numeric()->minValue(1)->required(),
                    Forms\Components\TextInput::make('tag')
                        ->label(__('automations.fields.whatsapp_variable_tag'))
                        ->required(),
                ])
                ->columns(2)
                ->default([])
                ->visible($is(AutomationNodeType::ActionWhatsApp->value)),

            Forms\Components\TextInput::make('config.header_media_url')
                ->label(__('automations.fields.whatsapp_header_media'))
                ->url()
                ->visible($is(AutomationNodeType::ActionWhatsApp->value)),

            Forms\Components\Select::make('config.whatsapp_gateway_id')
                ->label(__('automations.fields.whatsapp_gateway'))
                ->options(fn (): array => WhatsAppGatewayConfig::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->helperText(__('automations.fields.whatsapp_gateway_help'))
                ->visible($is(AutomationNodeType::ActionWhatsApp->value)),

            // ---- Web push (WP-46 §2.10, F-2.4.2-023) ---------------------
            Forms\Components\TextInput::make('config.title')
                ->label(__('automations.fields.web_push_title'))
                ->maxLength(120)->required()
                ->visible($is(AutomationNodeType::ActionWebPush->value)),

            Forms\Components\Textarea::make('config.body')
                ->label(__('automations.fields.web_push_body'))
                ->rows(3)->maxLength(300)
                ->visible($is(AutomationNodeType::ActionWebPush->value)),

            Forms\Components\TextInput::make('config.url')
                ->label(__('automations.fields.web_push_url'))
                ->url()
                ->helperText(__('automations.fields.web_push_url_help'))
                ->visible($is(AutomationNodeType::ActionWebPush->value)),

            Forms\Components\TextInput::make('config.icon_url')
                ->label(__('automations.fields.web_push_icon'))
                ->url()
                ->visible($is(AutomationNodeType::ActionWebPush->value)),

            // ---- CRM triggers (WP-44 §2.13, F-2.4.2-017) -----------------
            Forms\Components\Select::make('config.pipeline_id')
                ->label(__('crm.pipeline.label'))
                ->options(fn (): array => CrmPipeline::query()->orderBy('position')->pluck('name', 'id')->all())
                ->placeholder(__('crm.automations.any_pipeline'))
                ->live()
                ->visible($is(AutomationNodeType::TriggerDealStageChanged->value)),

            Forms\Components\Select::make('config.stage_id')
                ->label(__('crm.stage.label'))
                ->options(fn (Get $get): array => static::stageOptions((int) $get('config.pipeline_id')))
                ->placeholder(__('crm.automations.any_stage'))
                ->visible($is(AutomationNodeType::TriggerDealStageChanged->value)),

            Forms\Components\TextInput::make('config.threshold')
                ->label(__('crm.automations.threshold'))
                ->helperText(__('crm.automations.threshold_help'))
                ->numeric()->minValue(1)->maxValue(100)->default(50)
                ->required()
                ->visible($is(AutomationNodeType::TriggerLeadScore->value)),

            Forms\Components\Select::make('config.direction')
                ->label(__('crm.automations.direction'))
                ->options([
                    'above' => __('crm.automations.direction_above'),
                    'below' => __('crm.automations.direction_below'),
                ])
                ->default('above')
                ->visible($is(AutomationNodeType::TriggerLeadScore->value)),

            // ---- CRM actions (WP-44, F-2.4.2-031/032/033) ----------------
            Forms\Components\Select::make('config.op')
                ->label(__('crm.automations.op'))
                ->options([
                    'create_task' => __('crm.automations.op_create_task'),
                    'create_deal' => __('crm.automations.op_create_deal'),
                    'update_deal_stage' => __('crm.automations.op_update_stage'),
                ])
                ->default('create_task')->required()->live()
                ->visible($is(AutomationNodeType::ActionCrm->value)),

            Forms\Components\TextInput::make('config.title')
                ->label(__('crm.automations.title'))
                ->maxLength(191)
                ->required(fn (Get $get): bool => $get('type') === AutomationNodeType::ActionCrm->value
                    && in_array($get('config.op'), ['create_task', 'create_deal'], true))
                ->visible(fn (Get $get): bool => $get('type') === AutomationNodeType::ActionCrm->value
                    && in_array($get('config.op') ?? 'create_task', ['create_task', 'create_deal'], true)),

            Forms\Components\Select::make('config.task_type')
                ->label(__('crm.fields.task_type'))
                ->options(fn (): array => CrmTaskType::options())
                ->default(CrmTaskType::Task->value)
                ->visible(fn (Get $get): bool => $get('type') === AutomationNodeType::ActionCrm->value
                    && ($get('config.op') ?? 'create_task') === 'create_task'),

            Forms\Components\TextInput::make('config.due_days')
                ->label(__('crm.automations.due_days'))
                ->numeric()->minValue(0)->default(1)
                ->visible(fn (Get $get): bool => $get('type') === AutomationNodeType::ActionCrm->value
                    && ($get('config.op') ?? 'create_task') === 'create_task'),

            Forms\Components\TextInput::make('config.value_minor')
                ->label(__('crm.automations.value_minor'))
                ->helperText(__('crm.automations.value_minor_help'))
                ->numeric()->minValue(0)->default(0)
                ->visible(fn (Get $get): bool => $get('type') === AutomationNodeType::ActionCrm->value
                    && $get('config.op') === 'create_deal'),

            Forms\Components\Select::make('config.stage_id')
                ->label(__('crm.stage.label'))
                ->options(fn (): array => static::stageOptions())
                ->required(fn (Get $get): bool => $get('type') === AutomationNodeType::ActionCrm->value
                    && $get('config.op') === 'update_deal_stage')
                ->visible(fn (Get $get): bool => $get('type') === AutomationNodeType::ActionCrm->value
                    && in_array($get('config.op'), ['create_deal', 'update_deal_stage'], true)),

            Forms\Components\Select::make('config.op')
                ->label(__('crm.automations.score_op'))
                ->options([
                    'add' => __('crm.automations.score_add'),
                    'set' => __('crm.automations.score_set'),
                ])
                ->default('add')->required()
                ->visible($is(AutomationNodeType::ActionLeadScore->value)),

            Forms\Components\TextInput::make('config.points')
                ->label(__('crm.automations.points'))
                ->helperText(fn (): string => __('crm.automations.points_help', ['days' => LeadScorer::MANUAL_HALF_LIFE_DAYS]))
                ->numeric()->default(10)->required()
                ->visible($is(AutomationNodeType::ActionLeadScore->value)),

            Forms\Components\Select::make('config.mode')
                ->label(__('crm.automations.owner_mode'))
                ->options([
                    'round_robin' => __('crm.automations.owner_round_robin'),
                    'specific' => __('crm.automations.owner_specific'),
                ])
                ->default('round_robin')->required()->live()
                ->visible($is(AutomationNodeType::ActionAssignOwner->value)),

            Forms\Components\Select::make('config.sub_user_id')
                ->label(__('crm.fields.owner'))
                ->options(fn (): array => SubUser::query()
                    ->where('customer_id', Filament::auth()->user()?->getAuthIdentifier() ?? 0)
                    ->where('status', SubUserStatus::Active->value)
                    ->orderBy('id')
                    ->get()
                    ->mapWithKeys(fn (SubUser $user): array => [(int) $user->id => $user->fullName()])
                    ->all())
                ->required(fn (Get $get): bool => $get('type') === AutomationNodeType::ActionAssignOwner->value
                    && $get('config.mode') === 'specific')
                ->visible(fn (Get $get): bool => $get('type') === AutomationNodeType::ActionAssignOwner->value
                    && $get('config.mode') === 'specific'),

            // ---- Delay ---------------------------------------------------
            Forms\Components\Select::make('config.mode')
                ->label(__('automations.fields.delay_mode'))
                ->options([
                    'duration' => __('automations.options.delay_duration'),
                    'until_date' => __('automations.options.delay_until_date'),
                    'until_time' => __('automations.options.delay_until_time'),
                    'until_event' => __('automations.options.delay_until_event'),
                ])
                ->default('duration')->live()
                ->visible($is(AutomationNodeType::LogicDelay->value)),

            Forms\Components\TextInput::make('config.value')
                ->label(__('automations.fields.delay_value'))
                ->numeric()->minValue(1)->default(1)
                ->visible(fn (Get $get): bool => $get('type') === AutomationNodeType::LogicDelay->value
                    && ($get('config.mode') ?? 'duration') === 'duration'),

            Forms\Components\Select::make('config.unit')
                ->label(__('automations.fields.delay_unit'))
                ->options(static::unitOptions())
                ->default('hours')
                ->visible(fn (Get $get): bool => $get('type') === AutomationNodeType::LogicDelay->value
                    && ($get('config.mode') ?? 'duration') === 'duration'),

            Forms\Components\DateTimePicker::make('config.date')
                ->label(__('automations.fields.date'))
                ->visible(fn (Get $get): bool => $get('type') === AutomationNodeType::LogicDelay->value
                    && $get('config.mode') === 'until_date'),

            Forms\Components\Select::make('config.weekday')
                ->label(__('automations.fields.weekday'))
                ->options([
                    1 => __('Monday'), 2 => __('Tuesday'), 3 => __('Wednesday'),
                    4 => __('Thursday'), 5 => __('Friday'), 6 => __('Saturday'), 0 => __('Sunday'),
                ])
                ->placeholder('—')
                ->visible(fn (Get $get): bool => $get('type') === AutomationNodeType::LogicDelay->value
                    && $get('config.mode') === 'until_time'),

            Forms\Components\TextInput::make('config.time')
                ->label(__('automations.fields.time'))
                ->default('09:00')
                ->visible(fn (Get $get): bool => $get('type') === AutomationNodeType::LogicDelay->value
                    && $get('config.mode') === 'until_time'),

            Forms\Components\Select::make('config.event')
                ->label(__('automations.fields.event'))
                ->options(AutomationEvents::options())
                ->visible(fn (Get $get): bool => $get('type') === AutomationNodeType::LogicDelay->value
                    && $get('config.mode') === 'until_event'),

            // ---- Wait for event ------------------------------------------
            Forms\Components\Select::make('config.event')
                ->label(__('automations.fields.event'))
                ->options(AutomationEvents::options())
                ->required()
                ->visible($is(AutomationNodeType::LogicWaitEvent->value)),

            Forms\Components\TextInput::make('config.timeout_value')
                ->label(__('automations.fields.timeout_value'))
                ->numeric()->minValue(1)->default(3)
                ->required(fn (Get $get): bool => $get('type') === AutomationNodeType::LogicWaitEvent->value)
                ->visible($is(AutomationNodeType::LogicWaitEvent->value, AutomationNodeType::LogicDelay->value)),

            Forms\Components\Select::make('config.timeout_unit')
                ->label(__('automations.fields.timeout_unit'))
                ->options(static::unitOptions())
                ->default('days')
                ->visible($is(AutomationNodeType::LogicWaitEvent->value)),

            // ---- Conditions (if/else + goal) -----------------------------
            Forms\Components\Select::make('config.match')
                ->label(__('automations.fields.match'))
                ->options([
                    'all' => __('automations.options.match_all'),
                    'any' => __('automations.options.match_any'),
                ])
                ->default('all')
                ->visible($is(AutomationNodeType::LogicIfElse->value, AutomationNodeType::LogicGoal->value)),

            Forms\Components\Repeater::make('config.conditions')
                ->label(__('automations.fields.conditions'))
                ->schema(static::conditionSchema())
                ->defaultItems(1)
                ->visible($is(AutomationNodeType::LogicIfElse->value, AutomationNodeType::LogicGoal->value)),

            Forms\Components\TextInput::make('config.conversion_value')
                ->label(__('automations.fields.conversion_value'))
                ->numeric()->minValue(0)->default(0)
                ->visible($is(AutomationNodeType::LogicGoal->value)),

            Forms\Components\Toggle::make('config.wait')
                ->label(__('automations.fields.goal_wait'))
                ->default(false)
                ->visible($is(AutomationNodeType::LogicGoal->value)),

            // ---- Multi-branch split --------------------------------------
            Forms\Components\Select::make('config.source')
                ->label(__('automations.fields.source'))
                ->options([
                    'email' => __('automations.options.source_email'),
                    'status' => __('automations.options.source_status'),
                ])
                ->default('email')
                ->visible($is(AutomationNodeType::LogicSplit->value)),

            Forms\Components\Select::make('config.list_field_id')
                ->label(__('automations.fields.field'))
                ->options(fn (): array => static::fieldOptions())
                ->placeholder('—')
                ->visible($is(AutomationNodeType::LogicSplit->value)),

            Forms\Components\Repeater::make('config.cases')
                ->label(__('automations.fields.cases'))
                ->schema([
                    Forms\Components\TextInput::make('value')
                        ->label(__('automations.fields.case_value'))
                        ->required(),
                ])
                ->defaultItems(2)->minItems(1)
                ->visible($is(AutomationNodeType::LogicSplit->value)),

            // ---- Percentage split ----------------------------------------
            Forms\Components\Repeater::make('config.variants')
                ->label(__('automations.fields.variants'))
                ->schema([
                    Forms\Components\TextInput::make('percent')
                        ->label(__('automations.fields.percent'))
                        ->numeric()->minValue(1)->maxValue(99)->required(),
                ])
                ->defaultItems(2)->minItems(2)->maxItems(5)
                ->visible($is(AutomationNodeType::LogicPercentage->value)),

            // ---- Jump ----------------------------------------------------
            Forms\Components\Select::make('config.target')
                ->label(__('automations.fields.jump_target'))
                ->options([
                    'node' => __('automations.options.jump_node'),
                    'automation' => __('automations.options.jump_automation'),
                ])
                ->default('node')->live()
                ->visible($is(AutomationNodeType::LogicJump->value)),

            Forms\Components\Select::make('config.target_node_uid')
                ->label(__('automations.fields.target_node'))
                ->options(fn (): array => $nodeOptions())
                ->visible(fn (Get $get): bool => $get('type') === AutomationNodeType::LogicJump->value
                    && ($get('config.target') ?? 'node') === 'node'),

            Forms\Components\Select::make('config.target_automation_id')
                ->label(__('automations.fields.target_automation'))
                ->options(fn ($livewire): array => Automation::query()
                    ->when(isset($livewire->record), fn ($q) => $q->whereKeyNot($livewire->record->getKey()))
                    ->orderBy('name')->pluck('name', 'id')->all())
                ->visible(fn (Get $get): bool => $get('type') === AutomationNodeType::LogicJump->value
                    && $get('config.target') === 'automation'),

            // ---- Frequency guard -----------------------------------------
            Forms\Components\Toggle::make('config.quiet_enabled')
                ->label(__('automations.fields.quiet_enabled'))
                ->default(true)->live()
                ->visible($is(AutomationNodeType::LogicFrequencyGuard->value)),

            Forms\Components\TextInput::make('config.quiet_start')
                ->label(__('automations.fields.quiet_start'))
                ->default('22:00')
                ->visible(fn (Get $get): bool => $get('type') === AutomationNodeType::LogicFrequencyGuard->value
                    && (bool) $get('config.quiet_enabled')),

            Forms\Components\TextInput::make('config.quiet_end')
                ->label(__('automations.fields.quiet_end'))
                ->default('08:00')
                ->visible(fn (Get $get): bool => $get('type') === AutomationNodeType::LogicFrequencyGuard->value
                    && (bool) $get('config.quiet_enabled')),

            Forms\Components\Toggle::make('config.cap_enabled')
                ->label(__('automations.fields.cap_enabled'))
                ->default(false)->live()
                ->visible($is(AutomationNodeType::LogicFrequencyGuard->value)),

            Forms\Components\TextInput::make('config.cap_max')
                ->label(__('automations.fields.cap_max'))
                ->numeric()->minValue(1)->default(3)
                ->visible(fn (Get $get): bool => $get('type') === AutomationNodeType::LogicFrequencyGuard->value
                    && (bool) $get('config.cap_enabled')),

            Forms\Components\TextInput::make('config.cap_hours')
                ->label(__('automations.fields.cap_hours'))
                ->numeric()->minValue(1)->default(24)
                ->visible(fn (Get $get): bool => $get('type') === AutomationNodeType::LogicFrequencyGuard->value
                    && (bool) $get('config.cap_enabled')),
        ];
    }

    /** @return list<Component> */
    private static function conditionSchema(): array
    {
        return [
            Forms\Components\Select::make('type')
                ->label(__('segments.condition_type.field'))
                ->options([
                    'field' => __('segments.condition_type.field'),
                    'date' => __('segments.condition_type.date'),
                    'status' => __('segments.condition_type.status'),
                    'campaign' => __('segments.condition_type.campaign'),
                ])
                ->default('field')->live()->required(),

            Forms\Components\Select::make('list_field_id')
                ->label(__('automations.fields.field'))
                ->options(fn (): array => static::fieldOptions())
                ->placeholder(__('segments.fields.email'))
                ->visible(fn (Get $get): bool => $get('type') === 'field'),

            Forms\Components\Select::make('field_key')
                ->label(__('automations.fields.field'))
                ->options([
                    'subscribed' => __('segments.fields.subscribed'),
                    'last_activity' => __('segments.fields.last_activity'),
                ])
                ->default('subscribed')
                ->visible(fn (Get $get): bool => $get('type') === 'date'),

            Forms\Components\Select::make('operator')
                ->label(__('automations.fields.operator'))
                ->options(fn (Get $get): array => match ($get('type')) {
                    'date' => SegmentOperator::optionsFor(SegmentOperator::forDate()),
                    'status' => SegmentOperator::optionsFor(SegmentOperator::forStatus()),
                    'campaign' => SegmentOperator::optionsFor(SegmentOperator::forCampaign()),
                    default => SegmentOperator::optionsFor(SegmentOperator::forField()),
                })
                ->live()->required(),

            Forms\Components\Select::make('value')
                ->label(__('automations.fields.value'))
                ->options(SubscriberStatus::options())
                ->visible(fn (Get $get): bool => $get('type') === 'status'),

            Forms\Components\TextInput::make('value')
                ->label(__('automations.fields.value'))
                ->visible(fn (Get $get): bool => in_array($get('type'), ['field', 'date'], true)
                    && ($op = SegmentOperator::tryFrom((string) $get('operator'))) !== null
                    && $op->needsValue()),

            Forms\Components\TextInput::make('value2')
                ->label(__('automations.fields.value2'))
                ->visible(fn (Get $get): bool => ($op = SegmentOperator::tryFrom((string) $get('operator'))) !== null
                    && $op->needsSecondValue()),

            Forms\Components\Select::make('settings.campaign_id')
                ->label(__('automations.fields.campaign'))
                ->options(fn (): array => Campaign::query()->orderByDesc('id')->limit(200)->pluck('name', 'id')->all())
                ->placeholder(__('automations.fields.any_campaign'))
                ->visible(fn (Get $get): bool => $get('type') === 'campaign'),

            Forms\Components\TextInput::make('settings.url')
                ->label(__('automations.fields.url_contains'))
                ->visible(fn (Get $get): bool => $get('type') === 'campaign'
                    && $get('operator') === 'clicked_link'),

            Forms\Components\TextInput::make('settings.within_days')
                ->label(__('automations.fields.days'))
                ->numeric()->minValue(1)
                ->visible(fn (Get $get): bool => $get('type') === 'campaign'),
        ];
    }

    /** @return array<string, string> */
    public static function unitOptions(): array
    {
        return [
            'minutes' => __('automations.options.unit_minutes'),
            'hours' => __('automations.options.unit_hours'),
            'days' => __('automations.options.unit_days'),
            'weeks' => __('automations.options.unit_weeks'),
        ];
    }

    /** All customer fields, list-prefixed. @return array<int, string> */
    public static function fieldOptions(bool $dateOnly = false): array
    {
        $lists = EmailList::query()->orderBy('name')->pluck('name', 'id');

        return ListField::query()
            ->when($dateOnly, fn ($q) => $q->whereIn('type', ['date', 'datetime']))
            ->orderBy('list_id')->orderBy('sort_order')
            ->get()
            ->mapWithKeys(fn (ListField $field): array => [
                (int) $field->id => ($lists[$field->list_id] ?? '?').' — '.$field->label,
            ])
            ->all();
    }

    /**
     * Phone-type list fields, labelled with their list (WP-32 §2.10).
     *
     * @return array<int, string>
     */
    public static function phoneFieldOptions(): array
    {
        $lists = EmailList::query()->orderBy('name')->pluck('name', 'id');

        return ListField::query()
            ->where('type', ListFieldType::Phone)
            ->orderBy('list_id')->orderBy('sort_order')
            ->get()
            ->mapWithKeys(fn (ListField $field): array => [
                (int) $field->id => ($lists[$field->list_id] ?? '?').' — '.$field->label,
            ])
            ->all();
    }

    /**
     * Card summary for a WhatsApp node: the template's name, resolved
     * tenant-scoped so a foreign id renders as nothing rather than leaking
     * another account's template name (WP-46 §2.10).
     *
     * @param  array<string, mixed>  $config
     */
    public static function whatsAppTemplateName(array $config): string
    {
        $id = (int) ($config['whatsapp_template_id'] ?? 0);

        if ($id <= 0) {
            return '';
        }

        return (string) (WhatsAppTemplate::query()->whereKey($id)->value('name') ?? '');
    }

    /**
     * Pipeline stages, pipeline-prefixed (WP-44 §2.13). Tenant-scoped by the
     * models' global scope; passing a pipeline id narrows to one board.
     *
     * @return array<int, string>
     */
    public static function stageOptions(int $pipelineId = 0): array
    {
        $pipelines = CrmPipeline::query()->orderBy('position')->pluck('name', 'id');

        return CrmPipelineStage::query()
            ->when($pipelineId > 0, fn ($query) => $query->where('pipeline_id', $pipelineId))
            ->orderBy('pipeline_id')->orderBy('position')
            ->get()
            ->mapWithKeys(fn (CrmPipelineStage $stage): array => [
                (int) $stage->id => ($pipelines[$stage->pipeline_id] ?? '?').' — '.$stage->name,
            ])
            ->all();
    }

    /** @return array<int, string> */
    public static function segmentOptions(): array
    {
        $lists = EmailList::query()->orderBy('name')->pluck('name', 'id');

        return ListSegment::query()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (ListSegment $segment): array => [
                (int) $segment->id => ($lists[$segment->list_id] ?? '?').' — '.$segment->name,
            ])
            ->all();
    }

    /** Compact human summary for the node card. @param array<string, mixed> $node */
    public static function summary(array $node): string
    {
        $type = AutomationNodeType::tryFrom((string) ($node['type'] ?? ''));
        $config = (array) ($node['config'] ?? []);

        return match ($type) {
            AutomationNodeType::ActionSendEmail => (string) ($config['subject'] ?? ''),
            AutomationNodeType::ActionNotifyTeam => (string) ($config['recipients'] ?? ''),
            AutomationNodeType::ActionAddTag,
            AutomationNodeType::ActionRemoveTag,
            AutomationNodeType::TriggerTagAdded,
            AutomationNodeType::TriggerTagRemoved => (string) ($config['tag'] ?? ''),
            AutomationNodeType::LogicDelay => match ((string) ($config['mode'] ?? 'duration')) {
                'until_date' => (string) ($config['date'] ?? ''),
                'until_time' => (string) ($config['time'] ?? ''),
                'until_event' => (string) ($config['event'] ?? ''),
                default => ($config['value'] ?? 1).' '.($config['unit'] ?? 'hours'),
            },
            AutomationNodeType::LogicWaitEvent => (string) ($config['event'] ?? ''),
            AutomationNodeType::LogicPercentage => collect((array) ($config['variants'] ?? []))
                ->pluck('percent')->implode('% / ').'%',
            AutomationNodeType::ActionWebhook => (string) ($config['url'] ?? ''),
            AutomationNodeType::ActionSms => Str::limit((string) ($config['body'] ?? ''), 60),
            // WP-46 §2.10 multichannel journey nodes.
            AutomationNodeType::ActionWhatsApp => self::whatsAppTemplateName($config),
            AutomationNodeType::ActionWebPush => Str::limit((string) ($config['title'] ?? ''), 60),
            AutomationNodeType::TriggerCampaignNotOpened => __('automations.fields.days').': '.($config['days'] ?? '?'),
            // WP-45 §2.12: show the narrowing filter, or nothing when the
            // trigger is left on "any", which is the common case.
            AutomationNodeType::TriggerCartAbandoned,
            AutomationNodeType::TriggerOrderPlaced,
            AutomationNodeType::TriggerOrderShipped => trim((string) ($config['category'] ?? ''))
                ?: (((int) ($config['min_total'] ?? 0)) > 0 ? '≥ '.$config['min_total'] : ''),
            AutomationNodeType::TriggerProductViewed,
            AutomationNodeType::TriggerBackInStock => trim((string) ($config['product_external_id'] ?? ''))
                ?: trim((string) ($config['category'] ?? '')),
            AutomationNodeType::TriggerPageVisited => (string) ($config['path'] ?? ''),
            default => '',
        };
    }
}
