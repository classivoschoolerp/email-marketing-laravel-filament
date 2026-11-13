<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\GlobalBlacklistEmailResource;
use App\Filament\Admin\Resources\GlobalBlacklistEmailResource\Pages\ListGlobalBlacklistEmails;
use App\Jobs\Blacklist\ExportBlacklistCsvJob;
use App\Jobs\Blacklist\ImportBlacklistCsvJob;
use App\Models\Customer;
use App\Models\GlobalBlacklistEmail;
use App\Models\User;
use App\Settings\BlacklistSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\AdminTestHelpers;

/*
 * WP-16 §3.17 — admin global blacklist: index incl. customer column, add /
 * bulk paste / queued import / queued export, delete false positives,
 * search & filter, domain rows, the regex rule tester, permission gate.
 */

it('renders the global index with email, reason, source, date and customer (F-3.17-001)', function (): void {
    AdminTestHelpers::actingAdmin(['blacklist.manage']);

    $customer = Customer::factory()->create();
    $owned = GlobalBlacklistEmail::query()->create(['email' => 'owned@example.com', 'customer_id' => $customer->id]);
    $global = GlobalBlacklistEmail::query()->create(['email' => 'global@example.com']);

    $this->get(GlobalBlacklistEmailResource::getUrl('index', panel: 'admin'))->assertOk();

    Livewire::test(ListGlobalBlacklistEmails::class)
        ->assertTableColumnExists('email')
        ->assertTableColumnExists('reason')
        ->assertTableColumnExists('source')
        ->assertTableColumnExists('customer.email')
        ->assertTableColumnExists('created_at')
        ->assertCanSeeTableRecords([$owned, $global]);
});

it('denies the module to admins without blacklist.manage and to customer-guard users', function (): void {
    $this->actingAs(User::factory()->create(), 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->get(GlobalBlacklistEmailResource::getUrl('index', panel: 'admin'))->assertForbidden();

    auth('admin')->logout();
    $this->actingAs(Customer::factory()->create(), 'customer');

    expect($this->get(GlobalBlacklistEmailResource::getUrl('index', panel: 'admin'))->status())->not->toBe(200);
});

it('adds single addresses, domains and bulk pastes as install-wide rows (F-3.17-002/006)', function (): void {
    AdminTestHelpers::actingAdmin(['blacklist.manage']);

    Livewire::test(ListGlobalBlacklistEmails::class)
        ->callAction('create', data: [
            'email' => 'Admin@Example.com',
            'is_domain' => false,
            'reason' => 'admin add',
        ])
        ->assertHasNoActionErrors()
        ->callAction('create', data: [
            'email' => 'blocked-domain.example',
            'is_domain' => true,
            'reason' => null,
        ])
        ->assertHasNoActionErrors()
        ->callAction('bulkPaste', data: [
            'emails' => "p1@example.com p2@example.com\ninvalid",
            'reason' => 'pasted',
        ])
        ->assertHasNoActionErrors();

    expect(GlobalBlacklistEmail::query()->count())->toBe(4)
        ->and(GlobalBlacklistEmail::query()->whereNull('customer_id')->count())->toBe(4)
        ->and((bool) GlobalBlacklistEmail::query()->where('email', 'blocked-domain.example')->value('is_domain'))->toBeTrue();
});

it('deletes false positives incl. bulk (F-3.17-003)', function (): void {
    AdminTestHelpers::actingAdmin(['blacklist.manage']);

    $a = GlobalBlacklistEmail::query()->create(['email' => 'fp1@example.com']);
    $b = GlobalBlacklistEmail::query()->create(['email' => 'fp2@example.com']);

    Livewire::test(ListGlobalBlacklistEmails::class)
        ->callTableAction('delete', $a)
        ->assertHasNoTableActionErrors()
        ->callTableBulkAction('delete', [$b]);

    expect(GlobalBlacklistEmail::query()->count())->toBe(0);
});

it('searches and filters globally (F-3.17-004)', function (): void {
    AdminTestHelpers::actingAdmin(['blacklist.manage']);

    $bounce = GlobalBlacklistEmail::query()->create(['email' => 'b@example.com', 'source' => 'bounce']);
    $manual = GlobalBlacklistEmail::query()->create(['email' => 'm@example.com']);

    Livewire::test(ListGlobalBlacklistEmails::class)
        ->filterTable('source', 'bounce')
        ->assertCanSeeTableRecords([$bounce])
        ->assertCanNotSeeTableRecords([$manual]);
});

it('imports and exports install-wide CSVs through queued jobs (F-3.17-002)', function (): void {
    Storage::fake('local');
    AdminTestHelpers::actingAdmin(['blacklist.manage']);

    $path = 'admin/imports/blacklist.csv';
    Storage::disk('local')->put($path, "gi1@example.com,imported\ngi2@example.com\n");

    ImportBlacklistCsvJob::dispatch('op-a1', $path, null);

    expect(GlobalBlacklistEmail::query()->whereNull('customer_id')->count())->toBe(2);

    Livewire::test(ListGlobalBlacklistEmails::class)
        ->callAction('export')
        ->assertHasNoActionErrors();

    expect(Storage::disk('local')->exists(ExportBlacklistCsvJob::ADMIN_PATH))->toBeTrue();

    $csv = (string) Storage::disk('local')->get(ExportBlacklistCsvJob::ADMIN_PATH);
    expect($csv)->toContain('gi1@example.com')->toContain('gi2@example.com');

    $this->get(route('admin.blacklist.export.download'))->assertOk();
});

it('surfaces the regex rules through the rule tester (F-3.17-005)', function (): void {
    AdminTestHelpers::actingAdmin(['blacklist.manage']);

    $settings = app(BlacklistSettings::class);
    $settings->regex_rules = ['/^trap-/i'];
    $settings->save();

    GlobalBlacklistEmail::query()->create(['email' => 'exact@example.com']);
    GlobalBlacklistEmail::query()->create(['email' => 'domain.example', 'is_domain' => true]);

    $page = fn () => Livewire::test(ListGlobalBlacklistEmails::class);

    $page()->callAction('testRules', data: ['email' => 'exact@example.com'])
        ->assertNotified(__('blacklist.tester.match_exact'));

    $page()->callAction('testRules', data: ['email' => 'anyone@domain.example'])
        ->assertNotified(__('blacklist.tester.match_domain'));

    $page()->callAction('testRules', data: ['email' => 'trap-1@clean.example'])
        ->assertNotified(__('blacklist.tester.match_regex'));

    $page()->callAction('testRules', data: ['email' => 'clean@clean.example'])
        ->assertNotified(__('blacklist.tester.no_match'));
});
