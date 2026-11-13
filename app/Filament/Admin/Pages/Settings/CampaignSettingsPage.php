<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Settings;

use App\Settings\CampaignSettings;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\Rule;

/**
 * Campaign settings page (spec §3.25.3, rows F-3.25.3-001 … F-3.25.3-012).
 */
class CampaignSettingsPage extends BaseSettingsPage
{
    private const DOMAIN_RULE = 'regex:/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i';

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';
    protected static ?string $navigationGroup = 'Sending Infrastructure';

    protected static ?string $slug = 'settings/campaign';

    protected static ?int $navigationSort = 3;

    protected static function settingsClass(): string
    {
        return CampaignSettings::class;
    }

    public function getTitle(): string|Htmlable
    {
        return __('settings.campaign.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('settings.campaign.navigation_label');
    }

    /**
     * @return array<int, Component>
     */
    protected function schema(): array
    {
        return [
            Section::make(__('settings.campaign.sections.attachments'))
                ->columns(2)
                ->schema([
                    Toggle::make('attachments_enabled')
                        ->label(__('settings.campaign.fields.attachments_enabled'))
                        ->columnSpanFull(),
                    self::castInt(
                        TextInput::make('attachment_max_size_mb')
                            ->label(__('settings.campaign.fields.attachment_max_size_mb'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(100)
                            ->required(),
                    ),
                    self::castInt(
                        TextInput::make('attachment_max_count')
                            ->label(__('settings.campaign.fields.attachment_max_count'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(50)
                            ->required(),
                    ),
                    TagsInput::make('attachment_allowed_mime_types')
                        ->label(__('settings.campaign.fields.attachment_allowed_mime_types'))
                        ->placeholder('application/pdf')
                        ->nestedRecursiveRules(['regex:/^[-\w.+]+\/[-\w.+*]+$/']),
                    TagsInput::make('attachment_allowed_extensions')
                        ->label(__('settings.campaign.fields.attachment_allowed_extensions'))
                        ->placeholder('pdf')
                        ->nestedRecursiveRules(['regex:/^[a-z0-9]{1,10}$/i']),
                ]),

            Section::make(__('settings.campaign.sections.template_tags'))
                ->schema([
                    CheckboxList::make('enabled_template_tags')
                        ->label(__('settings.campaign.fields.enabled_template_tags'))
                        ->helperText(__('settings.campaign.help.enabled_template_tags'))
                        ->options(array_combine(CampaignSettings::KNOWN_TEMPLATE_TAGS, CampaignSettings::KNOWN_TEMPLATE_TAGS))
                        ->nestedRecursiveRules([Rule::in(CampaignSettings::KNOWN_TEMPLATE_TAGS)])
                        ->columns(3)
                        ->bulkToggleable(),
                ]),

            Section::make(__('settings.campaign.sections.content_filters'))
                ->description(__('settings.campaign.sections.content_filters_description'))
                ->columns(2)
                ->schema([
                    TagsInput::make('subject_blacklisted_words')
                        ->label(__('settings.campaign.fields.subject_blacklisted_words')),
                    TagsInput::make('content_blacklisted_words')
                        ->label(__('settings.campaign.fields.content_blacklisted_words')),
                    Select::make('blacklisted_words_action')
                        ->label(__('settings.campaign.fields.blacklisted_words_action'))
                        ->helperText(__('settings.campaign.help.blacklisted_words_action'))
                        ->options([
                            CampaignSettings::BLACKLISTED_WORDS_ACTION_BLOCK => __('settings.campaign.options.block'),
                            CampaignSettings::BLACKLISTED_WORDS_ACTION_FLAG => __('settings.campaign.options.flag'),
                        ])
                        ->in([
                            CampaignSettings::BLACKLISTED_WORDS_ACTION_BLOCK,
                            CampaignSettings::BLACKLISTED_WORDS_ACTION_FLAG,
                        ])
                        ->required(),
                    TagsInput::make('forbidden_from_domains')
                        ->label(__('settings.campaign.fields.forbidden_from_domains'))
                        ->helperText(__('settings.campaign.help.forbidden_from_domains'))
                        ->placeholder('spam-domain.example')
                        ->nestedRecursiveRules([self::DOMAIN_RULE]),
                    TagsInput::make('forbidden_reply_to_domains')
                        ->label(__('settings.campaign.fields.forbidden_reply_to_domains'))
                        ->helperText(__('settings.campaign.help.forbidden_reply_to_domains'))
                        ->placeholder('spam-domain.example')
                        ->nestedRecursiveRules([self::DOMAIN_RULE]),
                ]),

            Section::make(__('settings.campaign.sections.webhooks'))
                ->columns(2)
                ->schema([
                    Toggle::make('webhooks_enabled')
                        ->label(__('settings.campaign.fields.webhooks_enabled')),
                    TagsInput::make('webhook_endpoints')
                        ->label(__('settings.campaign.fields.webhook_endpoints'))
                        ->helperText(__('settings.campaign.help.webhook_endpoints'))
                        ->placeholder('https://example.com/webhook')
                        ->nestedRecursiveRules(['url']),
                ]),

            Section::make(__('settings.campaign.sections.tracking'))
                ->columns(2)
                ->schema([
                    Toggle::make('default_open_tracking')
                        ->label(__('settings.campaign.fields.default_open_tracking')),
                    Toggle::make('default_click_tracking')
                        ->label(__('settings.campaign.fields.default_click_tracking')),
                ]),

            Section::make(__('settings.campaign.sections.sending'))
                ->columns(2)
                ->schema([
                    self::castInt(
                        TextInput::make('max_campaign_recipients')
                            ->label(__('settings.campaign.fields.max_campaign_recipients'))
                            ->helperText(__('settings.campaign.help.max_campaign_recipients'))
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->required(),
                    ),
                    Toggle::make('approval_required')
                        ->label(__('settings.campaign.fields.approval_required')),
                    self::castInt(
                        TextInput::make('send_at_min_offset_minutes')
                            ->label(__('settings.campaign.fields.send_at_min_offset_minutes'))
                            ->helperText(__('settings.campaign.help.send_at_min_offset_minutes'))
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(10080)
                            ->required(),
                    ),
                    Toggle::make('default_plain_text_generation')
                        ->label(__('settings.campaign.fields.default_plain_text_generation'))
                        ->helperText(__('settings.campaign.help.default_plain_text_generation')),
                    self::castFloat(
                        TextInput::make('bounce_auto_pause_percent')
                            ->label(__('settings.campaign.fields.bounce_auto_pause_percent'))
                            ->helperText(__('settings.campaign.help.bounce_auto_pause_percent'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->required(),
                    ),
                    self::castFloat(
                        TextInput::make('complaint_auto_pause_percent')
                            ->label(__('settings.campaign.fields.complaint_auto_pause_percent'))
                            ->helperText(__('settings.campaign.help.complaint_auto_pause_percent'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->required(),
                    ),
                ]),

            Section::make(__('settings.campaign.sections.retry'))
                ->columns(2)
                ->schema([
                    self::castInt(
                        TextInput::make('retry_max_attempts')
                            ->label(__('settings.campaign.fields.retry_max_attempts'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(10)
                            ->required(),
                    ),
                    self::castInt(
                        TextInput::make('retry_backoff_seconds')
                            ->label(__('settings.campaign.fields.retry_backoff_seconds'))
                            ->helperText(__('settings.campaign.help.retry_backoff_seconds'))
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(86400)
                            ->required(),
                    ),
                ]),
        ];
    }
}
