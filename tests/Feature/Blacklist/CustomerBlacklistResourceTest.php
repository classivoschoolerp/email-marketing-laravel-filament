<?php

declare(strict_types=1);

use App\Filament\Customer\Resources\BlacklistEmailResource;
use App\Filament\Customer\Resources\BlacklistEmailResource\Pages\ListBlacklistEmails;
use App\Models\Customer;
use App\Models\GlobalBlacklistEmail;
use App\Settings\BlacklistSettings;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * WP-16 §2.9.10 — customer blacklist resource: index columns, add single,
 * bulk paste, remove (incl. D-28 bulk fencing), search/filter, queued CSV
 * import/export, blacklist.manage gate, isolation-vs-shared visibility.
 */

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);
});

afterEach(fn () => app(CurrentCustomer::class)->set(null));

function wp16_isolated(bool $isolated = true): void
{
    $settings = app(BlacklistSettings::class);
    $settings->shared_across_customers = ! $isolated;
    $settings->save();
}

it('renders the blacklist index with email, reason, date and source (F-2.9.10-001)', function (): void {
    $entry = GlobalBlacklistEmail::query()->create([
        'email' => 'bad@example.com',
        'reason' => 'spam trap',
        'customer_id' => $this->customer->id,
    ]);

    $this->get(BlacklistEmailResource::getUrl('index', panel: 'customer'))->assertOk();

    Livewire::test(ListBlacklistEmails::class)
        ->assertTableColumnExists('email')
        ->assertTableColumnExists('reason')
        ->assertTableColumnExists('source')
        ->assertTableColumnExists('created_at')
        ->assertCanSeeTableRecords([$entry]);
});

it('denies the module when the blacklist.manage group key is off', function (): void {
    $this->customer->permissionOverrides()->create(['key' => 'blacklist.manage', 'allowed' => false]);

    $this->get(BlacklistEmailResource::getUrl('index', panel: 'customer'))->assertForbidden();
});

it('adds a single address and a whole domain (F-2.9.10-002)', function (): void {
    Livewire::test(ListBlacklistEmails::class)
        ->callAction('create', data: [
            'email' => 'Manual@Example.com',
            'is_domain' => false,
            'reason' => 'manual add',
        ])
        ->assertHasNoActionErrors();

    Livewire::test(ListBlacklistEmails::class)
        ->callAction('create', data: [
            'email' => '@spammy.example',
            'is_domain' => true,
            'reason' => null,
        ])
        ->assertHasNoActionErrors();

    $exact = GlobalBlacklistEmail::query()->where('email', 'manual@example.com')->firstOrFail();
    expect($exact->customer_id)->toBe($this->customer->id)
        ->and($exact->source)->toBe(GlobalBlacklistEmail::SOURCE_MANUAL)
        ->and((bool) $exact->is_domain)->toBeFalse();

    $domain = GlobalBlacklistEmail::query()->where('email', 'spammy.example')->firstOrFail();
    expect((bool) $domain->is_domain)->toBeTrue();
});

it('bulk pastes addresses, skipping invalid and duplicate lines (F-2.9.10-002)', function (): void {
    GlobalBlacklistEmail::query()->create(['email' => 'dupe@example.com', 'customer_id' => $this->customer->id]);

    Livewire::test(ListBlacklistEmails::class)
        ->callAction('bulkPaste', data: [
            'emails' => "one@example.com, two@example.com;\nnot-an-email\ndupe@example.com ONE@example.com",
            'reason' => 'pasted',
        ])
        ->assertHasNoActionErrors();

    expect(GlobalBlacklistEmail::query()->count())->toBe(3)
        ->and(GlobalBlacklistEmail::query()->where('email', 'one@example.com')->value('reason'))->toBe('pasted');
});

it('removes entries and fences bulk removal to own rows (F-2.9.10-004, D-28)', function (): void {
    wp16_isolated(false); // shared mode → foreign rows visible

    $own = GlobalBlacklistEmail::query()->create(['email' => 'mine@example.com', 'customer_id' => $this->customer->id]);
    $foreign = GlobalBlacklistEmail::query()->create([
        'email' => 'theirs@example.com',
        'customer_id' => Customer::factory()->create()->id,
    ]);

    Livewire::test(ListBlacklistEmails::class)
        ->callTableAction('delete', $own)
        ->assertHasNoTableActionErrors();

    expect(GlobalBlacklistEmail::query()->where('email', 'mine@example.com')->exists())->toBeFalse();

    // Bulk remove selects both — only the own row may go (D-28 ->using()).
    $own2 = GlobalBlacklistEmail::query()->create(['email' => 'mine2@example.com', 'customer_id' => $this->customer->id]);

    Livewire::test(ListBlacklistEmails::class)
        ->callTableBulkAction('delete', [$own2, $foreign]);

    expect(GlobalBlacklistEmail::query()->where('email', 'mine2@example.com')->exists())->toBeFalse()
        ->and(GlobalBlacklistEmail::query()->where('email', 'theirs@example.com')->exists())->toBeTrue();
});

it('searches and filters the blacklist (F-2.9.10-005)', function (): void {
    $spam = GlobalBlacklistEmail::query()->create([
        'email' => 'spam@example.com',
        'reason' => 'complaints',
        'source' => 'fbl',
        'customer_id' => $this->customer->id,
    ]);
    $manual = GlobalBlacklistEmail::query()->create([
        'email' => 'other@example.net',
        'customer_id' => $this->customer->id,
    ]);

    Livewire::test(ListBlacklistEmails::class)
        ->searchTable('spam@')
        ->assertCanSeeTableRecords([$spam])
        ->assertCanNotSeeTableRecords([$manual])
        ->searchTable('')
        ->filterTable('source', 'fbl')
        ->assertCanSeeTableRecords([$spam])
        ->assertCanNotSeeTableRecords([$manual]);
});

it('shows only own rows in isolation mode and everything in shared mode', function (): void {
    $own = GlobalBlacklistEmail::query()->create(['email' => 'mine@example.com', 'customer_id' => $this->customer->id]);
    $foreign = GlobalBlacklistEmail::query()->create([
        'email' => 'theirs@example.com',
        'customer_id' => Customer::factory()->create()->id,
    ]);
    $global = GlobalBlacklistEmail::query()->create(['email' => 'global@example.com']);

    wp16_isolated(true);

    Livewire::test(ListBlacklistEmails::class)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$foreign, $global]);

    wp16_isolated(false);

    Livewire::test(ListBlacklistEmails::class)
        ->assertCanSeeTableRecords([$own, $foreign, $global]);
});

it('imports a CSV through the queued job stamping the tenant (F-2.9.10-003)', function (): void {
    Storage::fake('local');

    $path = 'customers/'.$this->customer->uid.'/imports/blacklist.csv';
    Storage::disk('local')->put($path, "import1@example.com,bad actor\nimport2@example.com\nnot-an-email\n");

    \App\Jobs\Blacklist\ImportBlacklistCsvJob::dispatch('op-1', $path, (int) $this->customer->id);

    expect(GlobalBlacklistEmail::query()->count())->toBe(2);

    $row = GlobalBlacklistEmail::query()->where('email', 'import1@example.com')->firstOrFail();
    expect($row->reason)->toBe('bad actor')
        ->and($row->source)->toBe(GlobalBlacklistEmail::SOURCE_IMPORT)
        ->and($row->customer_id)->toBe($this->customer->id);

    // Uploaded file is cleaned up after the run.
    expect(Storage::disk('local')->exists($path))->toBeFalse();
});

it('exports the visible rows to a downloadable CSV (F-2.9.10-003)', function (): void {
    Storage::fake('local');
    wp16_isolated(true);

    GlobalBlacklistEmail::query()->create(['email' => 'mine@example.com', 'customer_id' => $this->customer->id]);
    GlobalBlacklistEmail::query()->create([
        'email' => 'theirs@example.com',
        'customer_id' => Customer::factory()->create()->id,
    ]);

    Livewire::test(ListBlacklistEmails::class)
        ->callAction('export')
        ->assertHasNoActionErrors();

    $path = 'customers/'.$this->customer->uid.'/exports/blacklist.csv';
    expect(Storage::disk('local')->exists($path))->toBeTrue();

    $csv = (string) Storage::disk('local')->get($path);
    expect($csv)->toContain('mine@example.com')
        ->not->toContain('theirs@example.com');

    $this->get(route('blacklist.export.download'))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');
});

it('serves each tenant only its own export file', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('customers/'.$this->customer->uid.'/exports/blacklist.csv', 'email');

    // Another customer resolves their OWN (missing) file — never this one.
    $intruder = Customer::factory()->create();
    $this->actingAs($intruder, 'customer');
    app(CurrentCustomer::class)->set($intruder);

    $this->get(route('blacklist.export.download'))->assertNotFound();
});
