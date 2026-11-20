<?php

declare(strict_types=1);

use App\Enums\ExportFormat;
use App\Enums\ListFieldType;
use App\Enums\SurveyResponderStatus;
use App\Jobs\Surveys\RunSurveyResponderExportJob;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ExportJob;
use App\Models\FieldOption;
use App\Models\Subscriber;
use App\Models\Survey;
use App\Models\SurveyField;
use App\Models\SurveyResponder;
use App\Models\SurveyResponderAnswer;
use App\Services\Lists\FieldValueService;
use App\Services\Surveys\SurveyResponderExporter;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\AutomationFixtures as Fx;

/*
 * F-2.8-005 — responders list with all answers, exported to CSV (and XLSX)
 * through the existing WP-08/WP-26 export engine.
 */

beforeEach(function (): void {
    $this->customer = Fx::actingAsCustomer();
    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);

    $this->survey = Survey::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'name' => 'Feedback',
    ]);

    $this->rating = SurveyField::factory()->create([
        'survey_id' => $this->survey->id,
        'customer_id' => $this->customer->id,
        'type' => ListFieldType::Rating,
        'tag' => 'SCORE',
        'label' => 'Score',
        'sort_order' => 0,
    ]);

    $this->multi = SurveyField::factory()->create([
        'survey_id' => $this->survey->id,
        'customer_id' => $this->customer->id,
        'type' => ListFieldType::CheckboxList,
        'tag' => 'FEATURES',
        'label' => 'Features used',
        'sort_order' => 1,
    ]);

    foreach ([['Reports', 'reports'], ['Automations', 'automations']] as $index => [$label, $value]) {
        FieldOption::withoutTenancy()->create([
            'customer_id' => $this->customer->id,
            'owner_type' => SurveyField::class,
            'owner_id' => $this->multi->id,
            'label' => $label, 'value' => $value, 'sort_order' => $index,
        ]);
    }
});

/** One completed response with the given answers. */
function respondWith(Survey $survey, array $answers, ?Subscriber $subscriber = null): SurveyResponder
{
    /** @var SurveyResponder $responder */
    $responder = SurveyResponder::withoutTenancy()->create([
        'customer_id' => $survey->customer_id,
        'survey_id' => $survey->id,
        'subscriber_id' => $subscriber?->id,
        'email' => $subscriber?->email,
        'status' => SurveyResponderStatus::Complete,
        'completed_at' => now(),
    ]);

    $values = app(FieldValueService::class);

    foreach ($answers as $fieldId => $value) {
        $field = SurveyField::withoutTenancy()->findOrFail($fieldId);

        SurveyResponderAnswer::withoutTenancy()->create([
            'customer_id' => $survey->customer_id,
            'survey_responder_id' => $responder->id,
            'survey_field_id' => $field->id,
            'value' => $values->toStorage($field, $value),
        ]);
    }

    return $responder;
}

it('queues the responder export onto the shared export engine (F-2.8-005)', function (): void {
    Queue::fake();

    $export = app(SurveyResponderExporter::class)->queue($this->survey);

    expect($export->scope)->toBe(SurveyResponderExporter::SCOPE)
        ->and($export->status)->toBe(ExportJob::STATUS_PENDING)
        ->and($export->filters['survey_id'])->toBe($this->survey->id)
        ->and((int) $export->customer_id)->toBe((int) $this->customer->id);

    Queue::assertPushed(RunSurveyResponderExportJob::class);
});

it('writes a CSV with one column per question and every answer (F-2.8-005)', function (): void {
    $subscriber = Subscriber::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'email' => 'named@example.test',
    ]);

    respondWith($this->survey, [
        $this->rating->id => 5,
        $this->multi->id => ['reports', 'automations'],
    ], $subscriber);

    respondWith($this->survey, [$this->rating->id => 2]);

    $export = app(SurveyResponderExporter::class)->queue($this->survey);
    $path = app(SurveyResponderExporter::class)->write($export->refresh());

    $csv = Storage::disk('local')->get($path);

    expect($csv)->toContain('Score')
        ->and($csv)->toContain('Features used')
        ->and($csv)->toContain('named@example.test')
        ->and($csv)->toContain('reports, automations')
        ->and($csv)->toContain(__('surveys.responders.identified'))
        ->and($csv)->toContain(__('surveys.responders.anonymous'))
        ->and($export->refresh()->total_rows)->toBe(2);
});

it('round-trips CJK and emoji answers through the export', function (): void {
    $free = SurveyField::factory()->create([
        'survey_id' => $this->survey->id,
        'customer_id' => $this->customer->id,
        'type' => ListFieldType::Textarea,
        'tag' => 'COMMENT',
        'label' => 'コメント',
        'sort_order' => 2,
    ]);

    respondWith($this->survey, [$free->id => '素晴らしい 🎉 훌륭해요']);

    $export = app(SurveyResponderExporter::class)->queue($this->survey);
    $csv = Storage::disk('local')->get(app(SurveyResponderExporter::class)->write($export->refresh()));

    expect($csv)->toContain('コメント')
        ->and($csv)->toContain('素晴らしい 🎉 훌륭해요');
});

it('supports the XLSX format the export engine already offers (D-84)', function (): void {
    respondWith($this->survey, [$this->rating->id => 4]);

    $export = app(SurveyResponderExporter::class)->queue($this->survey, ExportFormat::Xlsx);
    $path = app(SurveyResponderExporter::class)->write($export->refresh());

    expect($path)->toEndWith('.xlsx')
        ->and(Storage::disk('local')->exists($path))->toBeTrue();
});

it('names the download after the survey on the shared signed route', function (): void {
    respondWith($this->survey, [$this->rating->id => 3]);

    $export = app(SurveyResponderExporter::class)->queue($this->survey);
    app(SurveyResponderExporter::class)->write($export->refresh());

    $export->forceFill(['status' => ExportJob::STATUS_COMPLETED])->save();

    $this->get(URL::signedRoute('lists.exports.download', ['uid' => $export->uid]))
        ->assertOk()
        ->assertDownload('survey-responders-'.$export->uid.'.csv');
});

it('refuses to export another tenant\'s survey', function (): void {
    $other = Customer::factory()->create();

    $foreignSurvey = app(CurrentCustomer::class)->runAs(
        $other,
        fn (): Survey => Survey::factory()->create(['customer_id' => $other->id]),
    );

    // The export row belongs to MY tenant, so the writer refuses to resolve
    // a survey from someone else's.
    $export = ExportJob::query()->create([
        'customer_id' => $this->customer->id,
        'scope' => SurveyResponderExporter::SCOPE,
        'status' => ExportJob::STATUS_PENDING,
        'columns' => [],
        'format' => ExportFormat::Csv,
        'filters' => ['survey_id' => $foreignSurvey->id],
    ]);

    expect(fn () => app(SurveyResponderExporter::class)->write($export))
        ->toThrow(RuntimeException::class);
});
