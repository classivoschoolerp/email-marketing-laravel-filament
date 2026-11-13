<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\EmailTemplateResource\Pages;

use App\Filament\Customer\Resources\EmailTemplateResource;
use App\Jobs\Templates\BulkTemplateActionJob;
use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Models\TemplateCategory;
use App\Services\Preferences\CustomerPreferences;
use App\Services\Templates\Builders\BuilderRegistry;
use App\Services\Templates\MergeTags;
use App\Services\Templates\TemplateCopier;
use App\Services\Templates\TemplateExporter;
use App\Services\Templates\TemplateQuota;
use App\Services\Templates\TemplateRevisions;
use App\Services\Templates\TemplateTestSender;
use App\Services\Templates\TemplateThumbnails;
use App\Services\Templates\TemplateUrlImporter;
use App\Services\Templates\TemplateZipImporter;
use App\Support\Templates\TemplateTags;
use Filament\Actions as PageActions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Templates index (spec §2.5.1): grid of thumbnail cards with a list toggle,
 * category / mine-vs-gallery filters, name search, row actions and bulk
 * delete / move-to-category.
 */
class ListEmailTemplates extends ListRecords
{
    protected static string $resource = EmailTemplateResource::class;

    private const LAYOUT_SESSION_KEY = 'templates.index.layout';

    public bool $isGridLayout = true;

    public function mount(): void
    {
        parent::mount();

        $this->isGridLayout = session(self::LAYOUT_SESSION_KEY, 'grid') === 'grid';
    }

    public function toggleLayout(): void
    {
        $this->isGridLayout = ! $this->isGridLayout;
        session([self::LAYOUT_SESSION_KEY => $this->isGridLayout ? 'grid' : 'list']);
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $table
            ->defaultSort('updated_at', 'desc')
            ->filters($this->tableFilters())
            ->actions($this->rowActions())
            ->bulkActions($this->bulkActions());

        if ($this->isGridLayout) {
            return $table
                ->contentGrid(['md' => 2, 'xl' => 3])
                ->columns([
                    Tables\Columns\Layout\Stack::make([
                        Tables\Columns\ViewColumn::make('thumbnail')
                            ->view('filament.customer.templates.thumbnail-card'),
                        Tables\Columns\TextColumn::make('name')
                            ->label(__('templates.fields.name'))
                            ->weight('bold')
                            ->searchable()
                            ->sortable(),
                        Tables\Columns\TextColumn::make('category.name')
                            ->label(__('templates.fields.category')),
                        $this->ownershipBadge(),
                        Tables\Columns\TextColumn::make('updated_at')
                            ->label(__('templates.fields.updated_at'))
                            ->since()
                            ->sortable(),
                    ])->space(2),
                ]);
        }

        return $table->columns([
            Tables\Columns\ViewColumn::make('thumbnail')
                ->label(__('templates.fields.thumbnail'))
                ->view('filament.customer.templates.thumbnail-row'),
            Tables\Columns\TextColumn::make('name')
                ->label(__('templates.fields.name'))
                ->searchable()
                ->sortable(),
            Tables\Columns\TextColumn::make('category.name')
                ->label(__('templates.fields.category'))
                ->sortable(),
            // F-2.5.1-002: the tags the tag filter matches on.
            Tables\Columns\TextColumn::make('tags')
                ->label(__('templates.fields.tags'))
                ->badge()
                ->state(fn (EmailTemplate $record): array => TemplateTags::normalize($record->tags))
                ->placeholder('—')
                ->toggleable(),
            Tables\Columns\TextColumn::make('builder')
                ->label(__('templates.fields.builder'))
                ->badge()
                ->formatStateUsing(fn (EmailTemplate $record): string => EmailTemplateResource::builderCase($record)->label()),
            $this->ownershipBadge(),
            Tables\Columns\IconColumn::make('is_default')
                ->label(__('templates.fields.is_default'))
                ->boolean(),
            Tables\Columns\TextColumn::make('updated_at')
                ->label(__('templates.fields.updated_at'))
                ->dateTime()
                ->sortable(),
        ]);
    }

    private function ownershipBadge(): Tables\Columns\TextColumn
    {
        return Tables\Columns\TextColumn::make('ownership')
            ->label(__('templates.fields.ownership'))
            ->badge()
            ->state(fn (EmailTemplate $record): string => $record->isSystem()
                ? __('templates.ownership.gallery')
                : __('templates.ownership.mine'))
            ->color(fn (EmailTemplate $record): string => $record->isSystem() ? 'info' : 'success');
    }

    /** @return array<int, Tables\Filters\BaseFilter> */
    private function tableFilters(): array
    {
        return [
            Tables\Filters\SelectFilter::make('category_id')
                ->label(__('templates.filters.category'))
                ->options(fn (): array => TemplateCategory::query()
                    ->browsable()
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all()),
            // F-2.5.1-002 tag filter (D-34 resolved in WP-27B). Options come
            // from the browsable() scope only, so a tenant never sees — let
            // alone filters by — another tenant's tag vocabulary.
            Tables\Filters\SelectFilter::make('tag')
                ->label(__('templates.filters.tag'))
                ->searchable()
                ->options(fn (): array => TemplateTags::options())
                ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                    ? TemplateTags::scopeTagged($query, (string) $data['value'])
                    : $query),
            Tables\Filters\SelectFilter::make('ownership')
                ->label(__('templates.filters.ownership'))
                ->options([
                    'mine' => __('templates.ownership.mine'),
                    'gallery' => __('templates.ownership.gallery'),
                ])
                ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                    'mine' => $query->whereNotNull('customer_id'),
                    'gallery' => $query->whereNull('customer_id'),
                    default => $query,
                }),
        ];
    }

    /** @return array<int, Tables\Actions\Action|Tables\Actions\ActionGroup> */
    private function rowActions(): array
    {
        return [
            Tables\Actions\Action::make('edit')
                ->label(__('templates.actions.edit'))
                ->icon('heroicon-m-pencil-square')
                ->visible(fn (EmailTemplate $record): bool => auth('customer')->user()->can('update', $record))
                ->url(fn (EmailTemplate $record): string => app(BuilderRegistry::class)
                    ->get(EmailTemplateResource::builderCase($record))
                    ->editUrl($record)),

            Tables\Actions\Action::make('use_template')
                ->label(__('templates.actions.use_template'))
                ->icon('heroicon-m-arrow-down-on-square')
                ->visible(fn (EmailTemplate $record): bool => $record->isSystem())
                ->action(function (EmailTemplate $record): void {
                    try {
                        $copy = app(TemplateCopier::class)
                            ->copyToCustomer($record, auth('customer')->user());
                    } catch (ValidationException $e) {
                        Notification::make()->danger()
                            ->title(collect($e->errors())->flatten()->first())
                            ->send();

                        return;
                    }

                    Notification::make()->success()
                        ->title(__('templates.notifications.copied', ['name' => $copy->name]))
                        ->send();
                }),

            Tables\Actions\Action::make('preview')
                ->label(__('templates.actions.preview'))
                ->icon('heroicon-m-eye')
                ->modalHeading(fn (EmailTemplate $record): string => $record->name)
                ->modalContent(fn (EmailTemplate $record) => view('filament.customer.templates.preview', [
                    'html' => app(MergeTags::class)->renderSample($record->content_html),
                ]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('templates.actions.close')),

            Tables\Actions\ActionGroup::make([
                Tables\Actions\Action::make('duplicate')
                    ->label(__('templates.actions.duplicate'))
                    ->icon('heroicon-m-document-duplicate')
                    ->visible(fn (EmailTemplate $record): bool => ! $record->isSystem())
                    ->action(function (EmailTemplate $record): void {
                        try {
                            $copy = app(TemplateCopier::class)->duplicate($record);
                        } catch (ValidationException $e) {
                            Notification::make()->danger()
                                ->title(collect($e->errors())->flatten()->first())
                                ->send();

                            return;
                        }

                        Notification::make()->success()
                            ->title(__('templates.notifications.duplicated', ['name' => $copy->name]))
                            ->send();
                    }),

                Tables\Actions\Action::make('send_test')
                    ->label(__('templates.actions.send_test'))
                    ->icon('heroicon-m-paper-airplane')
                    ->form([
                        Forms\Components\TagsInput::make('recipients')
                            ->label(__('templates.fields.recipients'))
                            ->helperText(__('templates.help.recipients'))
                            ->placeholder('name@example.com')
                            ->required()
                            ->nestedRecursiveRules(['email'])
                            ->rules(['max:'.(int) config('emp-templates.send_test_max_recipients', 10)]),
                    ])
                    ->action(function (EmailTemplate $record, array $data): void {
                        app(TemplateTestSender::class)->send($record, array_values($data['recipients']));

                        Notification::make()->success()
                            ->title(__('templates.notifications.test_queued'))
                            ->send();
                    }),

                Tables\Actions\Action::make('set_default')
                    ->label(__('templates.actions.set_default'))
                    ->icon('heroicon-m-star')
                    ->visible(fn (EmailTemplate $record): bool => ! $record->isSystem() && ! $record->is_default)
                    ->action(function (EmailTemplate $record): void {
                        EmailTemplate::query()->where('is_default', true)->update(['is_default' => false]);
                        $record->forceFill(['is_default' => true])->save();

                        Notification::make()->success()
                            ->title(__('templates.notifications.default_set', ['name' => $record->name]))
                            ->send();
                    }),

                Tables\Actions\Action::make('export_html')
                    ->label(__('templates.actions.export_html'))
                    ->icon('heroicon-m-arrow-down-tray')
                    ->action(function (EmailTemplate $record): StreamedResponse {
                        $exporter = app(TemplateExporter::class);

                        return response()->streamDownload(
                            fn () => print $exporter->html($record),
                            $exporter->fileName($record, 'html'),
                            ['Content-Type' => 'text/html'],
                        );
                    }),

                Tables\Actions\Action::make('export_zip')
                    ->label(__('templates.actions.export_zip'))
                    ->icon('heroicon-m-archive-box-arrow-down')
                    ->action(function (EmailTemplate $record) {
                        $exporter = app(TemplateExporter::class);

                        return response()
                            ->download($exporter->zip($record), $exporter->fileName($record, 'zip'))
                            ->deleteFileAfterSend();
                    }),

                Tables\Actions\DeleteAction::make()
                    ->label(__('templates.actions.delete')),
            ])->label(__('templates.actions.more'))->icon('heroicon-m-ellipsis-vertical'),
        ];
    }

    /** @return array<int, Tables\Actions\BulkAction|Tables\Actions\BulkActionGroup> */
    private function bulkActions(): array
    {
        return [
            Tables\Actions\DeleteBulkAction::make()
                ->label(__('templates.actions.bulk_delete'))
                // Gallery/system rows are read-only for customers (§3.14):
                // policies do not run per record in bulk actions (D-28), so
                // system rows are filtered out of the selection explicitly —
                // then the work is QUEUED (F-Phase-3-Differentiat-006).
                ->using(fn (Collection $records) => $this->queueBulk(
                    BulkTemplateActionJob::ACTION_DELETE,
                    $records->reject(fn (EmailTemplate $record): bool => $record->isSystem()),
                )),

            Tables\Actions\BulkAction::make('move_to_category')
                ->label(__('templates.actions.move_to_category'))
                ->icon('heroicon-m-folder-arrow-down')
                ->form([
                    Forms\Components\Select::make('category_id')
                        ->label(__('templates.fields.category'))
                        ->options(fn (): array => TemplateCategory::query()
                            ->orderBy('sort_order')
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->required(),
                ])
                ->action(fn (Collection $records, array $data) => $this->queueBulk(
                    BulkTemplateActionJob::ACTION_MOVE_CATEGORY,
                    $records->reject(fn (EmailTemplate $record): bool => $record->isSystem()),
                    (int) $data['category_id'],
                ))
                ->deselectRecordsAfterCompletion(),
        ];
    }

    /**
     * Dispatch one queued job for an already-authorized selection
     * (F-Phase-3-Differentiat-006 — bulk actions are never synchronous).
     *
     * @param  Collection<int, EmailTemplate>  $records
     */
    private function queueBulk(string $action, Collection $records, ?int $categoryId = null): void
    {
        $ids = $records->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();

        if ($ids !== []) {
            BulkTemplateActionJob::dispatch(
                $action,
                $ids,
                (int) auth('customer')->id(),
                (string) Str::uuid(),
                $categoryId,
            );
        }

        Notification::make()->success()
            ->title(__('templates.notifications.bulk_queued', ['count' => count($ids)]))
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            PageActions\Action::make('toggle_layout')
                ->label(fn (): string => $this->isGridLayout
                    ? __('templates.actions.layout_list')
                    : __('templates.actions.layout_grid'))
                ->icon(fn (): string => $this->isGridLayout ? 'heroicon-m-list-bullet' : 'heroicon-m-squares-2x2')
                ->action(fn () => $this->toggleLayout()),

            PageActions\Action::make('builder_preference')
                ->label(__('templates.actions.builder_preference'))
                ->icon('heroicon-m-cog-6-tooth')
                ->form([
                    Forms\Components\Select::make('builder')
                        ->label(__('templates.fields.default_builder'))
                        ->helperText(__('templates.help.default_builder'))
                        ->options(fn (): array => app(BuilderRegistry::class)->options())
                        ->default(fn (): string => app(BuilderRegistry::class)
                            ->defaultFor(auth('customer')->user())->value)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    app(CustomerPreferences::class)->set(
                        auth('customer')->user(),
                        BuilderRegistry::CUSTOMER_PREFERENCE_KEY,
                        (string) $data['builder'],
                    );

                    Notification::make()->success()
                        ->title(__('templates.notifications.preference_saved'))
                        ->send();
                }),

            PageActions\Action::make('import_zip')
                ->label(__('templates.actions.import_zip'))
                ->icon('heroicon-m-arrow-up-tray')
                ->form([
                    Forms\Components\TextInput::make('name')
                        ->label(__('templates.fields.name'))
                        ->required()
                        ->maxLength(255),
                    Forms\Components\FileUpload::make('file')
                        ->label(__('templates.fields.zip_file'))
                        ->disk('local')
                        ->directory('tmp/template-imports')
                        ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed'])
                        ->maxSize((int) config('emp-templates.zip.max_size_mb', 20) * 1024)
                        ->required(),
                ])
                ->action(fn (array $data) => $this->importFromZip($data)),

            PageActions\Action::make('import_url')
                ->label(__('templates.actions.import_url'))
                ->icon('heroicon-m-globe-alt')
                ->form([
                    Forms\Components\TextInput::make('name')
                        ->label(__('templates.fields.name'))
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('url')
                        ->label(__('templates.fields.source_url'))
                        ->url()
                        ->required()
                        ->maxLength(2048),
                ])
                ->action(fn (array $data) => $this->importFromUrl($data)),

            PageActions\CreateAction::make()
                ->label(__('templates.actions.create')),
        ];
    }

    /** @param array{name: string, file: string} $data */
    private function importFromZip(array $data): void
    {
        /** @var Customer $customer */
        $customer = auth('customer')->user();

        try {
            app(TemplateQuota::class)->assertCanCreate($customer);

            $template = new EmailTemplate;
            $template->forceFill([
                'customer_id' => $customer->id,
                'name' => $data['name'],
                'builder' => 'html',
            ])->save();

            app(TemplateZipImporter::class)->import(
                $template,
                Storage::disk('local')->path($data['file']),
            );

            app(TemplateRevisions::class)->record($template);
            app(TemplateThumbnails::class)->refreshFromContent($template);
        } catch (ValidationException $e) {
            Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();

            return;
        } finally {
            Storage::disk('local')->delete($data['file'] ?? '');
        }

        Notification::make()->success()
            ->title(__('templates.notifications.imported', ['name' => $data['name']]))
            ->send();
    }

    /** @param array{name: string, url: string} $data */
    private function importFromUrl(array $data): void
    {
        /** @var Customer $customer */
        $customer = auth('customer')->user();

        try {
            app(TemplateQuota::class)->assertCanCreate($customer);

            $template = new EmailTemplate;
            $template->forceFill([
                'customer_id' => $customer->id,
                'name' => $data['name'],
                'builder' => 'html',
            ])->save();

            app(TemplateUrlImporter::class)->import($template, $data['url']);

            app(TemplateRevisions::class)->record($template);
            app(TemplateThumbnails::class)->refreshFromContent($template);
        } catch (ValidationException $e) {
            Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();

            return;
        }

        Notification::make()->success()
            ->title(__('templates.notifications.imported', ['name' => $data['name']]))
            ->send();
    }
}
