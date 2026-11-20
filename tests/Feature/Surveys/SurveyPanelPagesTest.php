<?php

declare(strict_types=1);

use App\Enums\ListFieldType;
use App\Enums\SurveyResponderStatus;
use App\Filament\Customer\Resources\SurveyResource\Pages\CreateSurvey;
use App\Filament\Customer\Resources\SurveyResource\Pages\ListSurveys;
use App\Filament\Customer\Resources\SurveyResource\Pages\ManageSurveyFields;
use App\Filament\Customer\Resources\SurveyResource\Pages\SurveyResponders;
use App\Filament\Customer\Resources\SurveyResource\Pages\SurveyStatsPage;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ExportJob;
use App\Models\FieldOption;
use App\Models\ListSegment;
use App\Models\Subscriber;
use App\Models\Survey;
use App\Models\SurveyField;
use App\Models\SurveyResponder;
use App\Models\SurveyResponderAnswer;
use App\Services\Lists\FieldValueService;
use App\Services\Segments\SegmentQueryBuilder;
use App\Services\Surveys\SurveyResponderExporter;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Tests\Support\AutomationFixtures as Fx;

/*
 * §2.8 panel pages: index (F-2.8-001), question builder (F-2.8-003),
 * responders with every answer + CSV export (F-2.8-005), stats (F-2.8-006)
 * and the segment action (F-2.8-007), plus the cross-tenant fence.
 */

beforeEach(function (): void {
    $this->customer = Fx::actingAsCustomer();
    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);

    $this->survey = Survey::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'name' => 'Quarterly NPS',
    ]);
});

it('renders the surveys index with responder counts (F-2.8-001)', function (): void {
    SurveyResponder::withoutTenancy()->create([
        'customer_id' => $this->customer->id,
        'survey_id' => $this->survey->id,
        'status' => SurveyResponderStatus::Complete,
    ]);

    Livewire::test(ListSurveys::class)
        ->assertOk()
        ->assertSee('Quarterly NPS')
        // F-2.8-008: the campaign merge tag is copyable from the row.
        ->assertSee('{SURVEY_URL:'.$this->survey->uid.'}');
});

it('creates a survey through the panel (F-2.8-002)', function (): void {
    Livewire::test(CreateSurvey::class)
        ->fillForm([
            'name' => 'Onboarding survey',
            'display_name' => 'Tell us about you',
            'list_id' => $this->list->id,
            'status' => 'active',
            'max_responders' => 500,
            'fields_per_step' => 2,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $survey = Survey::query()->where('name', 'Onboarding survey')->firstOrFail();

    expect($survey->publicName())->toBe('Tell us about you')
        ->and($survey->max_responders)->toBe(500)
        ->and($survey->fields_per_step)->toBe(2)
        ->and($survey->uid)->not->toBeEmpty();
});

it('adds a question through the builder (F-2.8-003)', function (): void {
    Livewire::test(ManageSurveyFields::class, ['record' => $this->survey->getKey()])
        ->assertOk()
        ->callTableAction('create', data: [
            'type' => ListFieldType::Rating->value,
            'label' => 'How likely are you to recommend us?',
            'tag' => 'nps',
            'required' => true,
        ])
        ->assertHasNoTableActionErrors();

    $field = SurveyField::query()->where('survey_id', $this->survey->id)->firstOrFail();

    expect($field->tag)->toBe('NPS')
        ->and($field->type)->toBe(ListFieldType::Rating)
        ->and($field->required)->toBeTrue();
});

it('rejects a duplicate question tag', function (): void {
    SurveyField::factory()->create([
        'survey_id' => $this->survey->id,
        'customer_id' => $this->customer->id,
        'tag' => 'NPS',
        'label' => 'Score',
    ]);

    Livewire::test(ManageSurveyFields::class, ['record' => $this->survey->getKey()])
        ->callTableAction('create', data: ['type' => 'text', 'label' => 'Again', 'tag' => 'nps'])
        ->assertHasTableActionErrors(['tag']);
});

it('lists responders with a column per answer and queues the export (F-2.8-005)', function (): void {
    $question = SurveyField::factory()->create([
        'survey_id' => $this->survey->id,
        'customer_id' => $this->customer->id,
        'type' => ListFieldType::CheckboxList,
        'tag' => 'FEATURES',
        'label' => 'Features used',
        'sort_order' => 0,
    ]);

    foreach ([['Reports', 'reports'], ['Automations', 'automations']] as $index => [$label, $value]) {
        FieldOption::withoutTenancy()->create([
            'customer_id' => $this->customer->id,
            'owner_type' => SurveyField::class,
            'owner_id' => $question->id,
            'label' => $label, 'value' => $value, 'sort_order' => $index,
        ]);
    }

    $subscriber = Subscriber::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'email' => 'named@example.test',
    ]);

    $responder = SurveyResponder::withoutTenancy()->create([
        'customer_id' => $this->customer->id,
        'survey_id' => $this->survey->id,
        'subscriber_id' => $subscriber->id,
        'email' => $subscriber->email,
        'status' => SurveyResponderStatus::Complete,
        'completed_at' => now(),
    ]);

    SurveyResponderAnswer::withoutTenancy()->create([
        'customer_id' => $this->customer->id,
        'survey_responder_id' => $responder->id,
        'survey_field_id' => $question->id,
        'value' => app(FieldValueService::class)->toStorage($question->refresh(), ['reports', 'automations']),
    ]);

    Livewire::test(SurveyResponders::class, ['record' => $this->survey->getKey()])
        ->assertOk()
        ->assertSee('named@example.test')
        ->assertSee(__('surveys.responders.identified'))
        // The multi-value answer reads as text, never as a JSON array.
        ->assertSee('reports, automations')
        ->callTableAction('export', data: ['format' => 'csv']);

    $export = ExportJob::query()->where('scope', SurveyResponderExporter::SCOPE)->firstOrFail();

    expect($export->filters['survey_id'])->toBe($this->survey->id);
});

it('renders the stats page with buckets and the segment action (F-2.8-006/007)', function (): void {
    $question = SurveyField::factory()->create([
        'survey_id' => $this->survey->id,
        'customer_id' => $this->customer->id,
        'type' => ListFieldType::Radio,
        'tag' => 'HAPPY',
        'label' => 'Are you happy?',
        'sort_order' => 0,
    ]);

    FieldOption::withoutTenancy()->create([
        'customer_id' => $this->customer->id,
        'owner_type' => SurveyField::class,
        'owner_id' => $question->id,
        'label' => 'Yes indeed', 'value' => 'yes', 'sort_order' => 0,
    ]);

    $subscriber = Subscriber::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'email' => 'happy@example.test',
    ]);

    $responder = SurveyResponder::withoutTenancy()->create([
        'customer_id' => $this->customer->id,
        'survey_id' => $this->survey->id,
        'subscriber_id' => $subscriber->id,
        'status' => SurveyResponderStatus::Complete,
        'completed_at' => now(),
    ]);

    SurveyResponderAnswer::withoutTenancy()->create([
        'customer_id' => $this->customer->id,
        'survey_responder_id' => $responder->id,
        'survey_field_id' => $question->id,
        'value' => 'yes',
    ]);

    Livewire::test(SurveyStatsPage::class, ['record' => $this->survey->getKey()])
        ->assertOk()
        ->assertSee('Are you happy?')
        ->assertSee('Yes indeed')
        ->callAction('segment', data: [
            'survey_field_id' => $question->id,
            'operator' => 'is',
            'value' => 'yes',
            'name' => 'Happy customers',
        ]);

    $segment = ListSegment::query()->where('name', 'Happy customers')->firstOrFail();

    expect(app(SegmentQueryBuilder::class)->subscribers($segment)->pluck('email')->all())
        ->toBe(['happy@example.test']);
});

it('refuses every record page for another tenant\'s survey', function (): void {
    $other = Customer::factory()->create();

    $foreign = app(CurrentCustomer::class)->runAs(
        $other,
        fn (): Survey => Survey::factory()->create(['customer_id' => $other->id]),
    );

    foreach ([SurveyStatsPage::class, ManageSurveyFields::class, SurveyResponders::class] as $page) {
        expect(fn () => Livewire::test($page, ['record' => $foreign->getKey()]))
            ->toThrow(ModelNotFoundException::class);
    }
});
