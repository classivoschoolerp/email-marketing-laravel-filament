<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Enums\ListPageType;
use App\Services\Lists\ListPageResolver;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * Admin editor for the per-install default content of the 11 list page
 * types (spec §3.16, F-3.16-001/002). Saved rows land in list_page_defaults
 * — the middle layer of ListPageResolver's 3-layer merge (per-list override
 * > admin default > translatable default) — so uncustomized lists inherit
 * them immediately. Includes the full merge-tag reference panel per page
 * type (F-3.16-002).
 */
class ListPageDefaultsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-duplicate';


    protected static ?string $slug = 'list-page-defaults';

    protected static ?int $navigationSort = 41;

    public static function getNavigationGroup(): ?string
    {
        return __('themes.nav_group');
    }

    protected static string $view = 'filament.admin.pages.list-page-defaults';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'type' => ListPageType::SubscribeForm->value,
            'html_mode' => false,
        ]);

        $this->loadType(ListPageType::SubscribeForm);
    }

    public function getTitle(): string|Htmlable
    {
        return __('public.page_defaults.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('public.page_defaults.navigation_label');
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
                            $this->loadType($type);
                        }
                    }),
                Forms\Components\Toggle::make('html_mode')
                    ->label(__('lists.pages.html_mode'))
                    ->live()
                    ->inline(false)
                    ->visible(fn (Forms\Get $get): bool => ! $this->currentType($get)->isRedirectOnly()),
                Forms\Components\Placeholder::make('state')
                    ->label(__('lists.pages.state'))
                    ->content(fn (Forms\Get $get): string => app(ListPageResolver::class)
                        ->defaultRecord($this->currentType($get)) !== null
                            ? __('public.page_defaults.state_customized')
                            : __('public.page_defaults.state_translatable')),
            ]),
            Forms\Components\TextInput::make('subject')
                ->label(__('lists.pages.subject'))
                ->maxLength(191)
                ->visible(fn (Forms\Get $get): bool => $this->currentType($get)->isEmail()),
            // Redirect-only page type carries no default content (§1.5).
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
            // Full merge tag reference per page type (F-3.16-002).
            Forms\Components\Section::make(__('lists.pages.merge_tags'))
                ->collapsed()
                ->schema([
                    Forms\Components\Placeholder::make('merge_tags_panel')
                        ->hiddenLabel()
                        ->content(new HtmlString($this->mergeTagsHtml())),
                ]),
        ]);
    }

    /** Load the effective default (admin row or translatable) into the form. */
    public function loadType(ListPageType $type): void
    {
        $resolver = app(ListPageResolver::class);

        $this->data = array_merge((array) $this->data, [
            'type' => $type->value,
            'content' => $resolver->defaultContent($type),
            'subject' => $resolver->defaultSubject($type),
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $type = ListPageType::from((string) $state['type']);

        app(ListPageResolver::class)->saveDefault(
            $type,
            ($state['content'] ?? null) ?: null,
            ($state['subject'] ?? null) ?: null,
        );

        Notification::make()->title(__('public.page_defaults.saved'))->success()->send();
    }

    /** Drop the admin default → back to the translatable layer. */
    public function resetToDefault(): void
    {
        $type = ListPageType::from((string) ($this->data['type'] ?? ListPageType::SubscribeForm->value));

        app(ListPageResolver::class)->resetDefault($type);
        $this->loadType($type);

        Notification::make()->title(__('public.page_defaults.reset_done'))->success()->send();
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
