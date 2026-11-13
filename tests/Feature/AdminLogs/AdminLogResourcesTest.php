<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\CampaignBounceLogResource;
use App\Filament\Admin\Resources\CampaignBounceLogResource\Pages\ListCampaignBounceLogs;
use App\Filament\Admin\Resources\CampaignComplaintLogResource;
use App\Filament\Admin\Resources\CampaignComplaintLogResource\Pages\ListCampaignComplaintLogs;
use App\Filament\Admin\Resources\CampaignDeliveryLogResource;
use App\Filament\Admin\Resources\CampaignDeliveryLogResource\Pages\ListCampaignDeliveryLogs;
use App\Jobs\Reports\RunAdminLogExportJob;
use App\Mail\ExportReadyMail;
use App\Models\CampaignBounceLog;
use App\Models\CampaignComplaintLog;
use App\Models\CampaignDeliveryLog;
use App\Models\Customer;
use App\Services\Reports\AdminLogExporter;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Support\AdminTestHelpers;
use Tests\Support\CampaignReportFixture;

// §3.27 F-3.27-001/002/003/015 — global admin log resources: all-tenant
// rows, filters, read-only, queued CSV export with signed download.

// F-3.27-001 — delivery logs across tenants with filters.
it('lists delivery logs across all tenants with status and customer filters', function (): void {
    $a = CampaignReportFixture::build();
    $b = CampaignReportFixture::build();

    AdminTestHelpers::actingAdmin(['logs.view']);

    $this->get(CampaignDeliveryLogResource::getUrl('index', panel: 'admin'))->assertOk();

    $aFailed = CampaignDeliveryLog::query()
        ->where('campaign_id', $a->campaign->id)->where('status', 'failed')->first();
    $bFailed = CampaignDeliveryLog::query()
        ->where('campaign_id', $b->campaign->id)->where('status', 'failed')->first();

    Livewire::test(ListCampaignDeliveryLogs::class)
        ->assertCanSeeTableRecords([$aFailed, $bFailed]) // both tenants (global view)
        ->filterTable('status', 'failed')
        ->assertCanSeeTableRecords([$aFailed, $bFailed])
        ->assertCountTableRecords(2)
        ->filterTable('customer', $a->customer->id)
        ->assertCanSeeTableRecords([$aFailed])
        ->assertCanNotSeeTableRecords([$bFailed])
        ->resetTableFilters()
        ->filterTable('delivery_server_id', $a->server->id)
        ->assertCountTableRecords(2); // s1 sent + s5 failed used server A
});

// F-3.27-002 — bounce logs with type filter.
it('lists bounce logs with the type filter', function (): void {
    $fixture = CampaignReportFixture::build();

    AdminTestHelpers::actingAdmin(['logs.view']);

    $this->get(CampaignBounceLogResource::getUrl('index', panel: 'admin'))->assertOk();

    $hard = CampaignBounceLog::query()->where('bounce_type', 'hard')->first();
    $soft = CampaignBounceLog::query()->where('bounce_type', 'soft')->first();
    $internal = CampaignBounceLog::query()->where('bounce_type', 'internal')->first();

    Livewire::test(ListCampaignBounceLogs::class)
        ->assertCanSeeTableRecords([$hard, $soft, $internal])
        ->assertSee('550 5.1.1 user unknown')
        ->filterTable('bounce_type', 'hard')
        ->assertCanSeeTableRecords([$hard])
        ->assertCanNotSeeTableRecords([$soft, $internal]);
});

// F-3.27-003 — complaint/FBL logs.
it('lists complaint logs', function (): void {
    $fixture = CampaignReportFixture::build();

    AdminTestHelpers::actingAdmin(['logs.view']);

    $this->get(CampaignComplaintLogResource::getUrl('index', panel: 'admin'))->assertOk();

    Livewire::test(ListCampaignComplaintLogs::class)
        ->assertCanSeeTableRecords(CampaignComplaintLog::query()->get()->all())
        ->assertSee('s4@fixture.test')
        ->assertSee('abuse');
});

// Denials: no permission → 403.
it('denies the log resources to unpermitted admins', function (): void {
    AdminTestHelpers::actingAdmin(); // no permissions

    $this->get(CampaignDeliveryLogResource::getUrl('index', panel: 'admin'))->assertForbidden();
    $this->get(CampaignBounceLogResource::getUrl('index', panel: 'admin'))->assertForbidden();
    $this->get(CampaignComplaintLogResource::getUrl('index', panel: 'admin'))->assertForbidden();
});

// A customer session never reaches the admin panel (bounced to admin login),
// and the policies deny the customer guard outright.
it('denies the log resources to customer-guard users', function (): void {
    $customer = Customer::factory()->create();

    expect(Gate::forUser($customer)->allows('viewAny', CampaignDeliveryLog::class))->toBeFalse()
        ->and(Gate::forUser($customer)->allows('viewAny', CampaignBounceLog::class))->toBeFalse()
        ->and(Gate::forUser($customer)->allows('viewAny', CampaignComplaintLog::class))->toBeFalse();

    $this->actingAs($customer, 'customer');
    $this->get(CampaignDeliveryLogResource::getUrl('index', panel: 'admin'))->assertRedirect();
});

// F-3.27-015 — export logs: queued job, exact CSV, signed download.
it('queues a delivery log export from the header action', function (): void {
    CampaignReportFixture::build();

    AdminTestHelpers::actingAdmin(['logs.view']);

    Queue::fake();

    Livewire::test(ListCampaignDeliveryLogs::class)
        ->callTableAction('export_log');

    Queue::assertPushedOn('exports', RunAdminLogExportJob::class);
});

it('writes the login log export csv and serves it via the signed route', function (): void {
    Mail::fake();

    $fixture = CampaignReportFixture::build();
    $admin = AdminTestHelpers::actingAdmin(['logs.view']);

    (new RunAdminLogExportJob('delivery', $admin->id, str_repeat('t', 32)))
        ->handle(app(AdminLogExporter::class));

    Mail::assertQueued(ExportReadyMail::class);

    $path = AdminLogExporter::relativePath('delivery', str_repeat('t', 32));
    $csv = (string) Storage::disk('local')->get($path);

    expect(substr_count($csv, "\n"))->toBeGreaterThanOrEqual(5) // header + 5 rows
        ->and($csv)->toContain('s1@fixture.test')
        ->and($csv)->toContain('msg-id-0001')
        ->and($csv)->toContain('"SMTP 550 mailbox unavailable"');

    $url = URL::temporarySignedRoute('reports.admin-logs.download', now()->addDay(), [
        'type' => 'delivery',
        'token' => str_repeat('t', 32),
    ]);

    $this->get($url)->assertOk()->assertDownload('delivery-log-export.csv');

    // An admin without logs.view cannot use the same signed link.
    AdminTestHelpers::actingAdmin();
    $this->get($url)->assertForbidden();
});
