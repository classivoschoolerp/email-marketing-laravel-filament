<?php

declare(strict_types=1);

use App\Enums\CampaignStatus;
use App\Filament\Customer\Resources\SuppressionListResource;
use App\Filament\Customer\Resources\SuppressionListResource\Pages\CreateSuppressionList;
use App\Filament\Customer\Resources\SuppressionListResource\Pages\ListSuppressionLists;
use App\Jobs\Suppression\ExportSuppressionListJob;
use App\Jobs\Suppression\ImportSuppressionCsvJob;
use App\Jobs\Suppression\ImportSuppressionFromListJob;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\DeliveryServer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Models\SuppressionList;
use App\Sending\DriverRegistry;
use App\Services\Campaigns\CampaignRecipients;
use App\Services\Suppression\SuppressionListEmails;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Fakes\RecordingSendingDriver;

/*
 * WP-16 §2.9.11 — suppression lists: named lists, add manually / import CSV
 * / import from list, counts + export, campaign attachment subtracted in
 * CampaignRecipients (count + pipeline), suppression.manage gate, tenancy.
 */

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);
});

afterEach(fn () => app(CurrentCustomer::class)->set(null));

it('creates named suppression lists from the resource (F-2.9.11-001)', function (): void {
    $this->get(SuppressionListResource::getUrl('index', panel: 'customer'))->assertOk();

    Livewire::test(CreateSuppressionList::class)
        ->fillForm(['name' => 'Competitors', 'description' => 'never mail these'])
        ->call('create')
        ->assertHasNoFormErrors();

    $list = SuppressionList::query()->firstOrFail();
    expect($list->name)->toBe('Competitors')
        ->and($list->customer_id)->toBe($this->customer->id)
        ->and($list->uid)->not->toBeNull();
});

it('denies the module when suppression.manage is off and fences tenants', function (): void {
    $foreign = SuppressionList::withoutTenancy()->create([
        'customer_id' => Customer::factory()->create()->id,
        'name' => 'Foreign',
    ]);
    $own = SuppressionList::query()->create(['customer_id' => $this->customer->id, 'name' => 'Mine']);

    Livewire::test(ListSuppressionLists::class)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$foreign]);

    $this->customer->permissionOverrides()->create(['key' => 'suppression.manage', 'allowed' => false]);

    $this->get(SuppressionListResource::getUrl('index', panel: 'customer'))->assertForbidden();
});

it('adds emails manually with parsing, dedupe and a maintained count (F-2.9.11-002/005)', function (): void {
    $list = SuppressionList::query()->create(['customer_id' => $this->customer->id, 'name' => 'Manual']);

    Livewire::test(ListSuppressionLists::class)
        ->callTableAction('addEmails', $list, data: [
            'emails' => "a@example.com\nb@example.com, not-an-email A@example.com",
        ])
        ->assertHasNoTableActionErrors();

    expect($list->refresh()->emails_count)->toBe(2)
        ->and($list->emails()->pluck('email')->sort()->values()->all())
        ->toBe(['a@example.com', 'b@example.com']);
});

it('imports a CSV through the queued job (F-2.9.11-002)', function (): void {
    Storage::fake('local');

    $list = SuppressionList::query()->create(['customer_id' => $this->customer->id, 'name' => 'CSV']);

    $path = 'customers/'.$this->customer->uid.'/imports/suppress.csv';
    Storage::disk('local')->put($path, "c@example.com\nd@example.com\nbroken-line\nc@example.com\n");

    ImportSuppressionCsvJob::dispatch('op-s1', (int) $list->id, $path);

    expect($list->refresh()->emails_count)->toBe(2)
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

it('imports the members of one of the customer\'s lists (F-2.9.11-002)', function (): void {
    $emailList = EmailList::factory()->for($this->customer)->create();
    Subscriber::factory()->for($this->customer)->for($emailList, 'list')->create(['email' => 'm1@example.com']);
    Subscriber::factory()->for($this->customer)->for($emailList, 'list')->create(['email' => 'm2@example.com']);

    $list = SuppressionList::query()->create(['customer_id' => $this->customer->id, 'name' => 'From list']);

    ImportSuppressionFromListJob::dispatch('op-s2', (int) $list->id, (int) $emailList->id);

    expect($list->refresh()->emails_count)->toBe(2);

    // Foreign EmailList → refused by the job's cross-tenant re-check.
    $foreignList = EmailList::factory()->create();
    Subscriber::factory()->create([
        'list_id' => $foreignList->id,
        'customer_id' => $foreignList->customer_id,
        'email' => 'foreign@example.com',
    ]);

    ImportSuppressionFromListJob::dispatch('op-s3', (int) $list->id, (int) $foreignList->id);

    expect($list->refresh()->emails_count)->toBe(2);
});

it('exports a suppression list to a downloadable CSV (F-2.9.11-005)', function (): void {
    Storage::fake('local');

    $list = SuppressionList::query()->create(['customer_id' => $this->customer->id, 'name' => 'Export']);
    app(SuppressionListEmails::class)->addMany($list, ['x@example.com', 'y@example.com']);

    ExportSuppressionListJob::dispatch('op-s4', (int) $list->id);

    $path = ExportSuppressionListJob::pathFor($list);
    expect(Storage::disk('local')->exists($path))->toBeTrue()
        ->and((string) Storage::disk('local')->get($path))->toContain('x@example.com');

    $this->get(route('suppression.export.download', ['uid' => $list->uid]))->assertOk();

    // Another customer may not download it.
    $intruder = Customer::factory()->create();
    $this->actingAs($intruder, 'customer');
    app(CurrentCustomer::class)->set($intruder);

    $this->get(route('suppression.export.download', ['uid' => $list->uid]))->assertForbidden();
});

// ---------------------------------------------------------------------------
// F-2.9.11-004 — attach to campaigns → CampaignRecipients subtraction
// ---------------------------------------------------------------------------
it('subtracts attached suppression lists from the recipient count', function (): void {
    $emailList = EmailList::factory()->for($this->customer)->create();
    Subscriber::factory()->for($this->customer)->for($emailList, 'list')->create(['email' => 'stay@example.com']);
    Subscriber::factory()->for($this->customer)->for($emailList, 'list')->create(['email' => 'drop@example.com']);

    $suppression = SuppressionList::query()->create(['customer_id' => $this->customer->id, 'name' => 'Drops']);
    app(SuppressionListEmails::class)->addMany($suppression, ['drop@example.com']);

    $campaign = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $emailList->id,
    ]);

    expect(app(CampaignRecipients::class)->count($campaign))->toBe(2);

    $campaign->forceFill(['suppression_list_ids' => [$suppression->id]])->saveQuietly();

    expect(app(CampaignRecipients::class)->count($campaign->refresh()))->toBe(1);
});

it('ignores a foreign tenant\'s suppression list id in the json column', function (): void {
    $emailList = EmailList::factory()->for($this->customer)->create();
    Subscriber::factory()->for($this->customer)->for($emailList, 'list')->create(['email' => 'kept@example.com']);

    $other = Customer::factory()->create();
    $foreign = SuppressionList::withoutTenancy()->create(['customer_id' => $other->id, 'name' => 'Foreign']);
    app(CurrentCustomer::class)->runAs($other, fn () => app(SuppressionListEmails::class)
        ->addMany($foreign->refresh(), ['kept@example.com']));

    $campaign = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $emailList->id,
    ]);
    $campaign->forceFill(['suppression_list_ids' => [$foreign->id]])->saveQuietly();

    expect(app(CampaignRecipients::class)->count($campaign->refresh()))->toBe(1);
});

it('never sends to suppressed subscribers through the real pipeline', function (): void {
    config(['app.url' => 'http://localhost']);
    app(CurrentCustomer::class)->set(null);

    $emailList = EmailList::factory()->create([
        'company_name' => 'Acme Inc.',
        'company_address_1' => '1 Acme Way',
        'company_city' => 'Springfield',
        'company_country' => 'US',
    ]);

    Subscriber::factory()->create([
        'list_id' => $emailList->id,
        'customer_id' => $emailList->customer_id,
        'email' => 'reachme@example.com',
    ]);
    Subscriber::factory()->create([
        'list_id' => $emailList->id,
        'customer_id' => $emailList->customer_id,
        'email' => 'suppressed@example.com',
    ]);

    $suppression = SuppressionList::withoutTenancy()->create([
        'customer_id' => $emailList->customer_id,
        'name' => 'Pipeline drops',
    ]);
    app(SuppressionListEmails::class)->addMany($suppression, ['suppressed@example.com']);

    DeliveryServer::factory()->create([
        'customer_id' => $emailList->customer_id,
        'type' => 'smtp',
    ]);

    $driver = new RecordingSendingDriver('smtp');
    app(DriverRegistry::class)->register('smtp', $driver);

    Campaign::factory()->create([
        'customer_id' => $emailList->customer_id,
        'email_list_id' => $emailList->id,
        'status' => CampaignStatus::Scheduled,
        'send_at' => Carbon::now()->subMinute(),
        'suppression_list_ids' => [$suppression->id],
    ]);

    $this->artisan('campaigns:send')->assertSuccessful();

    expect($driver->sent)->toHaveCount(1)
        ->and($driver->sent[0]['message']->firstRecipient())->toBe('reachme@example.com');
});
