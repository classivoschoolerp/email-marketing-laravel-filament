<?php

declare(strict_types=1);

use App\Enums\InfraServerStatus;
use App\Enums\MailboxEncryption;
use App\Enums\MailboxProtocol;
use App\Enums\MonitorAction;
use App\Enums\MonitorConditionField;
use App\Enums\MonitorConditionOperator;
use App\Enums\SubscriberStatus;
use App\Filament\Admin\Resources\EmailBoxMonitorResource;
use App\Filament\Admin\Resources\EmailBoxMonitorResource\Pages\CreateEmailBoxMonitor;
use App\Filament\Admin\Resources\EmailBoxMonitorResource\Pages\EmailBoxMonitorConditions;
use App\Filament\Admin\Resources\EmailBoxMonitorResource\Pages\ListEmailBoxMonitors;
use App\Jobs\Infra\ProcessEmailBoxMonitorJob;
use App\Mail\MonitorAlertMail;
use App\Models\CronRun;
use App\Models\Customer;
use App\Models\EmailBoxMonitor;
use App\Models\EmailBoxMonitorCondition;
use App\Models\EmailBoxMonitorLog;
use App\Models\EmailList;
use App\Models\GlobalBlacklistEmail;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Mailboxes\MailboxClientInterface;
use App\Services\Monitors\EmailBoxMonitorProcessor;
use App\Settings\CronSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Fakes\FakeMailbox;
use Tests\Support\AdminTestHelpers;

/*
 * §2.9.6 email box monitors (WP-33): mailbox config, conditions with regex,
 * priority ordering, the five actions, the processing log and the
 * mailbox-monitors:process cron.
 */

function monitorFixture(string $name, string $from = 'reader@dest.example'): string
{
    $raw = (string) file_get_contents(base_path('tests/Fixtures/monitors/'.$name));

    return str_replace('{{FROM}}', $from, $raw);
}

function monitorMailbox(array $messages): FakeMailbox
{
    $fake = new FakeMailbox($messages);
    app()->instance(MailboxClientInterface::class, $fake);

    return $fake;
}

/** @return array{0: Customer, 1: Subscriber, 2: EmailList} */
function monitorSubscriber(string $email = 'reader@dest.example'): array
{
    $customer = Customer::factory()->create();
    $list = EmailList::factory()->for($customer)->create();
    $subscriber = Subscriber::factory()
        ->for($customer)
        ->for($list, 'list')
        ->create(['email' => $email, 'status' => SubscriberStatus::Confirmed]);

    return [$customer, $subscriber, $list];
}

function monitorCondition(EmailBoxMonitor $monitor, array $attributes = []): EmailBoxMonitorCondition
{
    return EmailBoxMonitorCondition::query()->create(array_merge([
        'email_box_monitor_id' => $monitor->id,
        'field' => MonitorConditionField::Subject,
        'operator' => MonitorConditionOperator::Contains,
        'value' => 'remove me',
        'action' => MonitorAction::Unsubscribe,
        'priority' => 10,
        'enabled' => true,
    ], $attributes));
}

// ---------------------------------------------------------------------------
// F-2.9.6-001 — mailbox connection config, password encrypted at rest
// ---------------------------------------------------------------------------
it('stores the monitor mailbox password encrypted at rest', function (): void {
    $monitor = EmailBoxMonitor::factory()->system()->create(['password' => 'monitor-secret-1']);

    $raw = DB::table('email_box_monitors')->where('id', $monitor->id)->value('password');

    expect($monitor->password)->toBe('monitor-secret-1')
        ->and($raw)->not->toContain('monitor-secret-1')
        ->and(Crypt::decryptString($raw))->toBe('monitor-secret-1');
});

it('exposes the mailbox connection DTO from the stored config', function (): void {
    $monitor = EmailBoxMonitor::factory()->system()->create([
        'hostname' => 'imap.platform.test',
        'port' => 993,
        'protocol' => MailboxProtocol::Imap,
        'encryption' => MailboxEncryption::Tls,
        'username' => 'monitor@platform.test',
        'password' => 'pw',
    ]);

    $connection = $monitor->connection();

    expect($connection->hostname)->toBe('imap.platform.test')
        ->and($connection->port)->toBe(993)
        ->and($connection->protocol)->toBe(MailboxProtocol::Imap)
        ->and($connection->encryption)->toBe(MailboxEncryption::Tls)
        ->and($connection->password)->toBe('pw');
});

// ---------------------------------------------------------------------------
// F-2.9.6-002 — subject / body / from matching, regex supported
// ---------------------------------------------------------------------------
it('matches on the subject and unsubscribes the sender', function (): void {
    [, $subscriber] = monitorSubscriber();

    $monitor = EmailBoxMonitor::factory()->system()->create();
    monitorCondition($monitor, ['value' => 'REMOVE ME']);

    $mailbox = monitorMailbox([1 => monitorFixture('unsubscribe-request.eml')]);

    $processed = app(EmailBoxMonitorProcessor::class)->process($monitor);

    expect($processed)->toBe(1)
        ->and($subscriber->refresh()->status)->toBe(SubscriberStatus::Unsubscribed)
        ->and($subscriber->unsubscribed_at)->not->toBeNull()
        ->and($mailbox->deleted)->toBe([1])
        ->and($monitor->refresh()->last_run_at)->not->toBeNull();
});

it('matches on the body of a multipart message', function (): void {
    [, $subscriber] = monitorSubscriber('abuse@dest.example');

    $monitor = EmailBoxMonitor::factory()->system()->create();
    monitorCondition($monitor, [
        'field' => MonitorConditionField::Body,
        'value' => 'reported as SPAM',
        'action' => MonitorAction::Blacklist,
    ]);

    monitorMailbox([1 => monitorFixture('spam-complaint.eml', 'abuse@dest.example')]);

    app(EmailBoxMonitorProcessor::class)->process($monitor);

    $entry = GlobalBlacklistEmail::query()->where('email', 'abuse@dest.example')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->source)->toBe('monitor')
        ->and($subscriber->refresh()->status)->toBe(SubscriberStatus::Confirmed);
});

it('matches on the from header', function (): void {
    [, $subscriber] = monitorSubscriber('abuse@isp.example');

    $monitor = EmailBoxMonitor::factory()->system()->create();
    monitorCondition($monitor, [
        'field' => MonitorConditionField::From,
        'value' => '@isp.example',
        'action' => MonitorAction::Unsubscribe,
    ]);

    monitorMailbox([1 => monitorFixture('plain-chatter.eml', 'abuse@isp.example')]);

    app(EmailBoxMonitorProcessor::class)->process($monitor);

    expect($subscriber->refresh()->status)->toBe(SubscriberStatus::Unsubscribed);
});

it('supports regex conditions and decoded mime subjects', function (): void {
    [, $subscriber] = monitorSubscriber('abuse@dest.example');

    $monitor = EmailBoxMonitor::factory()->system()->create();
    // The fixture subject is RFC 2047 encoded: =?UTF-8?B?UsOpY2xhbWF0aW9u?=
    monitorCondition($monitor, [
        'operator' => MonitorConditionOperator::Regex,
        'value' => '/r[ée]clamation/iu',
        'action' => MonitorAction::Unsubscribe,
    ]);

    monitorMailbox([1 => monitorFixture('spam-complaint.eml', 'abuse@dest.example')]);

    app(EmailBoxMonitorProcessor::class)->process($monitor);

    expect($subscriber->refresh()->status)->toBe(SubscriberStatus::Unsubscribed);
});

it('never matches on a broken regex pattern', function (): void {
    [, $subscriber] = monitorSubscriber();

    $monitor = EmailBoxMonitor::factory()->system()->create();
    monitorCondition($monitor, [
        'operator' => MonitorConditionOperator::Regex,
        'value' => '/([unclosed/',
        'action' => MonitorAction::DeleteSubscriber,
    ]);

    monitorMailbox([1 => monitorFixture('unsubscribe-request.eml')]);

    app(EmailBoxMonitorProcessor::class)->process($monitor);

    expect($subscriber->refresh()->status)->toBe(SubscriberStatus::Confirmed)
        ->and(EmailBoxMonitorLog::query()->value('matched'))->toBeFalse();
});

it('supports a does-not-contain condition', function (): void {
    [, $subscriber] = monitorSubscriber();

    $monitor = EmailBoxMonitor::factory()->system()->create();
    monitorCondition($monitor, [
        'operator' => MonitorConditionOperator::NotContains,
        'value' => 'newsletter',
        'action' => MonitorAction::Unsubscribe,
    ]);

    monitorMailbox([1 => monitorFixture('unsubscribe-request.eml')]);

    app(EmailBoxMonitorProcessor::class)->process($monitor);

    expect($subscriber->refresh()->status)->toBe(SubscriberStatus::Unsubscribed);
});

// ---------------------------------------------------------------------------
// F-2.9.6-004 — multiple conditions, priority ordering, first match wins
// ---------------------------------------------------------------------------
it('applies the highest-priority matching condition and stops', function (): void {
    [, $subscriber] = monitorSubscriber();

    $monitor = EmailBoxMonitor::factory()->system()->create();

    // Both match; priority 1 must win over priority 5.
    monitorCondition($monitor, ['value' => 'remove me', 'action' => MonitorAction::Blacklist, 'priority' => 5]);
    monitorCondition($monitor, ['value' => 'remove me', 'action' => MonitorAction::Unsubscribe, 'priority' => 1]);

    monitorMailbox([1 => monitorFixture('unsubscribe-request.eml')]);

    app(EmailBoxMonitorProcessor::class)->process($monitor);

    expect($subscriber->refresh()->status)->toBe(SubscriberStatus::Unsubscribed)
        ->and(GlobalBlacklistEmail::query()->count())->toBe(0)
        ->and(EmailBoxMonitorLog::query()->count())->toBe(1)
        ->and(EmailBoxMonitorLog::query()->value('action'))->toBe(MonitorAction::Unsubscribe);
});

it('skips disabled conditions', function (): void {
    [, $subscriber] = monitorSubscriber();

    $monitor = EmailBoxMonitor::factory()->system()->create();
    monitorCondition($monitor, ['value' => 'remove me', 'priority' => 1, 'enabled' => false]);

    monitorMailbox([1 => monitorFixture('unsubscribe-request.eml')]);

    app(EmailBoxMonitorProcessor::class)->process($monitor);

    expect($subscriber->refresh()->status)->toBe(SubscriberStatus::Confirmed)
        ->and(EmailBoxMonitorLog::query()->value('matched'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// F-2.9.6-003 — the remaining actions
// ---------------------------------------------------------------------------
it('deletes the subscriber when the delete action fires', function (): void {
    [, $subscriber] = monitorSubscriber();

    $monitor = EmailBoxMonitor::factory()->system()->create();
    monitorCondition($monitor, ['action' => MonitorAction::DeleteSubscriber]);

    monitorMailbox([1 => monitorFixture('unsubscribe-request.eml')]);

    app(EmailBoxMonitorProcessor::class)->process($monitor);

    expect(Subscriber::withoutTenancy()->find($subscriber->id))->toBeNull()
        ->and(Subscriber::withoutTenancy()->withTrashed()->find($subscriber->id))->not->toBeNull();
});

it('moves the subscriber to the configured list', function (): void {
    [$customer, $subscriber] = monitorSubscriber();
    $target = EmailList::factory()->for($customer)->create(['name' => 'Complaints']);

    $monitor = EmailBoxMonitor::factory()->create(['customer_id' => $customer->id]);
    monitorCondition($monitor, [
        'action' => MonitorAction::MoveToList,
        'target_list_id' => $target->id,
    ]);

    monitorMailbox([1 => monitorFixture('unsubscribe-request.eml')]);

    app(EmailBoxMonitorProcessor::class)->process($monitor);

    expect($subscriber->refresh()->list_id)->toBe($target->id)
        ->and(EmailBoxMonitorLog::query()->value('result'))->toContain('Complaints');
});

it('refuses to move a subscriber into another tenant list', function (): void {
    [, $subscriber] = monitorSubscriber();
    $foreign = EmailList::factory()->for(Customer::factory()->create())->create();
    $originalList = $subscriber->list_id;

    $monitor = EmailBoxMonitor::factory()->system()->create();
    monitorCondition($monitor, [
        'action' => MonitorAction::MoveToList,
        'target_list_id' => $foreign->id,
    ]);

    monitorMailbox([1 => monitorFixture('unsubscribe-request.eml')]);

    app(EmailBoxMonitorProcessor::class)->process($monitor);

    expect($subscriber->refresh()->list_id)->toBe($originalList)
        ->and(EmailBoxMonitorLog::query()->value('result'))
        ->toBe(__('infra.monitors.results.cross_tenant_list'));
});

it('notifies an admin address without needing a subscriber', function (): void {
    Mail::fake();

    $monitor = EmailBoxMonitor::factory()->system()->create(['name' => 'Abuse inbox']);
    monitorCondition($monitor, [
        'field' => MonitorConditionField::Subject,
        'value' => 'Out of office',
        'action' => MonitorAction::NotifyAdmin,
        'notify_email' => 'ops@platform.test',
    ]);

    monitorMailbox([1 => monitorFixture('out-of-office.eml', 'nobody@dest.example')]);

    app(EmailBoxMonitorProcessor::class)->process($monitor);

    Mail::assertQueued(
        MonitorAlertMail::class,
        fn (MonitorAlertMail $mail): bool => $mail->monitorName === 'Abuse inbox'
            && $mail->hasTo('ops@platform.test'),
    );

    expect(EmailBoxMonitorLog::query()->value('matched'))->toBeTrue();
});

it('logs a miss when the sender is not a known subscriber', function (): void {
    $monitor = EmailBoxMonitor::factory()->system()->create();
    monitorCondition($monitor, ['action' => MonitorAction::Unsubscribe]);

    monitorMailbox([1 => monitorFixture('unsubscribe-request.eml', 'stranger@dest.example')]);

    app(EmailBoxMonitorProcessor::class)->process($monitor);

    $log = EmailBoxMonitorLog::query()->firstOrFail();

    expect($log->matched)->toBeTrue()
        ->and($log->subscriber_id)->toBeNull()
        ->and($log->result)->toBe(__('infra.monitors.results.no_subscriber'));
});

it('fences a customer monitor to its owner subscribers', function (): void {
    [$mine, $mySubscriber] = monitorSubscriber('shared@dest.example');
    [, $theirSubscriber] = monitorSubscriber('shared@dest.example');

    $monitor = EmailBoxMonitor::factory()->create(['customer_id' => $mine->id]);
    monitorCondition($monitor, ['action' => MonitorAction::Unsubscribe]);

    monitorMailbox([1 => monitorFixture('unsubscribe-request.eml', 'shared@dest.example')]);

    app(EmailBoxMonitorProcessor::class)->process($monitor);

    expect($mySubscriber->refresh()->status)->toBe(SubscriberStatus::Unsubscribed)
        ->and($theirSubscriber->refresh()->status)->toBe(SubscriberStatus::Confirmed);
});

// ---------------------------------------------------------------------------
// F-2.9.6-005 — processing log
// ---------------------------------------------------------------------------
it('logs every examined message, matched or not', function (): void {
    [, $subscriber] = monitorSubscriber();

    $monitor = EmailBoxMonitor::factory()->system()->create();
    $condition = monitorCondition($monitor, ['value' => 'remove me']);

    monitorMailbox([
        1 => monitorFixture('unsubscribe-request.eml'),
        2 => monitorFixture('plain-chatter.eml'),
    ]);

    $processed = app(EmailBoxMonitorProcessor::class)->process($monitor);

    expect($processed)->toBe(2)
        ->and(EmailBoxMonitorLog::query()->count())->toBe(2);

    $matched = EmailBoxMonitorLog::query()->where('matched', true)->firstOrFail();
    $missed = EmailBoxMonitorLog::query()->where('matched', false)->firstOrFail();

    expect($matched->email_box_monitor_condition_id)->toBe($condition->id)
        ->and($matched->action)->toBe(MonitorAction::Unsubscribe)
        ->and($matched->from_email)->toBe('reader@dest.example')
        ->and($matched->subject)->toContain('REMOVE ME')
        ->and($matched->subscriber_id)->toBe($subscriber->id)
        ->and($missed->result)->toBe(__('infra.monitors.results.no_match'))
        ->and($missed->action)->toBeNull();
});

it('honours delete-after-processing and the batch cap', function (): void {
    $monitor = EmailBoxMonitor::factory()->system()->create([
        'delete_after_processing' => false,
        'max_per_batch' => 1,
    ]);

    $mailbox = monitorMailbox([
        1 => monitorFixture('plain-chatter.eml'),
        2 => monitorFixture('plain-chatter.eml'),
    ]);

    $processed = app(EmailBoxMonitorProcessor::class)->process($monitor);

    expect($processed)->toBe(1)
        ->and($mailbox->deleted)->toBe([])
        ->and($mailbox->disconnects)->toBe(1);
});

// ---------------------------------------------------------------------------
// F-0.6-007 — mailbox-monitors:process command
// ---------------------------------------------------------------------------
it('runs mailbox-monitors:process for active monitors only and records the cron run', function (): void {
    EmailBoxMonitor::factory()->system()->create();
    EmailBoxMonitor::factory()->system()->inactive()->create();

    monitorMailbox([1 => monitorFixture('plain-chatter.eml')]);

    $this->artisan('mailbox-monitors:process')->assertSuccessful();

    $run = CronRun::query()->where('job', 'mailbox-monitors:process')->firstOrFail();

    expect($run->status)->toBe(CronRun::STATUS_SUCCESS)
        ->and($run->processed)->toBe(1)
        ->and(EmailBoxMonitor::withoutTenancy()->whereNotNull('last_run_at')->count())->toBe(1);
});

it('skips mailbox-monitors:process when the job is disabled in cron settings', function (): void {
    $settings = app(CronSettings::class);
    $settings->enabled_jobs = array_values(array_diff($settings->enabled_jobs, ['mailbox-monitors:process']));
    $settings->save();

    EmailBoxMonitor::factory()->system()->create();
    $mailbox = monitorMailbox([1 => monitorFixture('plain-chatter.eml')]);

    $this->artisan('mailbox-monitors:process')->assertSuccessful();

    expect(CronRun::query()->where('job', 'mailbox-monitors:process')->exists())->toBeFalse()
        ->and($mailbox->connected)->toBeFalse();
});

it('continues past a failing mailbox and still succeeds', function (): void {
    EmailBoxMonitor::factory()->system()->create();

    $mailbox = monitorMailbox([]);
    $mailbox->failConnect = true;

    $this->artisan('mailbox-monitors:process')->assertSuccessful();

    $run = CronRun::query()->where('job', 'mailbox-monitors:process')->firstOrFail();

    expect($run->status)->toBe(CronRun::STATUS_SUCCESS)
        ->and($run->processed)->toBe(0);
});

it('is registered under its canonical §0.6 job name', function (): void {
    expect(array_key_exists('mailbox-monitors:process', Artisan::all()))->toBeTrue()
        ->and(CronSettings::KNOWN_JOBS)->toContain('mailbox-monitors:process')
        ->and(CronSettings::installedJobs())->toContain('mailbox-monitors:process')
        ->and(CronSettings::KNOWN_JOBS)->toContain('warmup:advance');

    // warmup:advance ships with the deliverability module; once installed it
    // must be registered under the same canonical name.
    expect(array_key_exists('warmup:advance', Artisan::all()))
        ->toBe(in_array('warmup:advance', CronSettings::installedJobs(), true));
});

// ---------------------------------------------------------------------------
// Admin resource + guards
// ---------------------------------------------------------------------------
it('renders the monitors index and creates one with an encrypted password', function (): void {
    AdminTestHelpers::actingAdmin(['servers.manage']);

    $this->get(EmailBoxMonitorResource::getUrl('index', panel: 'admin'))->assertOk();

    Livewire::test(CreateEmailBoxMonitor::class)
        ->fillForm([
            'name' => 'Abuse mailbox',
            'hostname' => 'pop.platform.test',
            'port' => 995,
            'protocol' => MailboxProtocol::Pop3->value,
            'encryption' => MailboxEncryption::Ssl->value,
            'username' => 'abuse@platform.test',
            'password' => 'monitor-secret-2',
            'validate_cert' => true,
            'delete_after_processing' => true,
            'max_per_batch' => 100,
            'search_charset' => 'UTF-8',
            'status' => InfraServerStatus::Active->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $monitor = EmailBoxMonitor::withoutTenancy()->where('name', 'Abuse mailbox')->firstOrFail();

    expect($monitor->customer_id)->toBeNull()
        ->and($monitor->max_per_batch)->toBe(100)
        ->and($monitor->password)->toBe('monitor-secret-2');

    $raw = DB::table('email_box_monitors')->where('id', $monitor->id)->value('password');
    expect($raw)->not->toContain('monitor-secret-2')
        ->and(Crypt::decryptString($raw))->toBe('monitor-secret-2');
});

it('denies monitors to non-permitted admins and customer-guard users', function (): void {
    $this->actingAs(User::factory()->create(), 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->get(EmailBoxMonitorResource::getUrl('index', panel: 'admin'))->assertForbidden();

    auth('admin')->logout();
    $this->actingAs(Customer::factory()->create(), 'customer');
    expect($this->get(EmailBoxMonitorResource::getUrl('index', panel: 'admin'))->status())->not->toBe(200);
});

it('never lets a customer manage another tenant monitor', function (): void {
    $mine = Customer::factory()->create();
    $theirs = Customer::factory()->create();

    $myMonitor = EmailBoxMonitor::factory()->create(['customer_id' => $mine->id]);
    $theirMonitor = EmailBoxMonitor::factory()->create(['customer_id' => $theirs->id]);
    $systemMonitor = EmailBoxMonitor::factory()->system()->create();

    expect(Gate::forUser($mine)->allows('view', $myMonitor))->toBeTrue()
        ->and(Gate::forUser($mine)->allows('view', $theirMonitor))->toBeFalse()
        ->and(Gate::forUser($mine)->allows('view', $systemMonitor))->toBeFalse()
        ->and(Gate::forUser($mine)->allows('update', $theirMonitor))->toBeFalse()
        ->and(Gate::forUser($mine)->allows('create', EmailBoxMonitor::class))->toBeFalse();
});

it('rejects an invalid regex from the conditions form', function (): void {
    AdminTestHelpers::actingAdmin(['servers.manage']);

    $monitor = EmailBoxMonitor::factory()->system()->create();

    Livewire::test(EmailBoxMonitorConditions::class, ['record' => $monitor->getKey()])
        ->callTableAction('create', data: [
            'field' => MonitorConditionField::Subject->value,
            'operator' => MonitorConditionOperator::Regex->value,
            'value' => '/([unclosed/',
            'action' => MonitorAction::Unsubscribe->value,
            'priority' => 10,
        ])
        ->assertHasTableActionErrors(['value']);

    expect(EmailBoxMonitorCondition::query()->count())->toBe(0);
});

it('creates a valid condition from the conditions page', function (): void {
    AdminTestHelpers::actingAdmin(['servers.manage']);

    $monitor = EmailBoxMonitor::factory()->system()->create();

    Livewire::test(EmailBoxMonitorConditions::class, ['record' => $monitor->getKey()])
        ->callTableAction('create', data: [
            'field' => MonitorConditionField::Body->value,
            'operator' => MonitorConditionOperator::Contains->value,
            'value' => 'unsubscribe me',
            'action' => MonitorAction::Blacklist->value,
            'priority' => 3,
            'enabled' => true,
        ])
        ->assertHasNoTableActionErrors();

    $condition = EmailBoxMonitorCondition::query()->firstOrFail();

    expect($condition->email_box_monitor_id)->toBe($monitor->id)
        ->and($condition->field)->toBe(MonitorConditionField::Body)
        ->and($condition->action)->toBe(MonitorAction::Blacklist)
        ->and($condition->priority)->toBe(3);
});

it('renders the monitor processing log page', function (): void {
    AdminTestHelpers::actingAdmin(['servers.manage']);

    $monitor = EmailBoxMonitor::factory()->system()->create(['last_run_at' => now()]);

    EmailBoxMonitorLog::query()->create([
        'email_box_monitor_id' => $monitor->id,
        'from_email' => 'angry@dest.example',
        'subject' => 'Remove me now',
        'matched' => true,
        'action' => MonitorAction::Unsubscribe,
        'result' => 'Unsubscribed angry@dest.example.',
    ]);

    $this->get(EmailBoxMonitorResource::getUrl('logs', ['record' => $monitor], panel: 'admin'))
        ->assertOk()
        ->assertSee('angry@dest.example');
});

it('queues a monitor processing job from the process-now action', function (): void {
    Bus::fake();
    AdminTestHelpers::actingAdmin(['servers.manage']);

    $monitor = EmailBoxMonitor::factory()->system()->create();

    Livewire::test(ListEmailBoxMonitors::class)
        ->callTableAction('process_now', $monitor);

    Bus::assertDispatched(
        ProcessEmailBoxMonitorJob::class,
        fn (ProcessEmailBoxMonitorJob $job): bool => $job->emailBoxMonitorId === $monitor->id,
    );
});
