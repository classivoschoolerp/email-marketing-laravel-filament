<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\EmailTemplateResource\Pages;

use App\Enums\TemplateBuilder;
use App\Filament\Customer\Resources\EmailTemplateResource;
use App\Filament\Customer\Resources\EmailTemplateResource\Concerns\HasTemplateRevisions;
use App\Models\EmailTemplate;
use App\Models\TemplateCategory;
use App\Services\Templates\Builders\BuilderRegistry;
use App\Services\Templates\MergeTags;
use App\Services\Templates\TemplateRevisions;
use App\Services\Templates\TemplateThumbnails;
use App\Services\Templates\UnsubscribeTagChecker;
use Closure;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Str;

/**
 * HTML / rich-text edit surface (spec §2.5.3 F-2.5.3-006 — the raw HTML
 * editor is always available). GrapesJS templates are redirected to the
 * builder page. Saves run the unsubscribe-tag check (F-2.5.5-006) and record
 * a revision (F-2.5.4-008).
 */
class EditEmailTemplate extends EditRecord
{
    use HasTemplateRevisions;

    protected static string $resource = EmailTemplateResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var EmailTemplate $template */
        $template = $this->getRecord();

        // GrapesJS templates edit in the builder; ?settings=1 opens this form
        // anyway (rename / category / fonts for builder templates).
        if (
            EmailTemplateResource::builderCase($template) === TemplateBuilder::GrapesJs
            && ! request()->boolean('settings')
        ) {
            $this->redirect(EmailTemplateResource::getUrl('builder', ['record' => $template]));
        }
    }

    public function form(Form $form): Form
    {
        /** @var EmailTemplate $template */
        $template = $this->getRecord();
        $isRichText = EmailTemplateResource::builderCase($template) === TemplateBuilder::RichText;

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
                        ->in(array_keys(app(BuilderRegistry::class)->options()))
                        ->required(),
                    Forms\Components\TagsInput::make('google_fonts')
                        ->label(__('templates.fields.google_fonts'))
                        ->helperText(__('templates.help.google_fonts'))
                        ->placeholder('https://fonts.googleapis.com/css2?family=Inter')
                        ->nestedRecursiveRules(['url', function (string $attribute, mixed $value, Closure $fail): void {
                            $host = parse_url((string) $value, PHP_URL_HOST);
                            $allowed = config('emp-templates.google_fonts_hosts', []);

                            if (! is_string($host) || ! in_array(Str::lower($host), $allowed, true)) {
                                $fail(__('templates.errors.font_host', ['hosts' => implode(', ', $allowed)]));
                            }
                        }]),
                ]),

            Forms\Components\Section::make(__('templates.form.content'))
                ->schema([
                    $isRichText
                        ? Forms\Components\RichEditor::make('content_html')
                            ->label(__('templates.fields.content'))
                            ->nullable()
                        : Forms\Components\Textarea::make('content_html')
                            ->label(__('templates.fields.content'))
                            ->rows(24)
                            ->extraAttributes(['class' => 'font-mono text-xs'])
                            ->nullable(),
                    Forms\Components\Placeholder::make('merge_tags_hint')
                        ->label(__('templates.fields.merge_tags'))
                        ->content(implode(', ', array_map(
                            fn (string $tag): string => '{'.$tag.'}',
                            app(MergeTags::class)->tags(),
                        ))),
                ]),
        ]);
    }

    protected function beforeSave(): void
    {
        $checker = app(UnsubscribeTagChecker::class);
        $html = (string) ($this->data['content_html'] ?? '');

        if ($checker->blocks($html)) {
            Notification::make()->danger()
                ->title(__('templates.errors.unsubscribe_required'))
                ->persistent()
                ->send();

            $this->halt();
        }

        if (! $checker->passes($html)) {
            Notification::make()->warning()
                ->title(__('templates.warnings.missing_unsubscribe'))
                ->send();
        }
    }

    protected function afterSave(): void
    {
        /** @var EmailTemplate $record */
        $record = $this->getRecord();

        $record->forceFill([
            'missing_unsubscribe_tag' => ! app(UnsubscribeTagChecker::class)->passes($record->content_html),
        ])->saveQuietly();

        app(TemplateRevisions::class)->record($record);
        app(TemplateThumbnails::class)->refreshFromContent($record);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('preview')
                ->label(__('templates.actions.preview'))
                ->icon('heroicon-m-eye')
                ->modalHeading(fn (): string => $this->getRecord()->name)
                ->modalContent(fn () => view('filament.customer.templates.preview', [
                    'html' => app(MergeTags::class)->renderSample($this->getRecord()->content_html),
                ]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('templates.actions.close')),
            // WP-46 §2.15.8 F-2.15.8-006 / F-2.15.8-009.
            $this->revisionsAction(),
            Actions\DeleteAction::make(),
        ];
    }
}
