<?php

declare(strict_types=1);

use App\Enums\SubscriberStatus;
use App\Filament\Customer\Resources\EmailListResource;
use App\Filament\Customer\Resources\EmailListResource\Pages\CreateEmailList;
use App\Filament\Customer\Resources\EmailListResource\Pages\EditEmailList;
use App\Filament\Customer\Resources\EmailListResource\Pages\ListEmailLists;
use App\Filament\Customer\Resources\SubscriberResource;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);
});

it('renders the lists index with per-status subscriber counts', function (): void {
    $list = EmailList::factory()->for($this->customer)->create(['name' => 'Newsletter']);
    Subscriber::factory()->for($list, 'list')->count(2)->create();
    Subscriber::factory()->for($list, 'list')->unconfirmed()->create();
    Subscriber::factory()->for($list, 'list')->unsubscribed()->create();
    Subscriber::factory()->for($list, 'list')->bounced()->create();

    $this->get(EmailListResource::getUrl('index', panel: 'customer'))->assertOk();

    Livewire::test(ListEmailLists::class)
        ->assertCanSeeTableRecords([$list])
        ->assertTableColumnStateSet('confirmed_count', 2, record: $list->getKey())
        ->assertTableColumnStateSet('unconfirmed_count', 1, record: $list->getKey())
        ->assertTableColumnStateSet('unsubscribed_count', 1, record: $list->getKey())
        ->assertTableColumnStateSet('bounced_count', 1, record: $list->getKey());
});

it('searches and filters lists by name, status, date range and tag', function (): void {
    $newsletter = EmailList::factory()->for($this->customer)->create([
        'name' => 'Weekly newsletter',
        'list_tags' => ['news'],
    ]);
    $promo = EmailList::factory()->for($this->customer)->create([
        'name' => 'Promotions',
        'list_tags' => ['sales'],
        'created_at' => now()->subDays(30),
    ]);

    Livewire::test(ListEmailLists::class)
        ->searchTable('Weekly')
        ->assertCanSeeTableRecords([$newsletter])
        ->assertCanNotSeeTableRecords([$promo])
        ->searchTable(null)
        ->filterTable('tag', 'sales')
        ->assertCanSeeTableRecords([$promo])
        ->assertCanNotSeeTableRecords([$newsletter])
        ->resetTableFilters()
        ->filterTable('created', ['created_from' => now()->subDay()->toDateString()])
        ->assertCanSeeTableRecords([$newsletter])
        ->assertCanNotSeeTableRecords([$promo])
        ->resetTableFilters()
        ->filterTable('status', EmailList::STATUS_ACTIVE)
        ->assertCanSeeTableRecords([$newsletter, $promo]);
});

// F-2.2.1-003: Overview, Edit, Subscribers, Import, Export, Forms, Pages,
// Segments, Fields, Copy, Delete — all eleven.
it('exposes the row actions from the spec', function (): void {
    $list = EmailList::factory()->for($this->customer)->create();

    Livewire::test(ListEmailLists::class)
        ->assertTableActionExists('overview')
        ->assertTableActionExists('edit')
        ->assertTableActionExists('subscribers')
        ->assertTableActionExists('fields')
        ->assertTableActionExists('segments')
        ->assertTableActionExists('import')
        ->assertTableActionExists('export')
        ->assertTableActionExists('forms')
        ->assertTableActionExists('pages')
        ->assertTableActionExists('copy')
        ->assertTableActionExists('delete');
});

it('resolves every navigational row action to a page the owner can open (F-2.2.1-003)', function (): void {
    $list = EmailList::factory()->for($this->customer)->create();

    foreach (['overview', 'edit', 'fields', 'segments', 'import', 'forms', 'pages'] as $page) {
        $this->get(EmailListResource::getUrl($page, ['record' => $list], panel: 'customer'))
            ->assertOk();
    }

    // Subscribers is a separate resource pre-filtered to this list.
    $this->get(SubscriberResource::getUrl('index', [
        'tableFilters' => ['list_id' => ['value' => $list->id]],
    ], panel: 'customer'))->assertOk();
});

it('404s every row-action destination for another tenant\'s list (F-2.2.1-003)', function (): void {
    $foreign = EmailList::factory()->create();

    foreach (['overview', 'edit', 'fields', 'segments', 'import', 'forms', 'pages'] as $page) {
        $this->get(EmailListResource::getUrl($page, ['record' => $foreign], panel: 'customer'))
            ->assertNotFound();
    }

    // The subscribers index stays reachable but leaks nothing from that list.
    Subscriber::factory()->for($foreign, 'list')->create(['email' => 'hidden@foreign.example']);

    $this->get(SubscriberResource::getUrl('index', [
        'tableFilters' => ['list_id' => ['value' => $foreign->id]],
    ], panel: 'customer'))
        ->assertOk()
        ->assertDontSee('hidden@foreign.example');
});

it('creates a list through the wizard with the general tab settings', function (): void {
    Livewire::test(CreateEmailList::class)
        ->fillForm([
            'name' => 'Product updates',
            'description' => 'Feature announcements',
            'opt_in' => 'double',
            'opt_out' => 'single',
            'require_approval' => true,
            'import_duplicate_handling' => 'skip',
            'visible' => false,
            'list_tags' => ['product'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $list = EmailList::query()->where('name', 'Product updates')->firstOrFail();

    expect($list->customer_id)->toBe($this->customer->id)
        ->and($list->opt_in->value)->toBe('double')
        ->and($list->require_approval)->toBeTrue()
        ->and($list->import_duplicate_handling)->toBe('skip')
        ->and($list->visible)->toBeFalse()
        ->and($list->list_tags)->toBe(['product'])
        ->and($list->uid)->not->toBeNull();
});

it('persists defaults, notifications, company and advanced settings on edit', function (): void {
    $list = EmailList::factory()->for($this->customer)->create();

    Livewire::test(EditEmailList::class, ['record' => $list->getRouteKey()])
        ->fillForm([
            'from_name' => 'Acme News',
            'from_email' => 'news@acme.example',
            'reply_to' => 'reply@acme.example',
            'default_subject' => 'Hello',
            'subscribe_notify' => true,
            'subscribe_notify_emails' => 'a@acme.example, b@acme.example',
            'company_name' => 'Acme Inc',
            'company_address_1' => '1 Main St',
            'company_city' => 'Metropolis',
            'company_country' => 'US',
            'company_zip' => '10001',
            'autodelete_unconfirmed_days' => 14,
            'autoremove_bounces_count' => 3,
            'optin_confirm_expiry_hours' => 72,
            'gdpr_consent_text' => 'I agree to receive emails.',
            'gdpr_consent_logging' => true,
            'default_language' => 'en',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $list->refresh();

    expect($list->from_name)->toBe('Acme News')
        ->and($list->subscribe_notify)->toBeTrue()
        ->and($list->subscribe_notify_emails)->toBe('a@acme.example, b@acme.example')
        ->and($list->company_name)->toBe('Acme Inc')
        ->and($list->autodelete_unconfirmed_days)->toBe(14)
        ->and($list->autoremove_bounces_count)->toBe(3)
        ->and($list->optin_confirm_expiry_hours)->toBe(72)
        ->and($list->gdpr_consent_logging)->toBeTrue();
});

it('copies the company details from the account settings (F-2.2.2-014)', function (): void {
    $this->customer->update([
        'company' => 'Acme LLC',
        'company_city' => 'Beirut',
        'company_country' => 'LB',
    ]);

    $list = EmailList::factory()->for($this->customer)->create();

    Livewire::test(EditEmailList::class, ['record' => $list->getRouteKey()])
        ->callFormComponentAction('copy_from_accountAction', 'copy_from_account')
        ->assertFormSet([
            'company_name' => 'Acme LLC',
            'company_city' => 'Beirut',
            'company_country' => 'LB',
        ]);
});

it('rejects invalid notification recipient lists', function (): void {
    $list = EmailList::factory()->for($this->customer)->create();

    Livewire::test(EditEmailList::class, ['record' => $list->getRouteKey()])
        ->fillForm([
            'subscribe_notify' => true,
            'subscribe_notify_emails' => 'valid@acme.example, not-an-email',
        ])
        ->call('save')
        ->assertHasFormErrors(['subscribe_notify_emails']);
});

it('stores subscriber actions and enforces them on status transitions', function (): void {
    $other = EmailList::factory()->for($this->customer)->create();
    $list = EmailList::factory()->for($this->customer)->create();

    Livewire::test(EditEmailList::class, ['record' => $list->getRouteKey()])
        ->fillForm(['on_subscribe_subscribe_ids' => [$other->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($list->refresh()->on_subscribe_subscribe_ids)->toBe([$other->id]);

    Subscriber::factory()->for($list, 'list')->create([
        'email' => 'chain@acme.example',
        'status' => SubscriberStatus::Confirmed,
    ]);

    expect(Subscriber::query()
        ->where('list_id', $other->id)
        ->where('email', 'chain@acme.example')
        ->exists())->toBeTrue();
});

it('enforces the max_lists quota on create', function (): void {
    $group = CustomerGroup::factory()->create();
    $group->limits()->create(['key' => 'max_lists', 'value' => 1]);
    $this->customer->update(['customer_group_id' => $group->id]);

    EmailList::factory()->for($this->customer)->create();

    expect($this->customer->refresh()->can('create', EmailList::class))->toBeFalse();

    $this->get(EmailListResource::getUrl('create', panel: 'customer'))->assertForbidden();
});

it('denies access to another tenant\'s list', function (): void {
    $foreign = EmailList::factory()->create(); // different customer

    Livewire::test(ListEmailLists::class)->assertCanNotSeeTableRecords([$foreign]);

    $this->get(EmailListResource::getUrl('edit', ['record' => $foreign], panel: 'customer'))
        ->assertNotFound();
    $this->get(EmailListResource::getUrl('overview', ['record' => $foreign], panel: 'customer'))
        ->assertNotFound();
});
