<?php

declare(strict_types=1);

use App\Enums\CampaignStatus;
use App\Enums\DeliveryServerStatus;
use App\Filament\Customer\Resources\DeliveryServerResource\Pages\CreateDeliveryServer;
use App\Filament\Customer\Resources\DeliveryServerResource\Pages\EditDeliveryServer;
use App\Filament\Customer\Resources\DeliveryServerResource\Pages\ListDeliveryServers;
use App\Models\Campaign;
use App\Models\CampaignDeliveryLog;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\DeliveryServer;
use App\Models\DeliveryServerAssignment;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Sending\DriverRegistry;
use App\Sending\ServerPicker;
use App\Sending\ServerUsage;
use App\Services\Sending\DeliveryServerAssignments;
use App\Services\Sending\DeliveryServerQuota;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\Fakes\RecordingSendingDriver;

/*
 * F-2.9.2-038 "Assign to specific lists/campaigns only" — a per-server
 * restriction to an explicit set of the owning tenant's lists and/or
 * campaigns. NO rows = NO restriction (the sub_user_list_access convention).
 * Enforced inside ServerPicker, i.e. on the real send path, BEFORE quotas so
 * the D-50 pause-vs-next semantics are untouched.
 */

beforeEach(function (): void {
    $this->group = CustomerGroup::factory()->create();
    $this->customer = Customer::factory()->create(['customer_group_id' => $this->group->id]);
    app(CurrentCustomer::class)->set(null);
});

afterEach(function (): void {
    app(CurrentCustomer::class)->set(null);
});

function assignmentPicker(int $seed = 1): ServerPicker
{
    return new ServerPicker(
        app(ServerUsage::class),
        app(DeliveryServerQuota::class),
        new Randomizer(new Mt19937($seed)),
    );
}

function assignmentServer(Customer $customer, array $attributes = []): DeliveryServer
{
    return DeliveryServer::factory()->create(array_merge([
        'customer_id' => $customer->id,
        'type' => 'smtp',
        'status' => DeliveryServerStatus::Active,
        'probability' => 100,
    ], $attributes));
}

function assignmentList(Customer $customer, string $name = 'List'): EmailList
{
    return EmailList::factory()->create([
        'customer_id' => $customer->id,
        'name' => $name,
        'company_name' => 'Acme Inc.',
        'company_address_1' => '1 Acme Way',
        'company_city' => 'Springfield',
        'company_country' => 'US',
    ]);
}

// ---------------------------------------------------------------- semantics

it('F-2.9.2-038: treats a server with no assignment rows as unrestricted', function (): void {
    $server = assignmentServer($this->customer);
    $list = assignmentList($this->customer);
    $campaign = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $list->id,
    ]);

    $picked = assignmentPicker()->pickFor(
        $this->customer,
        campaignId: (int) $campaign->id,
        listIds: [(int) $list->id],
    );

    expect(app(DeliveryServerAssignments::class)->isRestricted($server))->toBeFalse()
        ->and($picked->server?->id)->toBe($server->id);
});

it('F-2.9.2-038: skips a campaign-assigned server when another campaign is sent', function (): void {
    $list = assignmentList($this->customer);
    $mine = Campaign::factory()->create(['customer_id' => $this->customer->id, 'email_list_id' => $list->id]);
    $other = Campaign::factory()->create(['customer_id' => $this->customer->id, 'email_list_id' => $list->id]);

    $restricted = assignmentServer($this->customer, ['name' => 'Restricted']);
    app(DeliveryServerAssignments::class)->sync($restricted, [], [(int) $mine->id]);

    // Its own campaign: eligible (it is the only server).
    expect(assignmentPicker()->pickFor($this->customer, campaignId: (int) $mine->id, listIds: [(int) $list->id])->server?->id)
        ->toBe($restricted->id);

    // A different campaign: skipped, and nothing else can serve it.
    $picked = assignmentPicker()->pickFor($this->customer, campaignId: (int) $other->id, listIds: [(int) $list->id]);

    expect($picked->server)->toBeNull()
        ->and($picked->shouldPause)->toBeFalse();
});

it('F-2.9.2-038: matches on the campaign target list too', function (): void {
    $allowed = assignmentList($this->customer, 'Allowed');
    $forbidden = assignmentList($this->customer, 'Forbidden');

    $restricted = assignmentServer($this->customer);
    app(DeliveryServerAssignments::class)->sync($restricted, [(int) $allowed->id], []);

    $onAllowed = Campaign::factory()->create(['customer_id' => $this->customer->id, 'email_list_id' => $allowed->id]);
    $onForbidden = Campaign::factory()->create(['customer_id' => $this->customer->id, 'email_list_id' => $forbidden->id]);

    expect(assignmentPicker()->pickFor($this->customer, campaignId: (int) $onAllowed->id, listIds: $onAllowed->targetListIds())->server?->id)
        ->toBe($restricted->id)
        ->and(assignmentPicker()->pickFor($this->customer, campaignId: (int) $onForbidden->id, listIds: $onForbidden->targetListIds())->server)
        ->toBeNull();
});

it('F-2.9.2-038: a restricted server never sends traffic that carries no campaign context', function (): void {
    $list = assignmentList($this->customer);
    $campaign = Campaign::factory()->create(['customer_id' => $this->customer->id, 'email_list_id' => $list->id]);

    $restricted = assignmentServer($this->customer);
    app(DeliveryServerAssignments::class)->sync($restricted, [], [(int) $campaign->id]);

    // Automation / transactional path: pickFor() without campaign context.
    expect(assignmentPicker()->pickFor($this->customer)->server)->toBeNull();
});

// F-2.9.2-038 × D-50 — the pause semantics must not change
it('F-2.9.2-038: a restricted exhausted PAUSE server does not abort a pick for an unassigned campaign', function (): void {
    $list = assignmentList($this->customer);
    $mine = Campaign::factory()->create(['customer_id' => $this->customer->id, 'email_list_id' => $list->id]);
    $other = Campaign::factory()->create(['customer_id' => $this->customer->id, 'email_list_id' => $list->id]);

    $restricted = assignmentServer($this->customer, [
        'hourly_quota' => 3,
        'quota_exceeded_action' => 'pause',
    ]);
    app(DeliveryServerAssignments::class)->sync($restricted, [], [(int) $mine->id]);

    $open = assignmentServer($this->customer, ['hourly_quota' => 0]);

    app(ServerUsage::class)->recordSend($restricted, 3);

    // Unassigned campaign: the exhausted PAUSE server is invisible to it.
    $free = assignmentPicker()->pickFor($this->customer, campaignId: (int) $other->id, listIds: [(int) $list->id]);

    expect($free->shouldPause)->toBeFalse()
        ->and($free->server?->id)->toBe($open->id);

    // Its OWN campaign still gets the unchanged D-50 pause.
    $paused = assignmentPicker()->pickFor($this->customer, campaignId: (int) $mine->id, listIds: [(int) $list->id]);

    expect($paused->picked())->toBeFalse()
        ->and($paused->shouldPause)->toBeTrue()
        ->and($paused->retryAfterSeconds)->toBeGreaterThan(0);
});

// ------------------------------------------------------------- the send path

it('F-2.9.2-038: the campaign send path routes only through the assigned server', function (): void {
    $list = assignmentList($this->customer, 'Assignment List');

    foreach (['anna@example.com', 'taro@example.jp'] as $email) {
        Subscriber::factory()->create([
            'list_id' => $list->id,
            'customer_id' => $this->customer->id,
            'email' => $email,
        ]);
    }

    $mine = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $list->id,
        'status' => CampaignStatus::Scheduled,
        'send_at' => Carbon::now()->subMinute(),
    ]);
    $other = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $list->id,
    ]);

    $assigned = assignmentServer($this->customer, ['name' => 'Assigned relay']);
    $foreignCampaignServer = assignmentServer($this->customer, ['name' => 'Other relay']);

    $assignments = app(DeliveryServerAssignments::class);
    $assignments->sync($assigned, [], [(int) $mine->id]);
    $assignments->sync($foreignCampaignServer, [], [(int) $other->id]);

    $driver = new RecordingSendingDriver('smtp');
    app(DriverRegistry::class)->register('smtp', $driver);

    $this->artisan('campaigns:send')->assertSuccessful();

    $usedServerIds = CampaignDeliveryLog::query()
        ->where('campaign_id', $mine->id)
        ->pluck('delivery_server_id')
        ->unique()
        ->values()
        ->all();

    expect($driver->sent)->toHaveCount(2)
        ->and($usedServerIds)->toBe([$assigned->id])
        ->and(collect($driver->sent)->pluck('server.id')->unique()->all())->toBe([$assigned->id]);
});

// ------------------------------------------------------------- cross-tenant

it('F-2.9.2-038: refuses to attach lists or campaigns of another tenant', function (): void {
    $intruder = Customer::factory()->create();
    $foreignList = assignmentList($intruder, 'Foreign list');
    $foreignCampaign = Campaign::factory()->create([
        'customer_id' => $intruder->id,
        'email_list_id' => $foreignList->id,
    ]);

    $server = assignmentServer($this->customer);
    $assignments = app(DeliveryServerAssignments::class);

    expect(fn () => $assignments->sync($server, [(int) $foreignList->id], []))
        ->toThrow(ValidationException::class)
        ->and(fn () => $assignments->sync($server, [], [(int) $foreignCampaign->id]))
        ->toThrow(ValidationException::class)
        ->and(DeliveryServerAssignment::query()->where('delivery_server_id', $server->id)->count())->toBe(0);

    // …and a system server, which owns nothing, accepts nothing.
    $system = app(CurrentCustomer::class)->runAs(null, fn () => DeliveryServer::factory()->system()->create());
    $ownList = assignmentList($this->customer, 'Own list');

    expect(fn () => $assignments->sync($system, [(int) $ownList->id], []))
        ->toThrow(ValidationException::class);
});

// ------------------------------------------------------------------ the UI

it('F-2.9.2-038: persists and reloads the assignment through the customer form', function (): void {
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);

    $list = assignmentList($this->customer, 'UI list');
    $campaign = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $list->id,
    ]);

    Livewire::test(CreateDeliveryServer::class)
        ->fillForm([
            'name' => 'Restricted relay',
            'type' => 'smtp',
            'hostname' => 'smtp.acme.example',
            'port' => 587,
            'protocol' => 'tls',
            'from_email' => 'news@acme.example',
            'assigned_list_ids' => [$list->id],
            'assigned_campaign_ids' => [$campaign->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $server = DeliveryServer::query()->where('name', 'Restricted relay')->firstOrFail();

    expect(app(DeliveryServerAssignments::class)->forServer($server))
        ->toBe(['list' => [(int) $list->id], 'campaign' => [(int) $campaign->id]]);

    Livewire::test(EditDeliveryServer::class, ['record' => $server->getRouteKey()])
        ->assertFormSet([
            'assigned_list_ids' => [$list->id],
            'assigned_campaign_ids' => [$campaign->id],
        ])
        ->fillForm(['assigned_campaign_ids' => []])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(DeliveryServerAssignments::class)->forServer($server->refresh()))
        ->toBe(['list' => [(int) $list->id], 'campaign' => []]);
});

it('F-2.9.2-038: rejects a forged cross-tenant id posted to the customer form', function (): void {
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);

    $intruder = Customer::factory()->create();
    $foreignList = assignmentList($intruder, 'Foreign');

    Livewire::test(CreateDeliveryServer::class)
        ->fillForm([
            'name' => 'Sneaky relay',
            'type' => 'smtp',
            'hostname' => 'smtp.acme.example',
            'port' => 587,
            'protocol' => 'tls',
            'from_email' => 'news@acme.example',
            'assigned_list_ids' => [$foreignList->id],
        ])
        ->call('create')
        // nestedRecursiveRules() reports per-element: data.assigned_list_ids.0
        ->assertHasFormErrors(['assigned_list_ids.0']);

    expect(DeliveryServer::withoutTenancy()->where('name', 'Sneaky relay')->exists())->toBeFalse()
        ->and(DeliveryServerAssignment::query()->count())->toBe(0);
});

/*
 * WP-42 coordinator: the copy action creates a NEW server from an existing
 * one. `replicate()` copies columns, not related rows, so without carrying the
 * assignment across, copying a server that was fenced to two lists produced a
 * server that could send anything — a restriction widening by accident, which
 * is the one direction a safety fence must never move in.
 */
it('F-2.9.2-038: copying a restricted server carries the restriction across', function (): void {
    $customer = $this->customer;
    $this->actingAs($customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($customer);

    $list = assignmentList($customer, 'Fenced');
    $server = assignmentServer($customer, ['name' => 'Restricted']);

    app(DeliveryServerAssignments::class)->sync($server, [(int) $list->id], []);

    Livewire::test(ListDeliveryServers::class)
        ->callTableAction('copy', $server);

    $copy = DeliveryServer::query()
        ->where('customer_id', $customer->id)
        ->where('name', 'Restricted '.__('sending.labels.copy_suffix'))
        ->sole();

    $assignments = app(DeliveryServerAssignments::class);

    expect($assignments->forServer($copy))->toBe(['list' => [(int) $list->id], 'campaign' => []])
        ->and($assignments->isRestricted($copy))->toBeTrue()
        // The original is untouched.
        ->and($assignments->forServer($server))->toBe(['list' => [(int) $list->id], 'campaign' => []]);
});

it('F-2.9.2-038: copying an unrestricted server leaves it unrestricted', function (): void {
    $customer = $this->customer;
    $this->actingAs($customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($customer);

    $server = assignmentServer($customer, ['name' => 'Open']);

    Livewire::test(ListDeliveryServers::class)
        ->callTableAction('copy', $server);

    $copy = DeliveryServer::query()
        ->where('customer_id', $customer->id)
        ->where('name', 'Open '.__('sending.labels.copy_suffix'))
        ->sole();

    expect(app(DeliveryServerAssignments::class)->isRestricted($copy))->toBeFalse()
        ->and(DeliveryServerAssignment::query()->where('delivery_server_id', $copy->id)->count())->toBe(0);
});
