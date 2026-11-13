<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\EmailListResource\Pages;

use App\Enums\ImportSource;
use App\Enums\SubscriberStatus;
use App\Filament\Customer\Resources\EmailListResource;
use App\Jobs\Imports\RunImportJob;
use App\Models\EmailList;
use App\Models\ImportJob;
use App\Services\Imports\CsvAnalyzer;
use App\Services\Imports\Esp\EspImporterRegistry;
use App\Services\Imports\ImportFileValidator;
use App\Services\Settings\SettingsReader;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Import subscribers into a list (spec §2.2.7): CSV upload with
 * delimiter/enclosure auto-detection, header toggle and a column → field
 * mapping UI (tag-name heuristic + manual override); paste-text; import
 * from URL; from another list; from an external SQL database. The heavy
 * lifting always runs on the imports queue (RunImportJob) — this page only
 * validates, stores the source and creates the import_jobs row.
 */
class ImportSubscribers extends Page implements HasForms
{
    use InteractsWithForms;
    use InteractsWithRecord;

    protected static string $resource = EmailListResource::class;

    protected static string $view = 'filament.customer.resources.email-list-resource.pages.import-subscribers';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canEdit($this->getRecordModel()), 403);

        $this->form->fill([
            'source' => ImportSource::Csv->value,
            'has_header' => true,
            'auto_create_fields' => true,
            'imported_status' => SubscriberStatus::Unconfirmed->value,
            'duplicate_strategy' => (string) ($this->getRecordModel()->import_duplicate_handling ?? 'update'),
            'database' => ['port' => 3306],
        ]);
    }

    public function getTitle(): string|Htmlable
    {
        return __('imports.page.title', ['name' => $this->getRecordModel()->name]);
    }

    public function getRecordModel(): EmailList
    {
        /** @var EmailList $record */
        $record = $this->record;

        return $record;
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('history')
                ->label(__('imports.page.history'))
                ->icon('heroicon-m-clock')
                ->url(fn (): string => EmailListResource::getUrl('imports', ['record' => $this->getRecordModel()])),

        ];
    }

    public function form(Form $form): Form
    {
        $settings = SettingsReader::importExport();

        return $form->statePath('data')->schema([
            Forms\Components\Section::make(__('imports.form.source_section'))->schema([
                Forms\Components\Radio::make('source')
                    ->label(__('imports.form.source'))
                    ->options($this->sourceOptions())
                    ->required()
                    ->live(),

                // (a) CSV upload — F-2.2.7-001; limits from §3.25.2 (F-2.2.7-015).
                Forms\Components\FileUpload::make('file')
                    ->label(__('imports.form.file'))
                    ->helperText(__('imports.form.file_help', [
                        'extensions' => implode(', ', $settings->allowedImportExtensions()),
                        'max' => $settings->maxImportFileSizeMb(),
                    ]))
                    ->disk('local')
                    ->directory(fn (): string => 'customers/'.$this->getRecordModel()->customer()->first()?->uid.'/imports')
                    ->storeFileNamesIn('original_filename')
                    ->maxSize($settings->maxImportFileSizeMb() * 1024)
                    ->visible(fn (Forms\Get $get): bool => $get('source') === ImportSource::Csv->value)
                    ->required(fn (Forms\Get $get): bool => $get('source') === ImportSource::Csv->value)
                    ->live()
                    ->afterStateUpdated(fn (Forms\Set $set, mixed $state) => $this->analyzeUpload($set, $state)),

                // (b) Paste text — F-2.2.7-002.
                Forms\Components\Textarea::make('paste_text')
                    ->label(__('imports.form.paste_text'))
                    ->helperText(__('imports.form.paste_help'))
                    ->rows(6)
                    ->visible(fn (Forms\Get $get): bool => $get('source') === ImportSource::Paste->value)
                    ->required(fn (Forms\Get $get): bool => $get('source') === ImportSource::Paste->value),

                // (c) Import from URL — F-2.2.7-003 (gated by §3.25.2).
                Forms\Components\TextInput::make('url')
                    ->label(__('imports.form.url'))
                    ->helperText(__('imports.form.url_help'))
                    ->url()
                    ->visible(fn (Forms\Get $get): bool => $get('source') === ImportSource::Url->value)
                    ->required(fn (Forms\Get $get): bool => $get('source') === ImportSource::Url->value),

                // (d) From another list — F-2.2.7-004.
                Forms\Components\Select::make('source_list_id')
                    ->label(__('imports.form.source_list'))
                    ->options(fn (): array => EmailList::query()
                        ->whereKeyNot($this->getRecordModel()->id)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->visible(fn (Forms\Get $get): bool => $get('source') === ImportSource::List->value)
                    ->required(fn (Forms\Get $get): bool => $get('source') === ImportSource::List->value),

                // (e) From a SQL database — F-2.2.7-005 (gated by §3.25.2).
                Forms\Components\Fieldset::make(__('imports.form.database_section'))
                    ->visible(fn (Forms\Get $get): bool => $get('source') === ImportSource::Database->value)
                    ->schema([
                        Forms\Components\TextInput::make('database.host')
                            ->label(__('imports.form.db_host'))
                            ->required(fn (Forms\Get $get): bool => $get('source') === ImportSource::Database->value),
                        Forms\Components\TextInput::make('database.port')
                            ->label(__('imports.form.db_port'))
                            ->numeric()
                            ->default(3306),
                        Forms\Components\TextInput::make('database.database')
                            ->label(__('imports.form.db_name'))
                            ->required(fn (Forms\Get $get): bool => $get('source') === ImportSource::Database->value),
                        Forms\Components\TextInput::make('database.username')
                            ->label(__('imports.form.db_user'))
                            ->required(fn (Forms\Get $get): bool => $get('source') === ImportSource::Database->value),
                        Forms\Components\TextInput::make('database.password')
                            ->label(__('imports.form.db_password'))
                            ->password()
                            ->revealable(),
                        Forms\Components\TextInput::make('database.table')
                            ->label(__('imports.form.db_table'))
                            ->helperText(__('imports.form.db_table_help')),
                        Forms\Components\Textarea::make('database.query')
                            ->label(__('imports.form.db_query'))
                            ->helperText(__('imports.form.db_query_help'))
                            ->rows(2)
                            ->columnSpanFull(),
                    ])->columns(2),

                // (f) From another ESP — F-2.2.7-006. The vendor picker is
                // driven by EspImporterRegistry, so a plugin's connector
                // appears here without touching this page.
                Forms\Components\Fieldset::make(__('imports.form.esp_section'))
                    ->visible(fn (Forms\Get $get): bool => $get('source') === ImportSource::Esp->value)
                    ->schema([
                        Forms\Components\Select::make('esp.driver')
                            ->label(__('imports.form.esp_driver'))
                            ->options(fn (): array => app(EspImporterRegistry::class)->options())
                            ->live()
                            ->required(fn (Forms\Get $get): bool => $get('source') === ImportSource::Esp->value),

                        Forms\Components\Placeholder::make('esp_help')
                            ->hiddenLabel()
                            ->content(__('imports.form.esp_help'))
                            ->columnSpanFull(),

                        ...$this->espCredentialFields(),
                    ])->columns(2),
            ]),

            // Column → field mapping (F-2.2.7-001), populated by analyzeUpload().
            Forms\Components\Section::make(__('imports.form.mapping_section'))
                ->description(__('imports.form.mapping_help'))
                ->visible(fn (Forms\Get $get): bool => $get('source') === ImportSource::Csv->value
                    && (array) ($this->data['columns_preview'] ?? []) !== [])
                ->schema(fn (): array => $this->mappingSchema()),

            Forms\Components\Section::make(__('imports.form.options_section'))->schema([
                Forms\Components\Toggle::make('has_header')
                    ->label(__('imports.form.has_header'))
                    ->visible(fn (Forms\Get $get): bool => $get('source') === ImportSource::Csv->value),
                Forms\Components\Select::make('delimiter')
                    ->label(__('imports.form.delimiter'))
                    ->options([
                        ',' => __('imports.form.delimiter_comma'),
                        ';' => __('imports.form.delimiter_semicolon'),
                        "\t" => __('imports.form.delimiter_tab'),
                        '|' => __('imports.form.delimiter_pipe'),
                    ])
                    ->placeholder(__('imports.form.auto_detected'))
                    ->visible(fn (Forms\Get $get): bool => $get('source') === ImportSource::Csv->value),
                Forms\Components\Toggle::make('auto_create_fields')
                    ->label(__('imports.form.auto_create_fields'))
                    ->helperText(__('imports.form.auto_create_fields_help')),
                Forms\Components\Select::make('imported_status')
                    ->label(__('imports.form.imported_status'))
                    ->options([
                        SubscriberStatus::Confirmed->value => __('subscribers.status.confirmed'),
                        SubscriberStatus::Unconfirmed->value => __('subscribers.status.unconfirmed'),
                    ])
                    ->required(),
                Forms\Components\Select::make('duplicate_strategy')
                    ->label(__('imports.form.duplicate_strategy'))
                    ->options([
                        'update' => __('imports.form.duplicates_update'),
                        'skip' => __('imports.form.duplicates_skip'),
                        'error' => __('imports.form.duplicates_error'),
                    ])
                    ->required(),

                // F-2.2.7-014 — syntax + MX validation during the run, using
                // the built-in verification engine (D-12).
                Forms\Components\Toggle::make('validate_emails')
                    ->label(__('imports.form.validate_emails'))
                    ->helperText(__('imports.form.validate_emails_help'))
                    ->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    /**
     * One input per credential key of every registered ESP adapter
     * (F-2.2.7-006), each visible only while its vendor is selected. Built
     * from the registry so a plugin connector needs no change here.
     *
     * @return array<int, Forms\Components\TextInput>
     */
    private function espCredentialFields(): array
    {
        $fields = [];

        foreach (app(EspImporterRegistry::class)->keys() as $driver) {
            foreach (app(EspImporterRegistry::class)->for($driver)->credentialKeys() as $key) {
                $help = __('imports.form.esp_'.$driver.'_'.$key.'_help');

                $fields[] = Forms\Components\TextInput::make('esp.credentials.'.$driver.'.'.$key)
                    ->label(__('imports.form.esp_'.$key))
                    ->helperText($help === 'imports.form.esp_'.$driver.'_'.$key.'_help' ? null : $help)
                    ->password(str_contains($key, 'key') || str_contains($key, 'token'))
                    ->revealable(str_contains($key, 'key') || str_contains($key, 'token'))
                    ->visible(fn (Forms\Get $get): bool => $get('esp.driver') === $driver)
                    ->required(fn (Forms\Get $get): bool => $get('esp.driver') === $driver);
            }
        }

        return $fields;
    }

    /**
     * Reads the temporary upload, auto-detects delimiter/enclosure and the
     * header row, and pre-fills the mapping with the tag-name heuristic.
     */
    private function analyzeUpload(Forms\Set $set, mixed $state): void
    {
        $file = is_array($state) ? reset($state) : $state;

        if (! $file instanceof TemporaryUploadedFile) {
            $this->data['columns_preview'] = [];

            return;
        }

        $analyzer = app(CsvAnalyzer::class);

        $handle = fopen($file->getRealPath(), 'rb');
        $sample = $handle === false ? '' : (string) stream_get_contents($handle, 65536);

        if ($handle !== false) {
            fclose($handle);
        }

        $delimiter = $analyzer->detectDelimiter($sample);
        $enclosure = $analyzer->detectEnclosure($sample, $delimiter);
        $rows = $analyzer->sampleRows($sample, $delimiter, $enclosure, 4);
        $firstRow = $rows[0] ?? [];
        $hasHeader = $analyzer->looksLikeHeader($firstRow);

        $headers = $hasHeader
            ? array_map(fn ($cell): string => (string) $cell, $firstRow)
            : array_map(fn (int $i): string => __('imports.form.column_n', ['n' => $i + 1]), array_keys($firstRow));

        $set('delimiter', $delimiter);
        $set('enclosure', $enclosure);
        $set('has_header', $hasHeader);

        $this->data['columns_preview'] = [
            'headers' => $headers,
            'samples' => array_slice($rows, $hasHeader ? 1 : 0, 2),
        ];

        $suggested = $analyzer->suggestMapping($headers, $this->getRecordModel());

        foreach ($suggested as $index => $target) {
            $set('mapping.'.$index, $target);
        }
    }

    /** One target select per detected column. @return array<int, mixed> */
    private function mappingSchema(): array
    {
        $preview = (array) ($this->data['columns_preview'] ?? []);
        $headers = (array) ($preview['headers'] ?? []);
        $samples = (array) ($preview['samples'] ?? []);

        $fieldOptions = [];

        foreach ($this->getRecordModel()->fields()->get() as $field) {
            $fieldOptions[CsvAnalyzer::TARGET_FIELD_PREFIX.$field->tag] = $field->label.' ('.$field->tag.')';
        }

        $components = [];

        foreach ($headers as $index => $header) {
            $sampleValues = collect($samples)
                ->map(fn (array $row): string => (string) ($row[$index] ?? ''))
                ->filter()
                ->implode(', ');

            // WP-37 F-2.15.8-018: show what the AI thinks this column IS, so
            // the person importing can judge the suggestion before taking it.
            $hint = $sampleValues === '' ? null : __('imports.form.sample', ['values' => $sampleValues]);
            $detected = (string) (($this->data['ai_types'][$index] ?? '') ?: '');

            if ($detected !== '') {
                $hint = trim((string) $hint.' '.__('ai.mapping.detected_type', [
                    'type' => (string) __('lists.field_type.'.$detected),
                ]));
            }

            $components[] = Forms\Components\Select::make('mapping.'.$index)
                ->label((string) $header)
                ->helperText($hint)
                ->options(array_merge(
                    [
                        CsvAnalyzer::TARGET_EMAIL => __('imports.form.map_email'),
                        CsvAnalyzer::TARGET_SKIP => __('imports.form.map_skip'),
                    ],
                    $fieldOptions,
                    [CsvAnalyzer::TARGET_NEW_PREFIX.$header => __('imports.form.map_new', ['name' => $header])],
                ))
                ->required();
        }

        return $components;
    }

    /** Validates the form and queues the import run (docs/ARCHITECTURE.md rule 5). */
    public function startImport(): void
    {
        $state = $this->form->getState();
        $list = $this->getRecordModel();
        $source = ImportSource::from((string) $state['source']);

        $options = [
            'auto_create_fields' => (bool) ($state['auto_create_fields'] ?? false),
            'imported_status' => (string) $state['imported_status'],
            'duplicate_strategy' => (string) $state['duplicate_strategy'],
            // F-2.2.7-014
            'validate_emails' => (bool) ($state['validate_emails'] ?? false),
        ];

        $filePath = null;

        switch ($source) {
            case ImportSource::Csv:
                $filePath = is_array($state['file']) ? (string) reset($state['file']) : (string) $state['file'];
                $originalName = is_array($state['original_filename'] ?? null)
                    ? (string) reset($state['original_filename'])
                    : (string) ($state['original_filename'] ?? basename($filePath));

                // Enforce §3.25.2 size + extension server-side (F-2.2.7-015).
                $errors = app(ImportFileValidator::class)->validate(
                    $originalName,
                    (int) Storage::disk('local')->size($filePath),
                );

                if ($errors !== []) {
                    Storage::disk('local')->delete($filePath);
                    $this->addError('data.file', implode(' ', $errors));

                    return;
                }

                $mapping = array_map(strval(...), (array) ($state['mapping'] ?? []));

                if ($mapping !== [] && ! in_array(CsvAnalyzer::TARGET_EMAIL, $mapping, true)) {
                    $this->addError('data.file', __('imports.errors.no_email_column'));

                    return;
                }

                $options += [
                    'original_filename' => $originalName,
                    'has_header' => (bool) ($state['has_header'] ?? true),
                    'delimiter' => ($state['delimiter'] ?? null) ?: null,
                    'enclosure' => ($state['enclosure'] ?? null) ?: null,
                    'mapping' => $mapping === [] ? null : $mapping,
                ];
                $options = array_filter($options, fn (mixed $value): bool => $value !== null);
                break;

            case ImportSource::Paste:
                $filePath = $this->storePastedText($list, (string) $state['paste_text']);
                $options += [
                    'has_header' => false,
                    'delimiter' => ',',
                    'mapping' => [0 => CsvAnalyzer::TARGET_EMAIL],
                ];
                break;

            case ImportSource::Url:
                if (! SettingsReader::importExport()->importFromUrlEnabled()) {
                    $this->addError('data.url', __('imports.errors.url_disabled'));

                    return;
                }

                $options['url'] = (string) $state['url'];
                break;

            case ImportSource::List:
                $options['source_list_id'] = (int) $state['source_list_id'];
                break;

            case ImportSource::Database:
                if (! SettingsReader::importExport()->importFromDatabaseEnabled()) {
                    $this->addError('data.source', __('imports.errors.database_disabled'));

                    return;
                }

                $database = (array) ($state['database'] ?? []);

                $options['database'] = [
                    'driver' => 'mysql',
                    'host' => (string) ($database['host'] ?? ''),
                    'port' => (int) ($database['port'] ?? 3306),
                    'database' => (string) ($database['database'] ?? ''),
                    'username' => (string) ($database['username'] ?? ''),
                    'password_encrypted' => Crypt::encryptString((string) ($database['password'] ?? '')),
                    'table' => (string) ($database['table'] ?? ''),
                    'query' => (string) ($database['query'] ?? ''),
                ];
                break;

                // F-2.2.7-006: vendor credentials never touch the database in
                // clear text — the whole bag is encrypted into options, and the
                // queued EspReader decrypts it when it pages the API.
            case ImportSource::Esp:
                $esp = (array) ($state['esp'] ?? []);
                $driver = (string) ($esp['driver'] ?? '');

                if (! app(EspImporterRegistry::class)->has($driver)) {
                    $this->addError('data.esp.driver', __('imports.errors.esp_unknown', ['driver' => $driver]));

                    return;
                }

                $credentials = array_map(
                    strval(...),
                    (array) (((array) ($esp['credentials'] ?? []))[$driver] ?? []),
                );

                $options['esp'] = [
                    'driver' => $driver,
                    'credentials_encrypted' => Crypt::encryptString((string) json_encode($credentials)),
                ];
                break;
        }

        $import = ImportJob::create([
            'customer_id' => $list->customer_id,
            'list_id' => $list->id,
            'source' => $source,
            'status' => ImportJob::STATUS_PENDING,
            'options' => $options,
            'file_path' => $filePath,
        ]);

        RunImportJob::dispatch((int) $import->id);

        Notification::make()
            ->title(__('imports.page.queued'))
            ->success()
            ->send();

        $this->redirect(EmailListResource::getUrl('imports', ['record' => $list]));
    }

    /**
     * Pasted text (comma/newline separated emails, F-2.2.7-002) becomes a
     * one-column CSV file on the private disk so the queued pipeline treats
     * it exactly like an upload.
     */
    private function storePastedText(EmailList $list, string $text): string
    {
        $entries = array_values(array_filter(array_map(
            'trim',
            preg_split('/[\s,;]+/', $text) ?: [],
        ), fn (string $entry): bool => $entry !== ''));

        $customerUid = (string) $list->customer()->first()?->uid;
        $path = 'customers/'.$customerUid.'/imports/paste-'.now()->format('YmdHis').'-'.str()->random(6).'.csv';

        Storage::disk('local')->put($path, implode("\n", $entries));

        return $path;
    }

    private function sourceOptions(): array
    {
        $settings = SettingsReader::importExport();

        $options = [
            ImportSource::Csv->value => __('imports.source.csv'),
            ImportSource::Paste->value => __('imports.source.paste'),
        ];

        if ($settings->importFromUrlEnabled()) {
            $options[ImportSource::Url->value] = __('imports.source.url');
        }

        $options[ImportSource::List->value] = __('imports.source.list');

        if ($settings->importFromDatabaseEnabled()) {
            $options[ImportSource::Database->value] = __('imports.source.database');
        }

        // F-2.2.7-006 — only offered when at least one connector is registered.
        if (app(EspImporterRegistry::class)->keys() !== []) {
            $options[ImportSource::Esp->value] = __('imports.source.esp');
        }

        return $options;
    }
}
