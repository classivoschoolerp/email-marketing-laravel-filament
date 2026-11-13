<?php

declare(strict_types=1);

namespace App\Services\Imports\Readers;

use App\Enums\ImportSource;
use App\Enums\ListFieldType;
use App\Models\EmailList;
use App\Models\ImportJob;
use App\Models\ListField;
use App\Services\Imports\CsvAnalyzer;
use App\Services\Imports\ImportFailedException;
use App\Services\Imports\ImportFileValidator;
use App\Services\Settings\SettingsReader;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;
use League\Csv\Statement;

/**
 * Rows from a CSV file on the private disk — backs the csv upload, pasted
 * text (converted to a file at submit) and import-from-URL sources
 * (F-2.2.7-001/002/003). prepare() downloads URL sources (toggle-gated by
 * §3.25.2 settings and re-validated against the file limits), finalizes
 * delimiter/enclosure detection and materializes "new:" mapping targets as
 * Text custom fields (F-2.2.7-007).
 */
class CsvFileReader implements ImportReader
{
    public function __construct(
        private readonly CsvAnalyzer $analyzer,
        private readonly ImportFileValidator $validator,
    ) {}

    public function prepare(ImportJob $job): void
    {
        if ($job->source === ImportSource::Url && $job->file_path === null) {
            $this->download($job);
        }

        if ($job->file_path === null || ! Storage::disk('local')->exists($job->file_path)) {
            throw new ImportFailedException(__('imports.errors.file_missing'));
        }

        $options = (array) $job->options;
        $content = $this->sample($job);

        $options['delimiter'] ??= $this->analyzer->detectDelimiter($content);
        $options['enclosure'] ??= $this->analyzer->detectEnclosure($content, (string) $options['delimiter']);

        $rows = $this->analyzer->sampleRows($content, (string) $options['delimiter'], (string) $options['enclosure'], 1);
        $firstRow = $rows[0] ?? [];

        $options['has_header'] ??= $this->analyzer->looksLikeHeader($firstRow);

        // URL imports have no interactive mapping step: derive it here from
        // the headers with the same tag-name heuristic the UI uses.
        if (! isset($options['mapping'])) {
            /** @var EmailList $list */
            $list = EmailList::withoutTenancy()->findOrFail($job->list_id);
            $headers = $options['has_header'] ? $firstRow : array_map(fn (int $i): string => 'column_'.($i + 1), array_keys($firstRow));
            $options['mapping'] = $this->analyzer->suggestMapping($headers, $list);

            if (! ($options['auto_create_fields'] ?? false)) {
                $options['mapping'] = array_map(
                    fn (string $target): string => str_starts_with($target, CsvAnalyzer::TARGET_NEW_PREFIX)
                        ? CsvAnalyzer::TARGET_SKIP
                        : $target,
                    $options['mapping'],
                );
            }
        }

        $options['mapping'] = $this->materializeNewFields($job, $options['mapping']);

        if (! in_array(CsvAnalyzer::TARGET_EMAIL, $options['mapping'], true)) {
            throw new ImportFailedException(__('imports.errors.no_email_column'));
        }

        $job->options = $options;
        $job->save();
    }

    public function totalRows(ImportJob $job): int
    {
        $options = (array) $job->options;
        $count = 0;

        foreach ($this->reader($job)->getRecords() as $record) {
            $count++;
        }

        return max(0, $count - (($options['has_header'] ?? false) ? 1 : 0));
    }

    public function rows(ImportJob $job, int $offset): \Generator
    {
        $options = (array) $job->options;
        /** @var array<int, string> $mapping */
        $mapping = array_map(strval(...), (array) ($options['mapping'] ?? []));
        $headerRows = ($options['has_header'] ?? false) ? 1 : 0;

        /** @var EmailList $list */
        $list = EmailList::withoutTenancy()->findOrFail($job->list_id);
        $fieldsByTag = ListField::withoutTenancy()->where('list_id', $list->id)->get()->keyBy('tag');

        $records = Statement::create()
            ->offset($offset + $headerRows)
            ->process($this->reader($job));

        $line = $offset + $headerRows;

        foreach ($records as $record) {
            $line++;
            $cells = array_values($record);

            $email = '';
            $fields = [];

            foreach ($mapping as $index => $target) {
                $value = $cells[(int) $index] ?? null;
                $value = $value === null ? null : trim((string) $value);

                if ($target === CsvAnalyzer::TARGET_EMAIL) {
                    $email = (string) $value;

                    continue;
                }

                if (! str_starts_with($target, CsvAnalyzer::TARGET_FIELD_PREFIX) || $value === null || $value === '') {
                    continue;
                }

                $tag = substr($target, strlen(CsvAnalyzer::TARGET_FIELD_PREFIX));
                $field = $fieldsByTag->get($tag);

                $fields[$tag] = $field === null ? $value : $this->castForField($field, $value);
            }

            yield ['line' => $line, 'email' => $email, 'fields' => $fields];
        }
    }

    /** CSV cells are strings; coerce them for multi-value/boolean fields. */
    private function castForField(ListField $field, string $value): mixed
    {
        if ($field->type->isMultiValue()) {
            return array_values(array_filter(array_map('trim', preg_split('/[|;]/', $value) ?: []), fn (string $v): bool => $v !== ''));
        }

        if ($field->type->isBoolean()) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'y', 'on'], true);
        }

        return $value;
    }

    /**
     * Create Text fields for "new:Header" targets (F-2.2.7-007) and rewrite
     * them to "field:TAG".
     *
     * @param  array<int|string, string>  $mapping
     * @return array<int, string>
     */
    private function materializeNewFields(ImportJob $job, array $mapping): array
    {
        /** @var EmailList $list */
        $list = EmailList::withoutTenancy()->findOrFail($job->list_id);
        $result = [];

        foreach ($mapping as $index => $target) {
            if (! str_starts_with($target, CsvAnalyzer::TARGET_NEW_PREFIX)) {
                $result[(int) $index] = $target;

                continue;
            }

            $label = trim(substr($target, strlen(CsvAnalyzer::TARGET_NEW_PREFIX)));

            if ($label === '') {
                $result[(int) $index] = CsvAnalyzer::TARGET_SKIP;

                continue;
            }

            $tag = $this->analyzer->tagFromHeader($label, $list);

            ListField::create([
                'customer_id' => $list->customer_id,
                'list_id' => $list->id,
                'type' => ListFieldType::Text,
                'label' => $label,
                'tag' => $tag,
                'sort_order' => (int) $list->fields()->max('sort_order') + 1,
            ]);

            $result[(int) $index] = CsvAnalyzer::TARGET_FIELD_PREFIX.$tag;
        }

        return $result;
    }

    /** Download a URL import (F-2.2.7-003), gated by §3.25.2 (F-3.25.2-004). */
    private function download(ImportJob $job): void
    {
        if (! SettingsReader::importExport()->importFromUrlEnabled()) {
            throw new ImportFailedException(__('imports.errors.url_disabled'));
        }

        $url = (string) (((array) $job->options)['url'] ?? '');

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new ImportFailedException(__('imports.errors.url_invalid'));
        }

        $response = Http::timeout(120)->get($url);

        if (! $response->successful()) {
            throw new ImportFailedException(__('imports.errors.url_unreachable', ['status' => $response->status()]));
        }

        $body = $response->body();

        $errors = $this->validator->validate($url, strlen($body));

        if ($errors !== []) {
            throw new ImportFailedException(implode(' ', $errors));
        }

        $path = $job->storageDirectory().'/'.$job->uid.'.csv';
        Storage::disk('local')->put($path, $body);

        $job->file_path = $path;
        $job->save();
    }

    private function reader(ImportJob $job): Reader
    {
        $options = (array) $job->options;

        $reader = Reader::createFromPath(Storage::disk('local')->path((string) $job->file_path));
        $reader->setDelimiter((string) ($options['delimiter'] ?? ','));
        $reader->setEnclosure((string) ($options['enclosure'] ?? '"'));

        return $reader;
    }

    /** First 64KB for delimiter/enclosure/header sniffing. */
    private function sample(ImportJob $job): string
    {
        $stream = Storage::disk('local')->readStream((string) $job->file_path);

        if ($stream === null) {
            throw new ImportFailedException(__('imports.errors.file_missing'));
        }

        $sample = (string) stream_get_contents($stream, 65536);
        fclose($stream);

        return $sample;
    }
}
