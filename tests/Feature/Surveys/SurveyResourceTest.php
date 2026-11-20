<?php

declare(strict_types=1);

use App\Enums\ListFieldType;
use App\Enums\SurveyResponderStatus;
use App\Enums\SurveyStatus;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\CustomerGroupLimit;
use App\Models\CustomerGroupPermission;
use App\Models\EmailList;
use App\Models\FieldOption;
use App\Models\Survey;
use App\Models\SurveyField;
use App\Models\SurveyResponder;
use App\Policies\SurveyPolicy;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Tests\Support\AutomationFixtures as Fx;

/*
 * §2.8 surveys — panel side.
 *
 * F-2.8-001 index with responder counts
 * F-2.8-002 name, description, display name, start/end, status, max responders
 * F-2.8-003 questions from the list custom field type library
 * plus the §3.5 quota gate and the cross-tenant fence.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-13 12:00:00');
    $this->customer = Fx::actingAsCustomer();
    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);
});

afterEach(fn () => Carbon::setTestNow());

it('stores every survey attribute of F-2.8-002', function (): void {
    $survey = Survey::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'name' => 'NPS Q3',
        'display_name' => 'How are we doing? 📈',
        'description' => 'Two quick questions.',
        'status' => SurveyStatus::Active,
        'start_at' => now()->subDay(),
        'end_at' => now()->addWeek(),
        'max_responders' => 250,
    ]);

    $survey->refresh();

    expect($survey->name)->toBe('NPS Q3')
        ->and($survey->publicName())->toBe('How are we doing? 📈')
        ->and($survey->status)->toBe(SurveyStatus::Active)
        ->and($survey->max_responders)->toBe(250)
        ->and($survey->uid)->not->toBeEmpty()
        ->and($survey->isOpen())->toBeTrue();

    // display_name is optional; the internal name is the fallback.
    $survey->forceFill(['display_name' => null])->save();
    expect($survey->refresh()->publicName())->toBe('NPS Q3');
});

it('counts responders for the index (F-2.8-001)', function (): void {
    $survey = Survey::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
    ]);

    foreach ([SurveyResponderStatus::Complete, SurveyResponderStatus::Complete, SurveyResponderStatus::Incomplete] as $status) {
        SurveyResponder::withoutTenancy()->create([
            'customer_id' => $this->customer->id,
            'survey_id' => $survey->id,
            'status' => $status,
        ]);
    }

    $row = Survey::query()
        ->withCount([
            'responders',
            'responders as completed_count' => fn ($q) => $q->where('status', SurveyResponderStatus::Complete->value),
        ])
        ->findOrFail($survey->id);

    expect((int) $row->responders_count)->toBe(3)
        ->and((int) $row->completed_count)->toBe(2)
        // completedCount() is the authority the cap uses.
        ->and($survey->completedCount())->toBe(2);
});

it('builds questions from the whole list field type library (F-2.8-003)', function (): void {
    $survey = Survey::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
    ]);

    foreach (ListFieldType::cases() as $index => $type) {
        SurveyField::factory()->create([
            'survey_id' => $survey->id,
            'customer_id' => $this->customer->id,
            'type' => $type,
            'tag' => 'Q_'.strtoupper($type->value),
            'sort_order' => $index,
        ]);
    }

    expect($survey->fields()->count())->toBe(count(ListFieldType::cases()));

    $radio = $survey->fields()->where('type', ListFieldType::Radio->value)->firstOrFail();

    FieldOption::withoutTenancy()->create([
        'customer_id' => $this->customer->id,
        'owner_type' => SurveyField::class,
        'owner_id' => $radio->id,
        'label' => 'Very likely', 'value' => '10', 'sort_order' => 0,
    ]);

    expect($radio->refresh()->optionValues())->toBe(['10'])
        ->and($radio->isAggregatable())->toBeTrue();

    $free = $survey->fields()->where('type', ListFieldType::Textarea->value)->firstOrFail();
    expect($free->isAggregatable())->toBeFalse();
});

it('groups questions into wizard steps (F-1.9-001)', function (): void {
    $survey = Survey::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'fields_per_step' => 2,
    ]);

    foreach (range(1, 5) as $i) {
        SurveyField::factory()->create([
            'survey_id' => $survey->id,
            'customer_id' => $this->customer->id,
            'tag' => 'Q'.$i,
            'sort_order' => $i,
        ]);
    }

    $steps = $survey->refresh()->steps();

    expect($steps)->toHaveCount(3)
        ->and($steps[0])->toHaveCount(2)
        ->and($steps[2])->toHaveCount(1)
        ->and($survey->stepCount())->toBe(3);

    // 0 = every question on one page.
    $survey->forceFill(['fields_per_step' => 0])->save();
    expect($survey->refresh()->steps())->toHaveCount(1);
});

it('closes itself on the end date and the responder cap (F-1.9-004)', function (): void {
    $survey = Survey::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'end_at' => now()->addHour(),
        'max_responders' => 2,
    ]);

    expect($survey->isOpen())->toBeTrue();

    // Cap.
    foreach (range(1, 2) as $ignored) {
        SurveyResponder::withoutTenancy()->create([
            'customer_id' => $this->customer->id,
            'survey_id' => $survey->id,
            'status' => SurveyResponderStatus::Complete,
        ]);
    }

    expect($survey->isFull())->toBeTrue()
        ->and($survey->isOpen())->toBeFalse()
        ->and($survey->closedReason())->toBe('full');

    // Date.
    $survey->forceFill(['max_responders' => 0])->save();
    expect($survey->refresh()->isOpen())->toBeTrue();

    Carbon::setTestNow('2026-08-13 14:00:00');
    expect($survey->isExpired())->toBeTrue()
        ->and($survey->isOpen())->toBeFalse()
        ->and($survey->closedReason())->toBe('ended');

    // Not started yet.
    $pending = Survey::factory()->create([
        'customer_id' => $this->customer->id,
        'start_at' => now()->addDay(),
    ]);

    expect($pending->isPending())->toBeTrue()
        ->and($pending->isOpen())->toBeFalse()
        ->and($pending->closedReason())->toBe('not_started');
});

it('treats 0 and NULL max_responders as unlimited (D-23)', function (): void {
    foreach ([null, 0] as $max) {
        $survey = Survey::factory()->create([
            'customer_id' => $this->customer->id,
            'max_responders' => $max,
        ]);

        SurveyResponder::withoutTenancy()->create([
            'customer_id' => $this->customer->id,
            'survey_id' => $survey->id,
            'status' => SurveyResponderStatus::Complete,
        ]);

        expect($survey->isFull())->toBeFalse();
    }
});

it('gates creation on the surveys.enabled permission and the max_surveys limit (§3.5)', function (): void {
    $group = CustomerGroup::factory()->create();
    $this->customer->forceFill(['customer_group_id' => $group->id])->save();
    $this->customer->refresh();

    CustomerGroupPermission::query()->create([
        'customer_group_id' => $group->id,
        'key' => SurveyPolicy::CUSTOMER_PERMISSION,
        'allowed' => false,
    ]);

    expect(Gate::forUser($this->customer)->allows('viewAny', Survey::class))->toBeFalse()
        ->and(Gate::forUser($this->customer)->allows('create', Survey::class))->toBeFalse();

    CustomerGroupPermission::query()
        ->where('customer_group_id', $group->id)
        ->where('key', SurveyPolicy::CUSTOMER_PERMISSION)
        ->update(['allowed' => true]);
    CustomerGroupLimit::query()->create([
        'customer_group_id' => $group->id,
        'key' => SurveyPolicy::LIMIT_KEY,
        'value' => 1,
    ]);

    $this->customer->refresh();
    expect(Gate::forUser($this->customer)->allows('create', Survey::class))->toBeTrue();

    Survey::factory()->create(['customer_id' => $this->customer->id]);

    $this->customer->refresh();
    expect(Gate::forUser($this->customer)->allows('create', Survey::class))->toBeFalse();
});

it('denies every cross-tenant read and write of a survey', function (): void {
    $other = Customer::factory()->create();

    $foreign = app(CurrentCustomer::class)->runAs(
        $other,
        fn (): Survey => Survey::factory()->create(['customer_id' => $other->id]),
    );

    expect(Survey::query()->whereKey($foreign->id)->exists())->toBeFalse()
        ->and(Gate::forUser($this->customer)->allows('view', $foreign))->toBeFalse()
        ->and(Gate::forUser($this->customer)->allows('update', $foreign))->toBeFalse()
        ->and(Gate::forUser($this->customer)->allows('delete', $foreign))->toBeFalse();

    // Their responders are invisible too.
    app(CurrentCustomer::class)->runAs($other, function () use ($foreign, $other): void {
        SurveyResponder::withoutTenancy()->create([
            'customer_id' => $other->id,
            'survey_id' => $foreign->id,
            'status' => SurveyResponderStatus::Complete,
        ]);
    });

    expect(SurveyResponder::query()->count())->toBe(0)
        ->and(SurveyResponder::withoutTenancy()->count())->toBe(1);
});
