<?php

declare(strict_types=1);

namespace App\Services\Surveys;

use App\Enums\ExportFormat;
use App\Jobs\Surveys\RunSurveyResponderExportJob;
use App\Models\ExportJob;
use App\Models\Survey;
use App\Models\SurveyResponder;
use App\Models\SurveyResponderAnswer;
use App\Services\Exports\Writers\RowWriterFactory;
use App\Services\Lists\FieldValueService;
use App\Services\Settings\SettingsReader;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Responder export (spec F-2.8-005), riding the WP-08/WP-26 export engine:
 * an `export_jobs` row with `scope = survey` and `filters = {survey_id}`,
 * written by a queued job, stored on the private disk and served through the
 * existing signed + policy-checked download route. Nothing new was invented
 * for retention, pruning, history or download authorisation.
 *
 * The writer streams through {@see RowWriterFactory}, so a survey with a
 * hundred thousand responders costs the same memory as one with ten, and CJK
 * and emoji answers pass through byte-for-byte.
 */
class SurveyResponderExporter
{
    /** Distinguishes these rows in `export_jobs` (see CampaignReportExporter::SCOPE). */
    public const SCOPE = 'survey';

    public function __construct(private readonly FieldValueService $fieldValues) {}

    /** Creates the queued export row + dispatches the runner. */
    public function queue(Survey $survey, ExportFormat|string|null $format = null): ExportJob
    {
        /** @var ExportJob $export */
        $export = ExportJob::query()->create([
            'customer_id' => $survey->customer_id,
            'scope' => self::SCOPE,
            'status' => ExportJob::STATUS_PENDING,
            'columns' => [],
            'format' => $format instanceof ExportFormat ? $format : ExportFormat::fromValue($format),
            'filters' => ['survey_id' => (int) $survey->id],
        ]);

        RunSurveyResponderExportJob::dispatch($export->id);

        return $export;
    }

    /** Writes the file and returns its storage path. */
    public function write(ExportJob $export): string
    {
        $survey = $this->survey($export);
        $fields = $survey->orderedFields();

        $format = $export->format instanceof ExportFormat
            ? $export->format
            : ExportFormat::fromValue($export->format);

        $disk = Storage::disk('local');
        $disk->makeDirectory($export->storageDirectory());

        $path = $export->storageDirectory().'/'.$export->uid.'.'.$format->extension();
        $writer = RowWriterFactory::make($format, $disk->path($path));
        $writer->sheet(__('surveys.export.sheet_name'));

        $headers = [
            __('surveys.export.responder_uid'),
            __('surveys.export.status'),
            __('surveys.export.identity'),
            __('surveys.export.email'),
            __('surveys.export.started_at'),
            __('surveys.export.completed_at'),
            __('surveys.export.ip'),
        ];

        foreach ($fields as $field) {
            $headers[] = (string) $field->label;
        }

        $writer->writeRow($headers);

        $batchSize = max(1, SettingsReader::importExport()->exportBatchSize());
        $written = 0;

        SurveyResponder::withoutTenancy()
            ->where('survey_id', $survey->id)
            ->orderBy('id')
            ->chunkById($batchSize, function ($responders) use ($writer, $fields, &$written): void {
                $answers = SurveyResponderAnswer::withoutTenancy()
                    ->whereIn('survey_responder_id', $responders->pluck('id')->all())
                    ->get()
                    ->groupBy('survey_responder_id');

                foreach ($responders as $responder) {
                    $byField = ($answers->get($responder->id) ?? collect())->keyBy('survey_field_id');

                    $row = [
                        (string) $responder->uid,
                        $responder->status->value,
                        $responder->isAnonymous()
                            ? __('surveys.responders.anonymous')
                            : __('surveys.responders.identified'),
                        (string) ($responder->email ?? ''),
                        $responder->started_at?->toDateTimeString() ?? '',
                        $responder->completed_at?->toDateTimeString() ?? '',
                        (string) ($responder->ip ?? ''),
                    ];

                    foreach ($fields as $field) {
                        $raw = $byField->get($field->id)?->value;
                        $row[] = $this->stringify($this->fieldValues->fromStorage($field, $raw === null ? null : (string) $raw));
                    }

                    $writer->writeRow($row);
                    $written++;
                }
            });

        $writer->close();

        $export->file_path = $path;
        $export->total_rows = $written;
        $export->processed_rows = $written;
        $export->save();

        return $path;
    }

    private function survey(ExportJob $export): Survey
    {
        $id = (int) (($export->filters ?? [])['survey_id'] ?? 0);

        /** @var Survey|null $survey */
        $survey = Survey::withoutTenancy()
            ->where('customer_id', $export->customer_id)
            ->find($id);

        if ($survey === null) {
            throw new RuntimeException('Survey '.$id.' not found for export '.$export->uid.'.');
        }

        return $survey;
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_array($value)) {
            return implode(', ', array_map(fn (mixed $item): string => $this->stringify($item), $value));
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}
