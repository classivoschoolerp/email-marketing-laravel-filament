<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\EmailListResource\Pages;

use App\Filament\Customer\Resources\EmailListResource;
use App\Models\EmailList;
use App\Models\ListForm;
use App\Services\Lists\FormHtmlGenerator;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Embed form builder for a list (spec §2.2.11): pick which visible_on_form
 * fields appear and their order, choose styled vs raw markup, set the
 * success/error redirect URLs, and copy the generated HTML / iframe / JS
 * snippets. The POST target is the public /forms/{uid}/subscribe endpoint.
 */
class ManageListForm extends Page implements HasForms
{
    use InteractsWithForms;
    use InteractsWithRecord;

    protected static string $resource = EmailListResource::class;

    protected static string $view = 'filament.customer.resources.email-list-resource.pages.manage-list-form';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canEdit($this->getRecordModel()), 403);

        $form = ListForm::forList($this->getRecordModel());

        $this->form->fill([
            'field_rows' => array_map(
                fn (int $id): array => ['field_id' => $id],
                array_map(intval(...), (array) $form->field_ids),
            ),
            'styled' => $form->styled,
            'ajax' => $form->ajax,
            'success_url' => $form->success_url,
            'error_url' => $form->error_url,
        ]);
    }

    public function getTitle(): string|Htmlable
    {
        return __('lists.embed.title', ['name' => $this->getRecordModel()->name]);
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
            Forms\Components\Section::make(__('lists.embed.fields_section'))
                ->description(__('lists.embed.fields_help'))
                ->schema([
                    // Field picker + order (F-2.2.11-002): reorderable rows.
                    Forms\Components\Repeater::make('field_rows')
                        ->label(__('lists.embed.fields'))
                        ->schema([
                            Forms\Components\Select::make('field_id')
                                ->label(__('lists.embed.field'))
                                ->options(fn (): array => $this->getRecordModel()->fields()
                                    ->where('visible_on_form', true)
                                    ->pluck('label', 'id')
                                    ->all())
                                ->required()
                                ->distinct(),
                        ])
                        ->reorderable()
                        ->addActionLabel(__('lists.embed.add_field'))
                        ->default([]),
                ]),
            Forms\Components\Section::make(__('lists.embed.options_section'))->schema([
                // Styled vs raw markup (F-2.2.11-004).
                Forms\Components\Toggle::make('styled')
                    ->label(__('lists.embed.styled'))
                    ->helperText(__('lists.embed.styled_help')),
                // AJAX submit option (F-2.2.11-006).
                Forms\Components\Toggle::make('ajax')
                    ->label(__('lists.embed.ajax'))
                    ->helperText(__('lists.embed.ajax_help')),
                // Success/error redirect URLs (F-2.2.11-005).
                Forms\Components\TextInput::make('success_url')
                    ->label(__('lists.embed.success_url'))
                    ->url()
                    ->maxLength(191),
                Forms\Components\TextInput::make('error_url')
                    ->label(__('lists.embed.error_url'))
                    ->url()
                    ->maxLength(191),
            ])->columns(2),
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $fieldIds = collect((array) ($state['field_rows'] ?? []))
            ->pluck('field_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $form = ListForm::forList($this->getRecordModel());
        $form->update([
            'field_ids' => $fieldIds,
            'styled' => (bool) ($state['styled'] ?? true),
            'ajax' => (bool) ($state['ajax'] ?? false),
            'success_url' => ($state['success_url'] ?? null) ?: null,
            'error_url' => ($state['error_url'] ?? null) ?: null,
        ]);

        Notification::make()->title(__('lists.embed.saved'))->success()->send();
    }

    /** The copy-paste HTML snippet (F-2.2.11-001/003). */
    public function getHtmlSnippetProperty(): string
    {
        return app(FormHtmlGenerator::class)->html(
            $this->getRecordModel(),
            ListForm::forList($this->getRecordModel()),
        );
    }

    public function getIframeSnippetProperty(): string
    {
        return app(FormHtmlGenerator::class)->iframe($this->getRecordModel());
    }

    public function getJsSnippetProperty(): string
    {
        return app(FormHtmlGenerator::class)->javascript($this->getRecordModel());
    }
}
