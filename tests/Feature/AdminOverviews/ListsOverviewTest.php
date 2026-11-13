<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\ListOverviewResource;
use App\Filament\Admin\Resources\ListOverviewResource\Pages\ListListOverviews;
use App\Jobs\Exports\AdminListsExportJob;
use App\Jobs\Lists\PurgeListJob;
use App\Mail\ExportReadyMail;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Subscriber;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\AdminTestHelpers;

// WP-18 — §3.6 lists overview: global index with the customer column,
// detail drill, disable/delete, filters and the queued CSV export.

beforeEach(function (): void {
    $this->admin = AdminTestHelpers::actingAdmin(['lists.view', 'lists.update', 'lists.delete']);

    $this->customerA = Customer::factory()->create(['email' => 'alice@tenant.test']);
    $this->customerB = Customer::factory()->create(['email' => 'bob@tenant.test']);

    $this->listA = EmailList::factory()->create(['customer_id' => $this->customerA->id, 'name' => 'Alpha list']);
    $this->listB = EmailList::factory()->create(['customer_id' => $this->customerB->id, 'name' => 'Beta list']);

    Subscriber::factory()->count(3)->create(['list_id' => $this->listA->id, 'customer_id' => $this->customerA->id]);
    Subscriber::factory()->create(['list_id' => $this->listB->id, 'customer_id' => $this->customerB->id]);
});

// F-3.6-001 — global index across tenants with the customer column.
it('lists every customer list with its owner', function (): void {
    Livewire::test(ListListOverviews::class)
        ->assertCanSeeTableRecords([$this->listA, $this->listB])
        ->assertSee('Alpha list')
        ->assertSee('Beta list')
        ->assertSee('alice@tenant.test')
        ->assertSee('bob@tenant.test');
});

// F-3.6-002 — read-only detail drill: infolist stats + subscribers relation.
it('shows list details, stats and subscribers', function (): void {
    Subscriber::factory()->unsubscribed()->create(['list_id' => $this->listA->id, 'customer_id' => $this->customerA->id]);

    $this->get(ListOverviewResource::getUrl('view', ['record' => $this->listA]))
        ->assertOk()
        ->assertSee('Alpha list')
        ->assertSee('alice@tenant.test')
        ->assertSee(__('dashboards.lists_overview.detail.stats'));
});

// F-3.6-003 — disable/enable = the visible flag.
it('disables and re-enables any list', function (): void {
    Livewire::test(ListListOverviews::class)
        ->callTableAction('toggleVisibility', $this->listA);

    expect($this->listA->refresh()->visible)->toBeFalse();

    Livewire::test(ListListOverviews::class)
        ->callTableAction('toggleVisibility', $this->listA);

    expect($this->listA->refresh()->visible)->toBeTrue();
});

// F-3.6-003 — delete with typed confirmation → soft delete + queued purge.
it('deletes any list after typed confirmation', function (): void {
    Queue::fake();

    Livewire::test(ListListOverviews::class)
        ->callTableAction('deleteList', $this->listB, data: ['confirm_name' => 'WRONG'])
        ->assertHasTableActionErrors(['confirm_name']);

    expect($this->listB->refresh()->trashed())->toBeFalse();

    Livewire::test(ListListOverviews::class)
        ->callTableAction('deleteList', $this->listB, data: ['confirm_name' => 'Beta list']);

    expect(EmailList::withoutTenancy()->withTrashed()->find($this->listB->id)->trashed())->toBeTrue();
    Queue::assertPushed(PurgeListJob::class);
});

// F-3.6-004 — filters by customer and size.
it('filters by customer and by list size', function (): void {
    Livewire::test(ListListOverviews::class)
        ->filterTable('customer_id', $this->customerA->id)
        ->assertCanSeeTableRecords([$this->listA])
        ->assertCanNotSeeTableRecords([$this->listB])
        ->resetTableFilters()
        ->filterTable('size', ['min_subscribers' => 2])
        ->assertCanSeeTableRecords([$this->listA])
        ->assertCanNotSeeTableRecords([$this->listB]);
});

// F-3.6-005 — queued CSV export with a signed, permission-checked download.
it('exports the lists overview as a queued csv with a signed download', function (): void {
    Storage::fake('local');
    Mail::fake();

    Queue::fake();
    Livewire::test(ListListOverviews::class)->callTableAction('export');
    Queue::assertPushed(AdminListsExportJob::class);

    // Run the job body inline.
    $token = str_repeat('t', 32);
    (new AdminListsExportJob($this->admin->id, $token))->handle();

    Storage::disk('local')->assertExists('admin/overview-exports/lists-'.$token.'.csv');

    $csv = Storage::disk('local')->get('admin/overview-exports/lists-'.$token.'.csv');

    expect($csv)->toContain('Alpha list')
        ->toContain('alice@tenant.test')
        ->toContain('Beta list');

    $url = null;
    // ExportReadyMail is ShouldQueue → the fake records it as queued.
    Mail::assertQueued(ExportReadyMail::class, function (ExportReadyMail $mail) use (&$url): bool {
        $url = $mail->downloadUrl;

        return str_contains($url, 'admin-downloads/lists-overview/');
    });

    // Signed URL works for the permitted admin…
    $this->get($url)->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

    // …but never for a guest or the customer guard (redirected to the admin
    // login by AuthenticateAdmin), nor an admin without lists.view (403).
    auth('admin')->logout();
    $this->get($url)->assertRedirect();

    $this->actingAs(Customer::factory()->create(), 'customer');
    $this->get($url)->assertRedirect();

    AdminTestHelpers::actingAdmin(); // admin guard, no permissions
    $this->get($url)->assertForbidden();
});

// Permission gate: no lists.view → no overview.
it('denies the lists overview without lists.view', function (): void {
    AdminTestHelpers::actingAdmin();

    $this->get(ListOverviewResource::getUrl('index'))->assertForbidden();
});
