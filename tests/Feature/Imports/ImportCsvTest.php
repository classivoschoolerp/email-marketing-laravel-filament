<?php

declare(strict_types=1);

use App\Enums\ImportSource;
use App\Enums\ListFieldType;
use App\Enums\SubscriberStatus;
use App\Jobs\Imports\RunImportJob;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ImportJob;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Services\Imports\ImportFileValidator;
use App\Services\Imports\ImportGuard;
use App\Services\Lists\FieldValueService;
use App\Settings\ImportExportSettings;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);
    Storage::fake('local');

    $this->list = EmailList::factory()->for($this->customer)->create();

    $this->makeImport = function (string $csv, array $options, string $filename = 'subscribers.csv'): ImportJob {
        $path = 'customers/'.$this->customer->uid.'/imports/'.$filename;
        Storage::disk('local')->put($path, $csv);

        return ImportJob::create([
            'customer_id' => $this->customer->id,
            'list_id' => $this->list->id,
            'source' => ImportSource::Csv,
            'options' => $options + ['original_filename' => $filename],
            'file_path' => $path,
        ]);
    };

    $this->runImport = fn (ImportJob $import) => (new RunImportJob((int) $import->id))
        ->handle(app(CurrentCustomer::class));
});

// F-2.2.7-001 + F-2.2.7-007 + F-2.2.7-008 + F-2.2.7-011/012 + UTF-8 round-trip.
it('imports a CSV with delimiter auto-detection, mapping, auto-created fields and CJK + emoji values', function (): void {
    $plan = ListField::factory()->for($this->list, 'list')->type(ListFieldType::Dropdown)->create(['tag' => 'PLAN', 'label' => 'Plan']);
    $plan->options()->createMany([
        ['customer_id' => $this->customer->id, 'label' => 'Pro', 'value' => 'pro', 'sort_order' => 0],
        ['customer_id' => $this->customer->id, 'label' => 'Free', 'value' => 'free', 'sort_order' => 1],
    ]);

    // Semicolon-delimited, quoted, CJK + emoji cell (hard rule: must round-trip).
    $csv = "email;\"first name\";plan\n"
        ."alice@example.com;\"山田 太郎 🚀\";pro\n"
        ."bob@example.com;\"Émilie\";free\n"
        ."not-an-email;\"Broken\";pro\n";

    $import = ($this->makeImport)($csv, [
        'has_header' => true,
        // delimiter/enclosure intentionally omitted → auto-detected.
        'mapping' => [0 => 'EMAIL', 1 => 'new:First name', 2 => 'field:PLAN'],
        'auto_create_fields' => true,
        'imported_status' => SubscriberStatus::Confirmed->value,
        'duplicate_strategy' => 'update',
    ]);

    ($this->runImport)($import);
    $import->refresh();

    expect($import->status)->toBe(ImportJob::STATUS_COMPLETED_WITH_ERRORS)
        ->and($import->total_rows)->toBe(3)
        ->and($import->processed_rows)->toBe(3)
        ->and($import->imported_count)->toBe(2)
        ->and($import->failed_count)->toBe(1)
        ->and(((array) $import->options)['delimiter'])->toBe(';');

    // Auto-created Text field from the unmatched header (F-2.2.7-007).
    $firstName = ListField::query()->where('list_id', $this->list->id)->where('tag', 'FIRST_NAME')->first();
    expect($firstName)->not->toBeNull()
        ->and($firstName->type)->toBe(ListFieldType::Text);

    // Chosen status applied (F-2.2.7-008) + CJK/emoji round-trip.
    $alice = Subscriber::query()->where('email', 'alice@example.com')->firstOrFail();
    expect($alice->status)->toBe(SubscriberStatus::Confirmed)
        ->and($alice->source->value)->toBe('import')
        ->and(app(FieldValueService::class)->get($alice, $firstName))->toBe('山田 太郎 🚀')
        ->and(app(FieldValueService::class)->get($alice, $plan))->toBe('pro');

    // Error CSV written for the invalid row (F-2.2.7-012).
    expect($import->error_file_path)->not->toBeNull()
        ->and(Storage::disk('local')->get($import->error_file_path))->toContain('not-an-email');
});

// F-2.2.7-009: update vs skip vs error on duplicates.
it('applies the duplicate strategy update, skip or error', function (): void {
    ListField::factory()->for($this->list, 'list')->create(['tag' => 'CITY', 'label' => 'City']);

    $existing = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'dup@example.com']);

    $csv = "email,city\ndup@example.com,Berlin\n";

    // update
    $import = ($this->makeImport)($csv, [
        'has_header' => true,
        'mapping' => [0 => 'EMAIL', 1 => 'field:CITY'],
        'imported_status' => 'unconfirmed',
        'duplicate_strategy' => 'update',
    ], 'a.csv');
    ($this->runImport)($import);

    $city = ListField::query()->where('tag', 'CITY')->firstOrFail();
    expect($import->refresh()->updated_count)->toBe(1)
        ->and(app(FieldValueService::class)->get($existing->refresh(), $city))->toBe('Berlin')
        ->and($existing->status)->toBe(SubscriberStatus::Confirmed); // status untouched on update

    // skip
    $import = ($this->makeImport)($csv, [
        'has_header' => true,
        'mapping' => [0 => 'EMAIL', 1 => 'field:CITY'],
        'imported_status' => 'unconfirmed',
        'duplicate_strategy' => 'skip',
    ], 'b.csv');
    ($this->runImport)($import);
    expect($import->refresh()->skipped_count)->toBe(1)->and($import->status)->toBe(ImportJob::STATUS_COMPLETED);

    // error
    $import = ($this->makeImport)($csv, [
        'has_header' => true,
        'mapping' => [0 => 'EMAIL', 1 => 'field:CITY'],
        'imported_status' => 'unconfirmed',
        'duplicate_strategy' => 'error',
    ], 'c.csv');
    ($this->runImport)($import);
    expect($import->refresh()->failed_count)->toBe(1)
        ->and(Storage::disk('local')->get($import->error_file_path))->toContain('dup@example.com');
});

// F-2.2.7-010: the ImportGuard hook (Null guard until WP-16) skips suppressed addresses.
it('skips addresses vetoed by the ImportGuard suppression hook', function (): void {
    app()->instance(ImportGuard::class, new class implements ImportGuard
    {
        public function shouldSkip(string $email, EmailList $list): bool
        {
            return $email === 'blocked@example.com';
        }
    });

    $csv = "blocked@example.com\nok@example.com\n";

    $import = ($this->makeImport)($csv, [
        'has_header' => false,
        'mapping' => [0 => 'EMAIL'],
        'imported_status' => 'unconfirmed',
        'duplicate_strategy' => 'update',
    ]);
    ($this->runImport)($import);

    expect($import->refresh()->skipped_count)->toBe(1)
        ->and($import->imported_count)->toBe(1)
        ->and(Subscriber::query()->where('email', 'blocked@example.com')->exists())->toBeFalse()
        ->and(Subscriber::query()->where('email', 'ok@example.com')->exists())->toBeTrue();
});

// F-2.2.7-015: §3.25.2 max size + allowed extensions enforced.
it('enforces the max file size and allowed extensions from admin settings', function (): void {
    $settings = app(ImportExportSettings::class);
    $settings->max_import_file_size_mb = 1;
    $settings->save();

    $validator = app(ImportFileValidator::class);

    expect($validator->validate('big.csv', 2 * 1024 * 1024))->not->toBeEmpty()
        ->and($validator->validate('fine.csv', 512 * 1024))->toBeEmpty()
        ->and($validator->validate('nope.exe', 10))->not->toBeEmpty();

    // The queued job re-checks the stored file (never trusts the UI).
    $import = ($this->makeImport)(str_repeat("someone@example.com\n", 60000), [
        'has_header' => false,
        'mapping' => [0 => 'EMAIL'],
        'imported_status' => 'unconfirmed',
        'duplicate_strategy' => 'update',
    ]);
    ($this->runImport)($import);

    expect($import->refresh()->status)->toBe(ImportJob::STATUS_FAILED)
        ->and($import->imported_count)->toBe(0);

    // Disallowed extension fails even when the size is fine.
    $import = ($this->makeImport)("x@example.com\n", [
        'has_header' => false,
        'mapping' => [0 => 'EMAIL'],
        'imported_status' => 'unconfirmed',
        'duplicate_strategy' => 'update',
    ], 'subscribers.exe');
    ($this->runImport)($import);

    expect($import->refresh()->status)->toBe(ImportJob::STATUS_FAILED);
});

// F-2.2.7-011: live progress + ETA helpers.
it('reports progress percent and an ETA while processing', function (): void {
    $import = ImportJob::create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'source' => ImportSource::Csv,
        'status' => ImportJob::STATUS_PROCESSING,
        'total_rows' => 200,
        'processed_rows' => 50,
        'started_at' => now()->subSeconds(100),
    ]);

    expect($import->progressPercent())->toBe(25)
        ->and($import->etaSeconds())->toBeGreaterThan(0);

    $import->status = ImportJob::STATUS_COMPLETED;
    expect($import->progressPercent())->toBe(100)->and($import->etaSeconds())->toBeNull();
});

// Cross-tenant denial: customer B cannot import into A's list.
it('denies another tenant access to the import page and error report', function (): void {
    $import = ($this->makeImport)("x@example.com\n", [
        'has_header' => false,
        'mapping' => [0 => 'EMAIL'],
        'imported_status' => 'unconfirmed',
        'duplicate_strategy' => 'update',
    ]);
    ($this->runImport)($import);

    $errorImport = ImportJob::create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'source' => ImportSource::Csv,
        'error_file_path' => 'customers/'.$this->customer->uid.'/imports/errors.csv',
    ]);
    Storage::disk('local')->put($errorImport->error_file_path, "line,email,errors\n");

    $importPageUrl = \App\Filament\Customer\Resources\EmailListResource::getUrl('import', ['record' => $this->list]);
    $historyUrl = \App\Filament\Customer\Resources\EmailListResource::getUrl('imports', ['record' => $this->list]);

    // The owner passes.
    $this->get(route('lists.imports.errors', ['uid' => $errorImport->uid]))->assertOk();

    // Customer B is denied everywhere.
    $other = Customer::factory()->create();
    $this->actingAs($other, 'customer');
    app(CurrentCustomer::class)->set($other);

    $this->get($importPageUrl)->assertNotFound();
    $this->get($historyUrl)->assertNotFound();
    $this->get(route('lists.imports.errors', ['uid' => $errorImport->uid]))->assertForbidden();
});
