<?php

declare(strict_types=1);

use App\Enums\ImportSource;
use App\Enums\ListFieldType;
use App\Enums\SubscriberStatus;
use App\Filament\Customer\Resources\EmailListResource\Pages\ImportSubscribers;
use App\Jobs\Imports\RunImportJob;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ImportJob;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Services\Lists\FieldValueService;
use App\Settings\ImportExportSettings;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);
    Storage::fake('local');

    $this->list = EmailList::factory()->for($this->customer)->create();

    $this->runImport = fn (ImportJob $import) => (new RunImportJob((int) $import->id))
        ->handle(app(CurrentCustomer::class));

    $this->settings = function (array $overrides): void {
        $settings = app(ImportExportSettings::class);

        foreach ($overrides as $key => $value) {
            $settings->{$key} = $value;
        }

        $settings->save();
    };
});

// F-2.2.7-002: paste-text import through the Filament page → queued job.
it('imports pasted comma/newline separated addresses through the import page', function (): void {
    Queue::fake();

    Livewire::test(ImportSubscribers::class, ['record' => $this->list->getRouteKey()])
        ->fillForm([
            'source' => ImportSource::Paste->value,
            'paste_text' => "one@example.com, two@example.com\nthree@example.com",
            'imported_status' => SubscriberStatus::Confirmed->value,
            'duplicate_strategy' => 'update',
        ])
        ->call('startImport')
        ->assertHasNoFormErrors();

    $import = ImportJob::query()->where('list_id', $this->list->id)->firstOrFail();
    expect($import->source)->toBe(ImportSource::Paste)
        ->and($import->file_path)->not->toBeNull();

    Queue::assertPushed(RunImportJob::class, fn (RunImportJob $job): bool => $job->importJobId === $import->id
        && $job->queue === \App\Support\Queue\Queues::IMPORTS);

    // Now execute the queued run.
    ($this->runImport)($import);

    expect($import->refresh()->imported_count)->toBe(3)
        ->and(Subscriber::query()->where('list_id', $this->list->id)->count())->toBe(3)
        ->and(Subscriber::query()->where('email', 'two@example.com')->firstOrFail()->status)
        ->toBe(SubscriberStatus::Confirmed);
});

// F-2.2.7-003: import from URL, gated by the §3.25.2 toggle.
it('imports a remote CSV from a URL when enabled and fails when disabled', function (): void {
    ($this->settings)(['import_from_url_enabled' => true]);

    Http::fake([
        'files.example.com/*' => Http::response("email,first_name\nurl1@example.com,Ann\nurl2@example.com,Ben\n"),
    ]);

    $import = ImportJob::create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'source' => ImportSource::Url,
        'options' => [
            'url' => 'https://files.example.com/subscribers.csv',
            'auto_create_fields' => true,
            'imported_status' => 'unconfirmed',
            'duplicate_strategy' => 'update',
        ],
    ]);
    ($this->runImport)($import);

    expect($import->refresh()->status)->toBe(ImportJob::STATUS_COMPLETED)
        ->and($import->imported_count)->toBe(2)
        ->and(ListField::query()->where('list_id', $this->list->id)->where('tag', 'FIRST_NAME')->exists())->toBeTrue();

    // Toggle off → the job refuses to fetch (F-3.25.2-004).
    ($this->settings)(['import_from_url_enabled' => false]);

    $blocked = ImportJob::create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'source' => ImportSource::Url,
        'options' => ['url' => 'https://files.example.com/subscribers.csv', 'imported_status' => 'unconfirmed'],
    ]);
    ($this->runImport)($blocked);

    expect($blocked->refresh()->status)->toBe(ImportJob::STATUS_FAILED)
        ->and($blocked->message)->toBe(__('imports.errors.url_disabled'));
});

// URL import without auto-create leaves unmatched columns unmapped.
it('skips unmatched URL columns when auto-create is off', function (): void {
    ($this->settings)(['import_from_url_enabled' => true]);

    Http::fake([
        'files.example.com/*' => Http::response("email,nickname\nurl3@example.com,Speedy\n"),
    ]);

    $import = ImportJob::create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'source' => ImportSource::Url,
        'options' => [
            'url' => 'https://files.example.com/plain.csv',
            'auto_create_fields' => false,
            'imported_status' => 'unconfirmed',
        ],
    ]);
    ($this->runImport)($import);

    expect($import->refresh()->imported_count)->toBe(1)
        ->and(ListField::query()->where('list_id', $this->list->id)->where('tag', 'NICKNAME')->exists())->toBeFalse();
});

// F-2.2.7-004: import from another list of the same account.
it('imports active subscribers from another list, cloning missing fields', function (): void {
    $source = EmailList::factory()->for($this->customer)->create();
    $field = ListField::factory()->for($source, 'list')->create(['tag' => 'FNAME', 'label' => 'First name']);

    $active = Subscriber::factory()->for($source, 'list')->create(['email' => 'active@example.com']);
    app(FieldValueService::class)->set($active, $field, 'Ada');
    Subscriber::factory()->for($source, 'list')->unsubscribed()->create(['email' => 'gone@example.com']);

    $import = ImportJob::create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'source' => ImportSource::List,
        'options' => [
            'source_list_id' => $source->id,
            'auto_create_fields' => true,
            'imported_status' => 'confirmed',
            'duplicate_strategy' => 'update',
        ],
    ]);
    ($this->runImport)($import);

    $import->refresh();
    expect($import->status)->toBe(ImportJob::STATUS_COMPLETED)
        ->and($import->total_rows)->toBe(1) // unsubscribed members are not resurrected
        ->and($import->imported_count)->toBe(1);

    $clonedField = ListField::query()->where('list_id', $this->list->id)->where('tag', 'FNAME')->firstOrFail();
    $imported = Subscriber::query()->where('list_id', $this->list->id)->where('email', 'active@example.com')->firstOrFail();

    expect(app(FieldValueService::class)->get($imported, $clonedField))->toBe('Ada')
        ->and(Subscriber::query()->where('list_id', $this->list->id)->where('email', 'gone@example.com')->exists())->toBeFalse();
});

// Cross-tenant: another customer's list can never be an import source.
it('refuses a foreign list as the import source', function (): void {
    $foreign = EmailList::factory()->create(); // other tenant

    $import = ImportJob::create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'source' => ImportSource::List,
        'options' => ['source_list_id' => $foreign->id, 'imported_status' => 'confirmed'],
    ]);
    ($this->runImport)($import);

    expect($import->refresh()->status)->toBe(ImportJob::STATUS_FAILED)
        ->and($import->message)->toBe(__('imports.errors.source_list_invalid'))
        ->and(Subscriber::query()->where('list_id', $this->list->id)->count())->toBe(0);
});

/*
 * F-2.2.7-005: import from an external SQL database. Production uses the
 * MySQL driver with host/db/user/pass from the form; the test exercises the
 * identical reader/job code path through a SQLite file posing as the source
 * (no MySQL server exists in this environment — D-03).
 */
it('imports from an external database connection, gated by the settings toggle', function (): void {
    $dbFile = tempnam(sys_get_temp_dir(), 'empimp');
    $pdo = new PDO('sqlite:'.$dbFile);
    $pdo->exec('CREATE TABLE ext_subscribers (email TEXT, first_name TEXT)');
    $pdo->exec("INSERT INTO ext_subscribers VALUES ('db1@example.com', '田中 花子 🌸'), ('db2@example.com', 'Omar'), ('broken', 'X')");
    unset($pdo);

    $options = [
        'database' => ['driver' => 'sqlite', 'database' => $dbFile, 'table' => 'ext_subscribers'],
        'auto_create_fields' => true,
        'imported_status' => 'unconfirmed',
        'duplicate_strategy' => 'update',
    ];

    // Toggle off → refused (F-3.25.2-004).
    $blocked = ImportJob::create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'source' => ImportSource::Database,
        'options' => $options,
    ]);
    ($this->runImport)($blocked);
    expect($blocked->refresh()->status)->toBe(ImportJob::STATUS_FAILED)
        ->and($blocked->message)->toBe(__('imports.errors.database_disabled'));

    // Toggle on → columns map by name, unmatched auto-create, UTF-8 intact.
    ($this->settings)(['import_from_database_enabled' => true]);

    $import = ImportJob::create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'source' => ImportSource::Database,
        'options' => $options,
    ]);
    ($this->runImport)($import);

    $import->refresh();
    expect($import->status)->toBe(ImportJob::STATUS_COMPLETED_WITH_ERRORS)
        ->and($import->total_rows)->toBe(3)
        ->and($import->imported_count)->toBe(2)
        ->and($import->failed_count)->toBe(1);

    $field = ListField::query()->where('list_id', $this->list->id)->where('tag', 'FIRST_NAME')->firstOrFail();
    $sub = Subscriber::query()->where('email', 'db1@example.com')->firstOrFail();
    expect(app(FieldValueService::class)->get($sub, $field))->toBe('田中 花子 🌸')
        ->and($field->type)->toBe(ListFieldType::Text);

    @unlink($dbFile);
});
