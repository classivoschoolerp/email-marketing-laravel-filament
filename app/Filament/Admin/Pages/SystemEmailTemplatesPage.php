<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Services\SystemEmails\SystemEmailRenderer;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * Admin editor for the §3.15 system email templates (WP-20, F-3.15-001..016):
 * every key SystemEmailRenderer knows is editable here — subject + HTML body
 * + enabled toggle + per-key merge-tag reference + reset-to-default —
 * mirroring the ListPageDefaultsPage pattern. The overview panel in the view
 * lists every key with its customized/default state.
 */
class SystemEmailTemplatesPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';


    protected static ?string $slug = 'system-email-templates';

    protected static ?int $navigationSort = 42;

    public static function getNavigationGroup(): ?string
    {
        return __('cms.nav_group');
    }

    protected static string $view = 'filament.admin.pages.system-email-templates';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $firstKey = SystemEmailRenderer::keys()[0];

        $this->form->fill(['key' => $firstKey, 'html_mode' => true]);
        $this->loadKey($firstKey);
    }

    public function getTitle(): string|Htmlable
    {
        return __('system-emails.page.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('system-emails.page.navigation_label');
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Forms\Components\Grid::make(3)->schema([
                Forms\Components\Select::make('key')
                    ->label(__('system-emails.page.template'))
                    ->options(self::keyOptions())
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (mixed $state): void {
                        if (is_string($state) && array_key_exists($state, SystemEmailRenderer::KEYS)) {
                            $this->loadKey($state);
                        }
                    }),
                Forms\Components\Toggle::make('enabled')
                    ->label(__('system-emails.page.enabled'))
                    ->helperText(__('system-emails.page.enabled_help'))
                    ->inline(false)
                    ->default(true),
                Forms\Components\Placeholder::make('state')
                    ->label(__('system-emails.page.state'))
                    ->content(fn (Forms\Get $get): string => app(SystemEmailRenderer::class)
                        ->override($this->currentKey($get)) !== null
                            ? __('system-emails.page.state_customized')
                            : __('system-emails.page.state_default')),
            ]),
            Forms\Components\TextInput::make('subject')
                ->label(__('system-emails.page.subject'))
                ->required()
                ->maxLength(191),
            Forms\Components\Toggle::make('html_mode')
                ->label(__('lists.pages.html_mode'))
                ->live()
                ->inline(false),
            Forms\Components\RichEditor::make('body')
                ->label(__('system-emails.page.body'))
                ->required(fn (Forms\Get $get): bool => ! (bool) $get('html_mode'))
                ->visible(fn (Forms\Get $get): bool => ! (bool) $get('html_mode')),
            Forms\Components\Textarea::make('body')
                ->label(__('system-emails.page.body'))
                ->rows(12)
                ->required(fn (Forms\Get $get): bool => (bool) $get('html_mode'))
                ->extraAttributes(['class' => 'font-mono text-xs'])
                ->visible(fn (Forms\Get $get): bool => (bool) $get('html_mode')),
            // Per-key merge tag documentation (F-3.15-016).
            Forms\Components\Section::make(__('system-emails.page.merge_tags'))
                ->collapsed()
                ->schema([
                    Forms\Components\Placeholder::make('merge_tags_panel')
                        ->hiddenLabel()
                        ->content(fn (Forms\Get $get): HtmlString => new HtmlString($this->mergeTagsHtml($this->currentKey($get)))),
                ]),
        ]);
    }

    /** Load the effective content (override or default) into the form. */
    public function loadKey(string $key): void
    {
        $renderer = app(SystemEmailRenderer::class);
        $row = $renderer->override($key);

        $this->data = array_merge((array) $this->data, [
            'key' => $key,
            'subject' => $row?->subject ?? $renderer->defaultSubject($key),
            'body' => $row?->body ?? $renderer->defaultBody($key),
            'enabled' => $row?->enabled ?? true,
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $key = (string) $state['key'];

        app(SystemEmailRenderer::class)->saveOverride(
            $key,
            ($state['subject'] ?? null) ?: null,
            ($state['body'] ?? null) ?: null,
            (bool) ($state['enabled'] ?? true),
        );

        Notification::make()->title(__('system-emails.page.saved'))->success()->send();
    }

    /** Drop the override → back to the translatable default (F-3.15-016). */
    public function resetToDefault(): void
    {
        $key = (string) ($this->data['key'] ?? SystemEmailRenderer::keys()[0]);

        app(SystemEmailRenderer::class)->resetOverride($key);
        $this->loadKey($key);

        Notification::make()->title(__('system-emails.page.reset_done'))->success()->send();
    }

    /**
     * key => [label, customized] for the overview panel in the view.
     *
     * @return array<string, array{label: string, customized: bool}>
     */
    public function getOverviewProperty(): array
    {
        $renderer = app(SystemEmailRenderer::class);
        $rows = [];

        foreach (SystemEmailRenderer::keys() as $key) {
            $rows[$key] = [
                'label' => __('system-emails.labels.'.$key),
                'customized' => $renderer->override($key) !== null,
            ];
        }

        return $rows;
    }

    /** @return array<string, string> */
    public static function keyOptions(): array
    {
        $options = [];

        foreach (SystemEmailRenderer::keys() as $key) {
            $options[$key] = __('system-emails.labels.'.$key);
        }

        return $options;
    }

    private function currentKey(Forms\Get $get): string
    {
        $key = (string) $get('key');

        return array_key_exists($key, SystemEmailRenderer::KEYS) ? $key : SystemEmailRenderer::keys()[0];
    }

    private function mergeTagsHtml(string $key): string
    {
        return '<p class="text-sm">'.implode(' ', array_map(
            fn (string $tag): string => '<code class="mr-2 rounded bg-gray-100 px-1 py-0.5 text-xs dark:bg-gray-800">'.e($tag).'</code>',
            SystemEmailRenderer::tags($key),
        )).'</p>';
    }
}
