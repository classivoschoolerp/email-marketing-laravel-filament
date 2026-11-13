<?php

declare(strict_types=1);

use App\Filament\Customer\Resources\EmailListResource;
use App\Filament\Customer\Resources\EmailListResource\Pages\ManageListSegments;
use App\Jobs\Exports\RunExportJob;
use App\Models\Campaign;
use App\Models\CampaignTrackUrl;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ExportJob;
use App\Models\ListField;
use App\Models\ListSegment;
use App\Models\Subscriber;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->for($this->customer)->create();
});

function segmentsPage(): Testable
{
    return Livewire::test(ManageListSegments::class, ['record' => test()->list->getRouteKey()]);
}

it('renders the segments page', function (): void {
    ListSegment::factory()->for($this->list, 'list')->create(['name' => 'Actives']);

    $this->get(EmailListResource::getUrl('segments', ['record' => $this->list], panel: 'customer'))
        ->assertOk()
        ->assertSee('Actives');
});

it('creates a segment with ALL/ANY matching and typed conditions (F-2.2.10-001)', function (): void {
    $field = ListField::factory()->for($this->list, 'list')->create(['tag' => 'CITY']);

    $undoRepeaterFake = Repeater::fake();

    segmentsPage()
        ->callTableAction('create', data: [
            'name' => 'Locals',
            'match_type' => 'any',
            'conditions' => [
                [
                    'type' => 'field',
                    'list_field_id' => $field->id,
                    'operator' => 'contains',
                    'value' => 'Casa',
                ],
                [
                    'type' => 'status',
                    'operator' => 'is',
                    'value' => 'confirmed',
                ],
            ],
        ])
        ->assertHasNoTableActionErrors();

    $undoRepeaterFake();

    $segment = ListSegment::query()->where('name', 'Locals')->firstOrFail();

    expect($segment->match_type->value)->toBe('any')
        ->and($segment->list_id)->toBe($this->list->id)
        ->and($segment->customer_id)->toBe($this->customer->id)
        ->and($segment->conditions)->toHaveCount(2)
        ->and($segment->conditions->first()->list_field_id)->toBe($field->id)
        ->and($segment->conditions->first()->customer_id)->toBe($this->customer->id);
});

it('previews the live subscriber count before saving (F-2.2.10-010)', function (): void {
    Subscriber::factory()->for($this->list, 'list')->count(2)->create();
    Subscriber::factory()->for($this->list, 'list')->unsubscribed()->create();

    /** @var ManageListSegments $page */
    $page = segmentsPage()->instance();

    expect($page->previewCount('all', [
        ['type' => 'status', 'operator' => 'is', 'value' => 'confirmed'],
    ]))->toBe(trans_choice('segments.form.preview_count', 2, ['count' => '2']))
        ->and($page->previewCount('all', [
            ['type' => 'status', 'operator' => 'is', 'value' => 'unsubscribed'],
        ]))->toBe(trans_choice('segments.form.preview_count', 1, ['count' => '1']))
        ->and($page->previewCount('all', [
            ['type' => 'field', 'operator' => 'not-a-real-operator', 'value' => 'x'],
        ]))->toBe(__('segments.form.preview_incomplete'));
});

it('shows a live count column that recalculates dynamically (F-2.2.10-012)', function (): void {
    $segment = ListSegment::factory()->for($this->list, 'list')->create();
    $segment->conditions()->create([
        'customer_id' => $this->customer->id,
        'type' => 'status',
        'operator' => 'is',
        'value' => 'confirmed',
        'sort_order' => 0,
    ]);

    segmentsPage()->assertTableColumnStateSet('subscriber_count', '0', record: $segment->getKey());

    Subscriber::factory()->for($this->list, 'list')->count(3)->create();

    segmentsPage()->assertTableColumnStateSet('subscriber_count', '3', record: $segment->getKey());
});

it('duplicates a segment with its conditions (F-2.2.10-014)', function (): void {
    $segment = ListSegment::factory()->for($this->list, 'list')->create(['name' => 'Origin']);
    $segment->conditions()->create([
        'customer_id' => $this->customer->id,
        'type' => 'status',
        'operator' => 'is',
        'value' => 'confirmed',
        'sort_order' => 0,
    ]);

    segmentsPage()->callTableAction('duplicate', $segment);

    $copy = ListSegment::query()
        ->where('name', __('segments.duplicate_name', ['name' => 'Origin']))
        ->firstOrFail();

    expect($copy->id)->not->toBe($segment->id)
        ->and($copy->uid)->not->toBe($segment->uid)
        ->and($copy->conditions)->toHaveCount(1)
        ->and($copy->conditions->first()->operator->value)->toBe('is');
});

it('stores a campaign condition with its campaign, link and window (F-2.2.10-003)', function (): void {
    $campaign = Campaign::factory()->for($this->customer)->create(['email_list_id' => $this->list->id]);
    $url = CampaignTrackUrl::create([
        'campaign_id' => $campaign->id,
        'campaign_uid' => $campaign->uid,
        'url' => 'https://example.test/deal',
        'hash' => str_repeat('a', 64),
    ]);

    $undoRepeaterFake = Repeater::fake();

    segmentsPage()
        ->callTableAction('create', data: [
            'name' => 'Clickers',
            'match_type' => 'all',
            'conditions' => [[
                'type' => 'campaign',
                'operator' => 'clicked_link',
                'settings' => [
                    'campaign_id' => $campaign->id,
                    'link_id' => $url->id,
                    'within_days' => 30,
                ],
            ]],
        ])
        ->assertHasNoTableActionErrors();

    $undoRepeaterFake();

    $condition = ListSegment::query()->where('name', 'Clickers')->firstOrFail()->conditions()->firstOrFail();

    expect($condition->type->value)->toBe('campaign')
        ->and($condition->operator->value)->toBe('clicked_link')
        ->and((int) $condition->settings['campaign_id'])->toBe($campaign->id)
        ->and((int) $condition->settings['link_id'])->toBe($url->id)
        ->and((int) $condition->settings['within_days'])->toBe(30);
});

it('queues a segment export through the export engine (F-2.2.10-014)', function (): void {
    Queue::fake();

    $segment = ListSegment::factory()->for($this->list, 'list')->create(['name' => 'Exportable']);

    segmentsPage()->callTableAction('export', $segment, data: [
        'columns' => ['email', 'status'],
        'format' => 'csv',
    ]);

    $export = ExportJob::query()->latest('id')->firstOrFail();

    expect(ExportJob::query()->count())->toBe(1)
        ->and($export->scope)->toBe(ExportJob::SCOPE_SEGMENT)
        ->and((int) $export->list_segment_id)->toBe($segment->id)
        ->and((int) $export->list_id)->toBe($this->list->id)
        ->and((int) $export->customer_id)->toBe($this->customer->id)
        ->and($export->columns)->toContain('email')
        ->and($export->columns)->toContain('status')
        ->and($export->format->value)->toBe('csv');

    Queue::assertPushed(RunExportJob::class, fn (RunExportJob $job): bool => $job->exportJobId === (int) $export->id);
});

it('writes exactly the segment members with the chosen columns (F-2.2.10-014)', function (): void {
    Storage::fake('local');
    Mail::fake();

    Subscriber::factory()->for($this->list, 'list')->create(['email' => 'in-one@example.com']);
    Subscriber::factory()->for($this->list, 'list')->create(['email' => 'in-two@example.com']);
    Subscriber::factory()->for($this->list, 'list')->unsubscribed()->create(['email' => 'out-one@example.com']);
    Subscriber::factory()->for($this->list, 'list')->unsubscribed()->create(['email' => 'out-two@example.com']);

    $segment = ListSegment::factory()->for($this->list, 'list')->create(['name' => 'Confirmed only']);
    $segment->conditions()->create([
        'customer_id' => $this->customer->id,
        'type' => 'status',
        'operator' => 'is',
        'value' => 'confirmed',
        'sort_order' => 0,
    ]);

    // Sync queue: calling the action runs RunExportJob end to end.
    segmentsPage()->callTableAction('export', $segment, data: [
        'columns' => ['email'],
        'format' => 'csv',
    ]);

    $export = ExportJob::query()->latest('id')->firstOrFail();

    expect($export->status)->toBe(ExportJob::STATUS_COMPLETED)
        ->and($export->total_rows)->toBe(2)
        ->and($export->processed_rows)->toBe(2);

    $csv = Storage::disk('local')->get((string) $export->file_path);

    expect($csv)->toContain('in-one@example.com')
        ->and($csv)->toContain('in-two@example.com')
        ->and($csv)->not->toContain('out-one@example.com')
        ->and($csv)->not->toContain('out-two@example.com')
        // Header + 2 member rows, nothing else.
        ->and(count(array_filter(preg_split('/\R/', trim($csv)))))->toBe(3);
});

it('never exports another tenant\'s segment', function (): void {
    Queue::fake();

    $foreignList = EmailList::factory()->create();
    $foreignSegment = ListSegment::factory()->for($foreignList, 'list')->create();

    segmentsPage()->assertCanNotSeeTableRecords([$foreignSegment]);

    $this->get(EmailListResource::getUrl('segments', ['record' => $foreignList], panel: 'customer'))
        ->assertNotFound();

    expect(ExportJob::query()->count())->toBe(0);
    Queue::assertNotPushed(RunExportJob::class);
});

it('offers segments as picker options for later campaign targeting (F-2.2.10-014)', function (): void {
    $segment = ListSegment::factory()->for($this->list, 'list')->create(['name' => 'Pick me']);

    expect(ListSegment::pickerOptions($this->list))->toBe([$segment->id => 'Pick me']);
});

it('does not show another tenant\'s segments', function (): void {
    $foreignList = EmailList::factory()->create();
    $foreignSegment = ListSegment::factory()->for($foreignList, 'list')->create();

    segmentsPage()->assertCanNotSeeTableRecords([$foreignSegment]);

    $this->get(EmailListResource::getUrl('segments', ['record' => $foreignList], panel: 'customer'))
        ->assertNotFound();
});
