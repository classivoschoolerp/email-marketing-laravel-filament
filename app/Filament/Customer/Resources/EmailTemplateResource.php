<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources;

use App\Enums\TemplateBuilder;
use App\Filament\Customer\Resources\EmailTemplateResource\Pages;
use App\Models\EmailTemplate;
use App\Models\TemplateCategory;
use App\Services\Templates\Builders\BuilderRegistry;
use App\Support\Templates\TemplateTags;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;

/**
 * Customer templates resource (spec §2.5.1–§2.5.5). The list combines the
 * customer's own templates with published system gallery templates via the
 * documented EmailTemplate::browsable() scope; gallery rows are read-only
 * (EmailTemplatePolicy) and offer "use this" copying instead of editing.
 */
class EmailTemplateResource extends Resource
{
    protected static ?string $model = EmailTemplate::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope-open';

    protected static ?string $slug = 'email-templates';

    protected static ?int $navigationSort = 40;

    public static function getModelLabel(): string
    {
        return __('templates.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('templates.resource.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('templates.resource.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('templates.resource.navigation_group');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->browsable();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('templates.form.details'))
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label(__('templates.fields.name'))
                        ->required()
                        ->maxLength(255),
                    Forms\Components\Select::make('category_id')
                        ->label(__('templates.fields.category'))
                        ->options(fn (): array => TemplateCategory::query()
                            ->orderBy('sort_order')
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->nullable(),
                    Forms\Components\Select::make('builder')
                        ->label(__('templates.fields.builder'))
                        ->helperText(__('templates.help.builder'))
                        ->options(fn (): array => app(BuilderRegistry::class)->options())
                        ->default(fn (): string => app(BuilderRegistry::class)
                            ->defaultFor(auth('customer')->user())->value)
                        ->in(array_keys(app(BuilderRegistry::class)->options()))
                        ->required(),

                    // F-2.5.1-002 tags (D-34 resolved in WP-27B). Values are
                    // normalised by the model's saving hook, so what the user
                    // types is what the index filter can match.
                    Forms\Components\TagsInput::make('tags')
                        ->label(__('templates.fields.tags'))
                        ->helperText(__('templates.help.tags'))
                        ->placeholder(__('templates.placeholders.tags'))
                        ->suggestions(fn (): array => array_keys(TemplateTags::options()))
                        ->nestedRecursiveRules(['string', 'max:'.TemplateTags::MAX_LENGTH])
                        ->rules(['array', 'max:'.TemplateTags::MAX_PER_TEMPLATE])
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /** Starter content for new templates — includes the required unsubscribe tag. */
    public static function starterHtml(): string
    {
        return '<html><head><meta charset="utf-8"></head><body style="margin:0;padding:0;background-color:#f3f4f6;">'
            .'<div style="max-width:600px;margin:0 auto;padding:24px;background-color:#ffffff;font-family:Arial,Helvetica,sans-serif;">'
            .'<p>'.__('templates.starter.greeting').'</p>'
            .'<p></p>'
            .'<p style="font-size:12px;color:#6b7280;">{COMPANY_NAME} &middot; {COMPANY_FULL_ADDRESS} &middot; '
            .'<a href="{UNSUBSCRIBE_URL}">'.__('templates.starter.unsubscribe').'</a></p>'
            .'</div></body></html>';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmailTemplates::route('/'),
            'create' => Pages\CreateEmailTemplate::route('/create'),
            'edit' => Pages\EditEmailTemplate::route('/{record}/edit'),
            'builder' => Pages\BuildEmailTemplate::route('/{record}/builder'),
            // F-2.5.3-001 (WP-27B): the simple built-in block builder.
            'basic' => Pages\EditWithBasicBuilder::route('/{record}/basic'),
            // F-2.5.3-003 embedded Stripo editor (WP-26). The page itself
            // 403s unless the Stripo plugin credentials are configured.
            'stripo' => Pages\EditWithStripo::route('/{record}/stripo'),
        ];
    }

    public static function builderCase(EmailTemplate $record): TemplateBuilder
    {
        return $record->builder instanceof TemplateBuilder
            ? $record->builder
            : TemplateBuilder::from((string) $record->builder);
    }
}
