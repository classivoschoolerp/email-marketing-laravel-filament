<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources;

use App\Enums\SurveyStatus;
use App\Filament\Customer\Resources\SurveyResource\Pages;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Survey;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;

/**
 * Surveys module (spec §2.8 — WP-31) and the settings behind the §1.9
 * public pages: identity + window + responder cap (F-2.8-002), public page
 * customisation (F-2.8-004) and the anonymous/identified response model
 * (F-2.8-009). Questions, responders and stats live on their own pages.
 *
 * Tenancy: global scope + an explicit customer_id constraint.
 */
class SurveyResource extends Resource
{
    protected static ?string $model = Survey::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $slug = 'surveys';

    protected static ?int $navigationSort = 36;

    public static function getNavigationGroup(): ?string
    {
        return __('surveys.resource.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('surveys.resource.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('surveys.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('surveys.resource.plural_label');
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
            Forms\Components\Tabs::make('survey')->columnSpanFull()->tabs([

                // F-2.8-002
                Forms\Components\Tabs\Tab::make(__('surveys.tabs.general'))->schema([
                    Forms\Components\TextInput::make('name')
                        ->label(__('surveys.fields.name'))
                        ->required()
                        ->maxLength(191),

                    Forms\Components\TextInput::make('display_name')
                        ->label(__('surveys.fields.display_name'))
                        ->helperText(__('surveys.fields.display_name_help'))
                        ->maxLength(191),

                    Forms\Components\Textarea::make('description')
                        ->label(__('surveys.fields.description'))
                        ->rows(3)
                        ->columnSpanFull(),

                    Forms\Components\Select::make('list_id')
                        ->label(__('surveys.fields.list'))
                        ->options(fn (): array => EmailList::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->placeholder(__('surveys.fields.list_none'))
                        ->helperText(__('surveys.fields.list_help')),

                    Forms\Components\Select::make('status')
                        ->label(__('surveys.fields.status'))
                        ->options(SurveyStatus::options())
                        ->default(SurveyStatus::Draft->value)
                        ->required(),

                    Forms\Components\DateTimePicker::make('start_at')
                        ->label(__('surveys.fields.start_at'))
                        ->helperText(__('surveys.fields.start_at_help'))
                        ->seconds(false),

                    Forms\Components\DateTimePicker::make('end_at')
                        ->label(__('surveys.fields.end_at'))
                        ->helperText(__('surveys.fields.end_at_help'))
                        ->after('start_at')
                        ->seconds(false),

                    Forms\Components\TextInput::make('max_responders')
                        ->label(__('surveys.fields.max_responders'))
                        ->helperText(__('surveys.fields.max_responders_help'))
                        ->numeric()
                        ->minValue(0),
                ])->columns(2),

                // F-2.8-004 / §1.9
                Forms\Components\Tabs\Tab::make(__('surveys.tabs.public_page'))->schema([
                    Forms\Components\Textarea::make('start_page_content')
                        ->label(__('surveys.fields.start_page_content'))
                        ->helperText(__('surveys.fields.html_allowed'))
                        ->rows(4),
                    Forms\Components\Textarea::make('thank_you_content')
                        ->label(__('surveys.fields.thank_you_content'))
                        ->helperText(__('surveys.fields.html_allowed'))
                        ->rows(4),
                    Forms\Components\Textarea::make('closed_message')
                        ->label(__('surveys.fields.closed_message'))
                        ->rows(2),
                    Forms\Components\TextInput::make('redirect_url')
                        ->label(__('surveys.fields.redirect_url'))
                        ->helperText(__('surveys.fields.redirect_url_help'))
                        ->url()
                        ->maxLength(2048),
                    Forms\Components\TextInput::make('meta_title')
                        ->label(__('surveys.fields.meta_title'))
                        ->maxLength(191),
                    Forms\Components\TextInput::make('meta_description')
                        ->label(__('surveys.fields.meta_description'))
                        ->maxLength(500),
                    Forms\Components\Toggle::make('show_progress_bar')
                        ->label(__('surveys.fields.show_progress_bar'))
                        ->default(true),
                    Forms\Components\TextInput::make('fields_per_step')
                        ->label(__('surveys.fields.fields_per_step'))
                        ->helperText(__('surveys.fields.fields_per_step_help'))
                        ->numeric()
                        ->minValue(0)
                        ->default(0),
                ])->columns(2),

                // F-2.8-009
                Forms\Components\Tabs\Tab::make(__('surveys.tabs.responses'))->schema([
                    Forms\Components\Toggle::make('allow_anonymous')
                        ->label(__('surveys.fields.allow_anonymous'))
                        ->helperText(__('surveys.fields.allow_anonymous_help'))
                        ->default(true),
                    Forms\Components\Toggle::make('one_response_per_subscriber')
                        ->label(__('surveys.fields.one_response_per_subscriber'))
                        ->helperText(__('surveys.fields.one_response_help'))
                        ->default(true),
                ])->columns(1),
            ]),
        ])->columns(1);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSurveys::route('/'),
            'create' => Pages\CreateSurvey::route('/create'),
            'edit' => Pages\EditSurvey::route('/{record}/edit'),
            'questions' => Pages\ManageSurveyFields::route('/{record}/questions'),
            'responders' => Pages\SurveyResponders::route('/{record}/responders'),
            'stats' => Pages\SurveyStatsPage::route('/{record}/stats'),
        ];
    }
}
