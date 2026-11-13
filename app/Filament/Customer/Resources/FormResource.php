<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources;

use App\Enums\FormStatus;
use App\Enums\FormType;
use App\Enums\ListFieldType;
use App\Filament\Customer\Resources\FormResource\Pages;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Form as FormModel;
use App\Models\ListField;
use App\Services\Forms\DisplayRules;
use App\Services\Forms\FormSteps;
use App\Services\Forms\FormStyles;
use App\Services\Forms\FrequencyRules;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;

/**
 * Forms & popups module (spec §2.7 — WP-31). Index (F-2.7-001), settings
 * with the type picker (F-2.7-002), display rules (F-2.7-005), frequency
 * rules (F-2.7-006), styling editor (F-2.7-007), success handling
 * (F-2.7-008), GDPR consent (F-2.7-009) and captcha toggle (F-2.7-010);
 * the field builder, embed codes and analytics live on their own pages.
 *
 * Tenancy: global scope + an explicit customer_id constraint (defense in
 * depth, CampaignResource pattern).
 */
class FormResource extends Resource
{
    protected static ?string $model = FormModel::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?string $slug = 'forms';

    protected static ?int $navigationSort = 34;

    public static function getNavigationGroup(): ?string
    {
        return __('forms.resource.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('forms.resource.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('forms.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('forms.resource.plural_label');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Filament::auth()->user();

        if ($user instanceof Customer) {
            $query->where('customer_id', $user->id);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Tabs::make('form')->columnSpanFull()->tabs([

                Forms\Components\Tabs\Tab::make(__('forms.tabs.general'))->schema([
                    Forms\Components\TextInput::make('name')
                        ->label(__('forms.fields.name'))
                        ->required()
                        ->maxLength(191),

                    Forms\Components\Select::make('list_id')
                        ->label(__('forms.fields.list'))
                        ->options(fn (): array => EmailList::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->required()
                        ->live()
                        ->helperText(__('forms.fields.list_help')),

                    // F-2.7-002: the six shapes.
                    Forms\Components\Select::make('type')
                        ->label(__('forms.fields.type'))
                        ->options(FormType::options())
                        ->default(FormType::Embedded->value)
                        ->required(),

                    Forms\Components\Select::make('status')
                        ->label(__('forms.fields.status'))
                        ->options(FormStatus::options())
                        ->default(FormStatus::Draft->value)
                        ->required()
                        ->helperText(__('forms.fields.status_help')),

                    // F-2.7-013. The arms themselves live on their own page;
                    // this is the switch that puts the test live.
                    Forms\Components\Toggle::make('ab_enabled')
                        ->label(__('forms.ab.enabled'))
                        ->helperText(__('forms.ab.enabled_help'))
                        ->columnSpanFull(),
                ])->columns(2),

                Forms\Components\Tabs\Tab::make(__('forms.tabs.content'))->schema([
                    Forms\Components\TextInput::make('heading')
                        ->label(__('forms.fields.heading'))
                        ->maxLength(191),
                    Forms\Components\Textarea::make('description')
                        ->label(__('forms.fields.description'))
                        ->rows(3),
                    Forms\Components\TextInput::make('email_label')
                        ->label(__('forms.fields.email_label'))
                        ->maxLength(120)
                        ->placeholder(__('forms.public.email_label')),
                    Forms\Components\TextInput::make('submit_label')
                        ->label(__('forms.fields.submit_label'))
                        ->maxLength(120)
                        ->placeholder(__('forms.public.submit_label')),
                ])->columns(2),

                // F-2.7-008
                Forms\Components\Tabs\Tab::make(__('forms.tabs.success'))->schema([
                    Forms\Components\Radio::make('success_mode')
                        ->label(__('forms.fields.success_mode'))
                        ->options([
                            FormModel::SUCCESS_MESSAGE => __('forms.success.message'),
                            FormModel::SUCCESS_REDIRECT => __('forms.success.redirect'),
                            FormModel::SUCCESS_DOWNLOAD => __('forms.success.download'),
                        ])
                        ->default(FormModel::SUCCESS_MESSAGE)
                        ->live()
                        ->required(),

                    Forms\Components\Textarea::make('success_message')
                        ->label(__('forms.fields.success_message'))
                        ->rows(2)
                        ->placeholder(__('forms.public.success_default')),

                    Forms\Components\TextInput::make('redirect_url')
                        ->label(__('forms.fields.redirect_url'))
                        ->url()
                        ->maxLength(2048)
                        ->required(fn (Forms\Get $get): bool => $get('success_mode') === FormModel::SUCCESS_REDIRECT)
                        ->visible(fn (Forms\Get $get): bool => $get('success_mode') === FormModel::SUCCESS_REDIRECT),

                    Forms\Components\TextInput::make('lead_magnet_url')
                        ->label(__('forms.fields.lead_magnet_url'))
                        ->url()
                        ->maxLength(2048)
                        ->helperText(__('forms.fields.lead_magnet_help'))
                        ->required(fn (Forms\Get $get): bool => $get('success_mode') === FormModel::SUCCESS_DOWNLOAD)
                        ->visible(fn (Forms\Get $get): bool => $get('success_mode') === FormModel::SUCCESS_DOWNLOAD),

                    Forms\Components\TextInput::make('lead_magnet_label')
                        ->label(__('forms.fields.lead_magnet_label'))
                        ->maxLength(191)
                        ->visible(fn (Forms\Get $get): bool => $get('success_mode') === FormModel::SUCCESS_DOWNLOAD),
                ])->columns(1),

                // F-2.7-009 / F-2.7-010
                Forms\Components\Tabs\Tab::make(__('forms.tabs.compliance'))->schema([
                    Forms\Components\Toggle::make('consent_enabled')
                        ->label(__('forms.fields.consent_enabled'))
                        ->live(),
                    Forms\Components\Toggle::make('consent_required')
                        ->label(__('forms.fields.consent_required'))
                        ->default(true)
                        ->visible(fn (Forms\Get $get): bool => (bool) $get('consent_enabled')),
                    Forms\Components\Textarea::make('consent_text')
                        ->label(__('forms.fields.consent_text'))
                        ->rows(3)
                        ->maxLength(2000)
                        ->helperText(__('forms.fields.consent_text_help'))
                        ->visible(fn (Forms\Get $get): bool => (bool) $get('consent_enabled')),
                    Forms\Components\Select::make('consent_list_field_id')
                        ->label(__('forms.fields.consent_field'))
                        ->options(fn (Forms\Get $get): array => static::consentFieldOptions($get('list_id')))
                        ->helperText(__('forms.fields.consent_field_help'))
                        ->placeholder(__('forms.fields.consent_field_none'))
                        ->visible(fn (Forms\Get $get): bool => (bool) $get('consent_enabled')),
                    Forms\Components\Toggle::make('captcha_enabled')
                        ->label(__('forms.fields.captcha_enabled'))
                        ->default(true)
                        ->helperText(__('forms.fields.captcha_help')),
                ])->columns(1),

                // F-2.7-004
                Forms\Components\Tabs\Tab::make(__('forms.tabs.steps'))->schema([
                    Forms\Components\Toggle::make('multi_step')
                        ->label(__('forms.steps.multi_step'))
                        ->helperText(__('forms.steps.multi_step_help'))
                        ->live(),

                    Forms\Components\Toggle::make('steps.show_progress')
                        ->label(__('forms.steps.show_progress'))
                        ->default(true)
                        ->visible(fn (Forms\Get $get): bool => (bool) $get('multi_step')),

                    Forms\Components\Repeater::make('steps.items')
                        ->label(__('forms.steps.items'))
                        ->addActionLabel(__('forms.steps.add'))
                        ->maxItems(FormSteps::MAX_STEPS)
                        ->defaultItems(1)
                        ->reorderable()
                        ->itemLabel(fn (array $state): string => trim((string) ($state['title'] ?? '')) ?: __('forms.steps.title'))
                        ->visible(fn (Forms\Get $get): bool => (bool) $get('multi_step'))
                        ->helperText(__('forms.steps.single_step_notice'))
                        ->schema([
                            Forms\Components\TextInput::make('title')
                                ->label(__('forms.steps.step_title'))
                                ->maxLength(191),
                            Forms\Components\TextInput::make('next_label')
                                ->label(__('forms.steps.next_label'))
                                ->helperText(__('forms.steps.next_label_help'))
                                ->maxLength(120),
                            Forms\Components\Textarea::make('description')
                                ->label(__('forms.steps.step_description'))
                                ->rows(2)
                                ->maxLength(1000)
                                ->columnSpanFull(),
                        ])->columns(2),
                ])->columns(1),

                // F-2.7-005
                Forms\Components\Tabs\Tab::make(__('forms.tabs.display'))->schema([
                    Forms\Components\Select::make('display_rules.pages_mode')
                        ->label(__('forms.fields.pages_mode'))
                        ->options(DisplayRules::pageModeOptions())
                        ->default(DisplayRules::PAGES_ALL)
                        ->live(),
                    Forms\Components\Repeater::make('display_rules.pages')
                        ->label(__('forms.fields.pages'))
                        ->simple(
                            Forms\Components\TextInput::make('url')
                                ->label(__('forms.fields.page_url'))
                                ->maxLength(500)
                                ->required(),
                        )
                        ->defaultItems(0)
                        ->visible(fn (Forms\Get $get): bool => $get('display_rules.pages_mode') !== DisplayRules::PAGES_ALL),
                    Forms\Components\TextInput::make('display_rules.delay_seconds')
                        ->label(__('forms.fields.delay_seconds'))
                        ->numeric()->minValue(0)->default(0),
                    Forms\Components\TextInput::make('display_rules.scroll_percent')
                        ->label(__('forms.fields.scroll_percent'))
                        ->numeric()->minValue(0)->maxValue(100)->default(0),
                    Forms\Components\Toggle::make('display_rules.exit_intent')
                        ->label(__('forms.fields.exit_intent')),
                    Forms\Components\TextInput::make('display_rules.min_page_views')
                        ->label(__('forms.fields.min_page_views'))
                        ->numeric()->minValue(0)->default(0),
                    Forms\Components\CheckboxList::make('display_rules.devices')
                        ->label(__('forms.fields.devices'))
                        ->options(DisplayRules::deviceOptions())
                        ->helperText(__('forms.fields.devices_help'))
                        ->columns(3),
                    Forms\Components\Select::make('display_rules.visitors')
                        ->label(__('forms.fields.visitors'))
                        ->options(DisplayRules::visitorOptions())
                        ->default(DisplayRules::VISITORS_ALL),
                ])->columns(2),

                // F-2.7-006
                Forms\Components\Tabs\Tab::make(__('forms.tabs.frequency'))->schema([
                    Forms\Components\TextInput::make('frequency_rules.hide_days_after_close')
                        ->label(__('forms.fields.hide_days_after_close'))
                        ->numeric()->minValue(0)->default(7),
                    Forms\Components\Toggle::make('frequency_rules.hide_after_submit')
                        ->label(__('forms.fields.hide_after_submit'))
                        ->default(true)
                        ->live(),
                    Forms\Components\TextInput::make('frequency_rules.hide_after_submit_days')
                        ->label(__('forms.fields.hide_after_submit_days'))
                        ->helperText(__('forms.fields.zero_forever'))
                        ->numeric()->minValue(0)->default(0)
                        ->visible(fn (Forms\Get $get): bool => (bool) $get('frequency_rules.hide_after_submit')),
                    Forms\Components\TextInput::make('frequency_rules.max_shows_per_visitor')
                        ->label(__('forms.fields.max_shows_per_visitor'))
                        ->helperText(__('forms.fields.zero_unlimited'))
                        ->numeric()->minValue(0)->default(0),
                ])->columns(2),

                // F-2.7-007
                Forms\Components\Tabs\Tab::make(__('forms.tabs.styling'))->schema([
                    Forms\Components\ColorPicker::make('styles.background_color')
                        ->label(__('forms.fields.background_color'))->default('#ffffff'),
                    Forms\Components\ColorPicker::make('styles.text_color')
                        ->label(__('forms.fields.text_color'))->default('#111827'),
                    Forms\Components\ColorPicker::make('styles.button_color')
                        ->label(__('forms.fields.button_color'))->default('#4f46e5'),
                    Forms\Components\ColorPicker::make('styles.button_text_color')
                        ->label(__('forms.fields.button_text_color'))->default('#ffffff'),
                    Forms\Components\Select::make('styles.font')
                        ->label(__('forms.fields.font'))
                        ->options(FormStyles::fontOptions())->default('system'),
                    Forms\Components\Select::make('styles.shadow')
                        ->label(__('forms.fields.shadow'))
                        ->options(FormStyles::shadowOptions())->default('md'),
                    Forms\Components\TextInput::make('styles.corner_radius')
                        ->label(__('forms.fields.corner_radius'))
                        ->numeric()->minValue(0)->maxValue(48)->default(10),
                    Forms\Components\TextInput::make('styles.width')
                        ->label(__('forms.fields.width'))
                        ->numeric()->minValue(240)->maxValue(1200)->default(480),
                    Forms\Components\TextInput::make('styles.image_url')
                        ->label(__('forms.fields.image_url'))
                        ->url()->maxLength(2048),
                    Forms\Components\Select::make('styles.image_position')
                        ->label(__('forms.fields.image_position'))
                        ->options(FormStyles::imagePositionOptions())->default('none'),
                ])->columns(2),
            ]),
        ])->columns(1);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListForms::route('/'),
            'create' => Pages\CreateForm::route('/create'),
            'edit' => Pages\EditForm::route('/{record}/edit'),
            'fields' => Pages\ManageFormFields::route('/{record}/fields'),
            'variants' => Pages\ManageFormVariants::route('/{record}/ab'),
            'embed' => Pages\FormEmbedCode::route('/{record}/embed'),
            'analytics' => Pages\FormAnalyticsPage::route('/{record}/analytics'),
        ];
    }

    /**
     * Consent-type list fields on the selected list (F-2.7-009). Only those
     * produce a WP-22 consent_events row when ticked.
     *
     * @return array<int, string>
     */
    public static function consentFieldOptions(mixed $listId): array
    {
        $listId = (int) $listId;

        if ($listId <= 0) {
            return [];
        }

        return ListField::query()
            ->where('list_id', $listId)
            ->where('type', ListFieldType::Consent->value)
            ->orderBy('sort_order')
            ->pluck('label', 'id')
            ->all();
    }

    /**
     * Normalise the four JSON documents on save so the loader never sees a
     * half-written rule set (the builder and the browser read one schema).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeJsonDocuments(array $data): array
    {
        $data['display_rules'] = DisplayRules::normalize((array) ($data['display_rules'] ?? []));
        $data['frequency_rules'] = FrequencyRules::normalize((array) ($data['frequency_rules'] ?? []));
        $data['styles'] = FormStyles::normalize((array) ($data['styles'] ?? []));
        $data['steps'] = FormSteps::normalize((array) ($data['steps'] ?? []));

        return $data;
    }
}
