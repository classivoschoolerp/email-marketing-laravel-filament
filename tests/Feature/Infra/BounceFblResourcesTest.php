<?php

declare(strict_types=1);

use App\Enums\BounceType;
use App\Enums\InfraServerStatus;
use App\Enums\MailboxEncryption;
use App\Enums\MailboxProtocol;
use App\Filament\Admin\Resources\BounceRuleResource;
use App\Filament\Admin\Resources\BounceRuleResource\Pages\CreateBounceRule;
use App\Filament\Admin\Resources\BounceRuleResource\Pages\ListBounceRules;
use App\Filament\Admin\Resources\BounceServerResource;
use App\Filament\Admin\Resources\BounceServerResource\Pages\CreateBounceServer;
use App\Filament\Admin\Resources\BounceServerResource\Pages\ListBounceServers;
use App\Filament\Admin\Resources\FblServerResource;
use App\Filament\Admin\Resources\FblServerResource\Pages\CreateFblServer;
use App\Filament\Admin\Resources\FblServerResource\Pages\ListFblServers;
use App\Jobs\Infra\ProcessBounceServerJob;
use App\Jobs\Infra\ProcessFblServerJob;
use App\Models\BounceRule;
use App\Models\BounceServer;
use App\Models\CampaignBounceLog;
use App\Models\Customer;
use App\Models\FblServer;
use App\Models\User;
use App\Services\Mailboxes\MailboxClientInterface;
use App\Support\Queue\Queues;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Fakes\FakeMailbox;
use Tests\Support\AdminTestHelpers;

// ---------------------------------------------------------------------------
// Bounce servers (§2.9.4 + §3.13.2)
// ---------------------------------------------------------------------------
it('renders the bounce servers index for permitted admins', function (): void {
    AdminTestHelpers::actingAdmin(['servers.manage']);

    $global = BounceServer::factory()->system()->create(['name' => 'Global bounce']);
    $tenant = BounceServer::factory()->create(['name' => 'Tenant bounce']);

    $this->get(BounceServerResource::getUrl('index', panel: 'admin'))->assertOk();

    Livewire::test(ListBounceServers::class)
        ->assertCanSeeTableRecords([$global, $tenant]);
});

it('denies bounce servers to non-permitted admins and customer-guard users', function (): void {
    $this->actingAs(User::factory()->create(), 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->get(BounceServerResource::getUrl('index', panel: 'admin'))->assertForbidden();

    auth('admin')->logout();
    $this->actingAs(Customer::factory()->create(), 'customer');
    expect($this->get(BounceServerResource::getUrl('index', panel: 'admin'))->status())->not->toBe(200);
});

// F-2.9.4-001..004 — full mailbox config, password encrypted at rest
it('creates a bounce server with encrypted mailbox password', function (): void {
    AdminTestHelpers::actingAdmin(['servers.manage']);

    Livewire::test(CreateBounceServer::class)
        ->fillForm([
            'name' => 'Bounce inbox',
            'hostname' => 'pop.platform.test',
            'port' => 995,
            'protocol' => MailboxProtocol::Pop3->value,
            'encryption' => MailboxEncryption::Ssl->value,
            'username' => 'bounces@platform.test',
            'password' => 'pop-secret-1',
            'validate_cert' => false,
            'delete_after_processing' => false,
            'max_per_batch' => 50,
            'search_charset' => 'ISO-8859-1',
            'locked' => true,
            'hard_bounce_action' => 'blacklist',
            'soft_bounce_threshold' => 4,
            'soft_bounce_action' => 'unsubscribe',
            'status' => InfraServerStatus::Active->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $server = BounceServer::withoutTenancy()->where('name', 'Bounce inbox')->firstOrFail();

    expect($server->customer_id)->toBeNull() // admin default = global row
        ->and($server->protocol)->toBe(MailboxProtocol::Pop3)
        ->and($server->encryption)->toBe(MailboxEncryption::Ssl)
        ->and($server->validate_cert)->toBeFalse()
        ->and($server->delete_after_processing)->toBeFalse()
        ->and($server->max_per_batch)->toBe(50)
        ->and($server->search_charset)->toBe('ISO-8859-1')
        ->and($server->locked)->toBeTrue()
        ->and($server->soft_bounce_threshold)->toBe(4)
        ->and($server->password)->toBe('pop-secret-1');

    // F-2.9.4-001 password encrypted at rest — raw DB assertion
    $raw = DB::table('bounce_servers')->where('id', $server->id)->value('password');
    expect($raw)->not->toContain('pop-secret-1')
        ->and(Crypt::decryptString($raw))->toBe('pop-secret-1');
});

// F-2.9.4-005 — validate connection button through the mailbox client
it('validates the mailbox connection from the table action', function (): void {
    AdminTestHelpers::actingAdmin(['servers.manage']);

    $server = BounceServer::factory()->system()->create();

    $fake = new FakeMailbox;
    app()->instance(MailboxClientInterface::class, $fake);

    Livewire::test(ListBounceServers::class)
        ->callTableAction('test_connection', $server)
        ->assertNotified(__('infra.notifications.connection_ok'));

    expect($fake->lastConnection)->not->toBeNull()
        ->and($fake->lastConnection->hostname)->toBe($server->hostname);

    $fake->failConnect = true;

    Livewire::test(ListBounceServers::class)
        ->callTableAction('test_connection', $server)
        ->assertNotified(__('infra.notifications.connection_failed'));
});

it('queues a bounce processing job from the process-now action', function (): void {
    Bus::fake();
    AdminTestHelpers::actingAdmin(['servers.manage']);

    $server = BounceServer::factory()->system()->create();

    Livewire::test(ListBounceServers::class)
        ->callTableAction('process_now', $server);

    Bus::assertDispatched(
        ProcessBounceServerJob::class,
        fn (ProcessBounceServerJob $job): bool => $job->bounceServerId === $server->id
            && $job->queue === Queues::BOUNCES,
    );
});

// F-2.9.4-009 — processing log table per server
it('renders the bounce server processing log page', function (): void {
    AdminTestHelpers::actingAdmin(['servers.manage']);

    $server = BounceServer::factory()->system()->create(['last_run_at' => now()]);

    CampaignBounceLog::query()->create([
        'bounce_server_id' => $server->id,
        'bounce_type' => BounceType::Hard,
        'email' => 'gone@dest.example',
        'message' => '550 5.1.1 User unknown',
    ]);

    $this->get(BounceServerResource::getUrl('logs', ['record' => $server], panel: 'admin'))
        ->assertOk()
        ->assertSee('gone@dest.example')
        ->assertSee('550 5.1.1 User unknown');
});

// ---------------------------------------------------------------------------
// FBL servers (§2.9.5 + §3.13.3)
// ---------------------------------------------------------------------------
it('renders the fbl servers index and creates a server with encrypted password', function (): void {
    AdminTestHelpers::actingAdmin(['servers.manage']);

    $this->get(FblServerResource::getUrl('index', panel: 'admin'))->assertOk();

    Livewire::test(CreateFblServer::class)
        ->fillForm([
            'name' => 'AOL FBL',
            'hostname' => 'imap.platform.test',
            'port' => 993,
            'protocol' => MailboxProtocol::Imap->value,
            'encryption' => MailboxEncryption::Ssl->value,
            'username' => 'fbl@platform.test',
            'password' => 'fbl-secret-1',
            'status' => InfraServerStatus::Active->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $server = FblServer::withoutTenancy()->where('name', 'AOL FBL')->firstOrFail();

    expect($server->protocol)->toBe(MailboxProtocol::Imap)
        ->and($server->password)->toBe('fbl-secret-1');

    $raw = DB::table('fbl_servers')->where('id', $server->id)->value('password');
    expect($raw)->not->toContain('fbl-secret-1')
        ->and(Crypt::decryptString($raw))->toBe('fbl-secret-1');
});

it('queues an fbl processing job and validates connections', function (): void {
    Bus::fake();
    AdminTestHelpers::actingAdmin(['servers.manage']);

    $server = FblServer::factory()->system()->create();

    $fake = new FakeMailbox;
    app()->instance(MailboxClientInterface::class, $fake);

    Livewire::test(ListFblServers::class)
        ->callTableAction('test_connection', $server)
        ->assertNotified(__('infra.notifications.connection_ok'))
        ->callTableAction('process_now', $server);

    Bus::assertDispatched(
        ProcessFblServerJob::class,
        fn (ProcessFblServerJob $job): bool => $job->fblServerId === $server->id,
    );
});

it('renders the fbl server complaint log page', function (): void {
    AdminTestHelpers::actingAdmin(['servers.manage']);

    $server = FblServer::factory()->system()->create(['last_run_at' => now()]);

    App\Models\CampaignComplaintLog::query()->create([
        'fbl_server_id' => $server->id,
        'feedback_type' => 'abuse',
        'email' => 'angry@dest.example',
        'message' => 'Feedback-Type: abuse',
    ]);

    $this->get(FblServerResource::getUrl('logs', ['record' => $server], panel: 'admin'))
        ->assertOk()
        ->assertSee('angry@dest.example');
});

it('denies fbl servers to customer-guard users', function (): void {
    $this->actingAs(Customer::factory()->create(), 'customer');
    expect($this->get(FblServerResource::getUrl('index', panel: 'admin'))->status())->not->toBe(200);
});

// ---------------------------------------------------------------------------
// Bounce rules (§2.9.4 F-2.9.4-006 custom regex rules)
// ---------------------------------------------------------------------------
it('lists the seeded default rule set', function (): void {
    AdminTestHelpers::actingAdmin(['servers.manage']);

    $this->get(BounceRuleResource::getUrl('index', panel: 'admin'))->assertOk();

    expect(BounceRule::query()->count())->toBeGreaterThanOrEqual(7)
        ->and(BounceRule::query()->where('type', BounceType::Hard->value)->exists())->toBeTrue()
        ->and(BounceRule::query()->where('type', BounceType::Soft->value)->exists())->toBeTrue()
        ->and(BounceRule::query()->where('type', BounceType::Internal->value)->exists())->toBeTrue();

    Livewire::test(ListBounceRules::class)
        ->assertCanSeeTableRecords(BounceRule::query()->orderBy('sort_order')->limit(5)->get());
});

it('creates a custom rule and rejects invalid regex patterns', function (): void {
    AdminTestHelpers::actingAdmin(['servers.manage']);

    Livewire::test(CreateBounceRule::class)
        ->fillForm([
            'name' => 'Custom blocker',
            'pattern' => 'blocked\s+by\s+policy',
            'type' => BounceType::Hard->value,
            'enabled' => true,
            'sort_order' => 25,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(BounceRule::query()->where('name', 'Custom blocker')->exists())->toBeTrue();

    Livewire::test(CreateBounceRule::class)
        ->fillForm([
            'name' => 'Broken rule',
            'pattern' => '([unclosed',
            'type' => BounceType::Soft->value,
        ])
        ->call('create')
        ->assertHasFormErrors(['pattern']);
});

it('denies bounce rules to customer-guard users entirely', function (): void {
    $customer = Customer::factory()->create();
    $rule = BounceRule::query()->firstOrFail();

    expect(Gate::forUser($customer)->allows('viewAny', BounceRule::class))->toBeFalse()
        ->and(Gate::forUser($customer)->allows('update', $rule))->toBeFalse();
});
