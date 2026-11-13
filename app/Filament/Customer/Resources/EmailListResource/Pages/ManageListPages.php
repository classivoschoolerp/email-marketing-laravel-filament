<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\EmailListResource\Pages;

use App\Enums\ListPageType;
use App\Filament\Customer\Resources\EmailListResource;
use App\Models\EmailList;
use App\Services\Lists\ListPageResolver;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * List pages editor (spec §2.2.12): per-type rich editor with an HTML
 * source toggle, subject line for the email types, merge-tag reference
 * panel, per-page redirect override and reset-to-default. Content is stored
 * per list in list_pages; ListPageResolver merges the translatable
 * defaults; WP-09 renders the public pages.
 */
class ManageListPages extends Page implements HasForms
{
    use InteractsWithForms;
    use InteractsWithRecord;

    protected static string $resource = EmailListResource::class;

    protected static string $view = 'filament.customer.resources.email-list-resource.pages.manage-list-pages';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canEdit($this->getRecordModel()), 403);

        $this->form->fill([
            'type' => ListPageType::SubscribeForm->value,
            'html_mode' => false,
        ]);

        $this->loadPage(ListPageType::SubscribeForm);
    }

    public function getTitle(): string|Htmlable
    {
        return __('lists.pages.title', ['name' => $this->getRecordModel()->name]);
    }

    public function getRecordModel(): EmailList
    {
        /** @var EmailList $record */
        $record = $this->record;

        return $record;
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Forms\Components\Grid::make(3)->schema([
                Forms\Components\Select::make('type')
                    ->label(__('lists.pages.type'))
                    ->options(ListPageType::options())
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (mixed $state): void {
                        $type = ListPageType::tryFrom((string) $state);

                        if ($type !== null) {
                            $this->loadPage($type);
                        }
                    }),
                // WYSIWYG ↔ HTML source toggle (F-2.2.12-002).
                Forms\Components\Toggle::make('html_mode')
                    ->label(__('lists.pages.html_mode'))
                    ->live()
                    ->inline(false)
                    ->visible(fn (Forms\Get $get): bool => ! $this->currentType($get)->isRedirectOnly()),
                Forms\Components\Placeholder::make('customized')
                    ->label(__('lists.pages.state'))
                    ->content(fn (Forms\Get $get): string => app(ListPageResolver::class)
                        ->record($this->getRecordModel(), $this->currentType($get)) !== null
                            ? __('lists.pages.state_customized')
                            : __('lists.pages.state_default')),
            ]),
            Forms\Components\TextInput::make('subject')
                ->label(__('lists.pages.subject'))
                ->maxLength(191)
                ->visible(fn (Forms\Get $get): bool => $this->currentType($get)->isEmail()),
            // Both components share the "content" state path; visibility is
            // mutually exclusive, so exactly one editor binds at a time.
            Forms\Components\RichEditor::make('content')
                ->label(__('lists.pages.content'))
                ->visible(fn (Forms\Get $get): bool => ! (bool) $get('html_mode')
                    && ! $this->currentType($get)->isRedirectOnly()),
            Forms\Components\Textarea::make('content')
                ->label(__('lists.pages.content_html'))
                ->rows(12)
                ->extraAttributes(['class' => 'font-mono text-xs'])
                ->visible(fn (Forms\Get $get): bool => (bool) $get('html_mode')
                    && ! $this->currentType($get)->isRedirectOnly()),
            // Per-page redirect override (F-2.2.12-005).
            Forms\Components\TextInput::make('redirect_url')
                ->label(__('lists.pages.redirect_url'))
                ->helperText(__('lists.pages.redirect_url_help'))
                ->url()
                ->maxLength(191)
                ->visible(fn (Forms\Get $get): bool => $this->currentType($get)->supportsRedirect()),
            // Per-page custom CSS/JS injected into the public render
            // (WP-09, F-1.5-017). Web page types only.
            Forms\Components\Textarea::make('custom_css')
                ->label(__('lists.pages.custom_css'))
                ->helperText(__('lists.pages.custom_css_help'))
                ->rows(6)
                ->extraAttributes(['class' => 'font-mono text-xs'])
                ->visible(fn (Forms\Get $get): bool => ! $this->currentType($get)->isEmail()
                    && ! $this->currentType($get)->isRedirectOnly()),
            Forms\Components\Textarea::make('custom_js')
                ->label(__('lists.pages.custom_js'))
                ->helperText(__('lists.pages.custom_js_help'))
                ->rows(6)
                ->extraAttributes(['class' => 'font-mono text-xs'])
                ->visible(fn (Forms\Get $get): bool => ! $this->currentType($get)->isEmail()
                    && ! $this->currentType($get)->isRedirectOnly()),
            // Merge tag reference panel (F-2.2.12-003).
            Forms\Components\Section::make(__('lists.pages.merge_tags'))
                ->collapsed()
                ->schema([
                    Forms\Components\Placeholder::make('merge_tags_panel')
                        ->hiddenLabel()
                        ->content(new HtmlString($this->mergeTagsHtml())),
                ]),
        ]);
    }

    /** Load stored-or-default content for a page type into the editor. */
    public function loadPage(ListPageType $type): void
    {
        $resolver = app(ListPageResolver::class);
        $list = $this->getRecordModel();
        $record = $resolver->record($list, $type);

        $this->data = array_merge((array) $this->data, [
            'type' => $type->value,
            'content' => $resolver->content($list, $type),
            'subject' => $type->isEmail() ? $resolver->subject($list, $type) : null,
            'redirect_url' => $record?->redirect_url,
            'custom_css' => $record?->custom_css,
            'custom_js' => $record?->custom_js,
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $type = ListPageType::from((string) $state['type']);

        app(ListPageResolver::class)->save(
            $this->getRecordModel(),
            $type,
            ($state['content'] ?? null) ?: null,
            ($state['subject'] ?? null) ?: null,
            ($state['redirect_url'] ?? null) ?: null,
            ($state['custom_css'] ?? null) ?: null,
            ($state['custom_js'] ?? null) ?: null,
        );

        Notification::make()->title(__('lists.pages.saved'))->success()->send();
    }

    /** Reset to default (F-2.2.12-004): drop the override row. */
    public function resetToDefault(): void
    {
        $type = ListPageType::from((string) ($this->data['type'] ?? ListPageType::SubscribeForm->value));

        app(ListPageResolver::class)->reset($this->getRecordModel(), $type);
        $this->loadPage($type);

        Notification::make()->title(__('lists.pages.reset_done'))->success()->send();
    }

    private function currentType(Forms\Get $get): ListPageType
    {
        return ListPageType::tryFrom((string) $get('type')) ?? ListPageType::SubscribeForm;
    }

    private function mergeTagsHtml(): string
    {
        $html = '';

        foreach (ListPageResolver::mergeTags() as $group => $tags) {
            $html .= '<p class="mb-1 mt-2 text-sm font-semibold">'.e(__('lists.pages.merge_group_'.$group)).'</p>';
            $html .= '<p class="text-sm">'.implode(' ', array_map(
                fn (string $tag): string => '<code class="mr-2 rounded bg-gray-100 px-1 py-0.5 text-xs dark:bg-gray-800">'.e($tag).'</code>',
                $tags,
            )).'</p>';
        }

        return $html;
    }
}
