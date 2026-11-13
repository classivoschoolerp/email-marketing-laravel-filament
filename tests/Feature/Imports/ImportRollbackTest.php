<?php

declare(strict_types=1);

use App\Enums\ImportSource;
use App\Filament\Customer\Resources\EmailListResource\Pages\ListImports;
use App\Jobs\Imports\RollbackImportJob;
use App\Jobs\Imports\RunImportJob;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ImportJob;
use App\Models\ImportJobSubscriber;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Services\Imports\ImportFailedException;
use App\Services\Imports\ImportHistoryService;
use App\Services\Lists\FieldValueService;
use App\Support\Queue\Queues;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
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
    $this->field = ListField::factory()->for($this->list, 'list')->create(['tag' => 'FNAME', 'label' => 'First name']);

    $this->runImport = fn (ImportJob $import) => (new RunImportJob((int) $import->id))
        ->handle(app(CurrentCustomer::class));

    // A CSV import run that both CREATES and UPDATES a subscriber.
    $this->csvImport = function (string $csv): ImportJob {
        $path = 'customers/'.$this->customer->uid.'/imports/rollback-'.str()->random(6).'.csv';
        Storage::disk('local')->put($path, $csv);

        return ImportJob::create([
            'customer_id' => $this->customer->id,
            'list_id' => $this->list->id,
            'source' => ImportSource::Csv,
            'file_path' => $path,
            'options' => [
                'has_header' => true,
                'delimiter' => ',',
                'enclosure' => '"',
                'mapping' => [0 => 'EMAIL', 1 => 'field:FNAME'],
                'imported_status' => 'confirmed',
                'duplicate_strategy' => 'update',
            ],
        ]);
    };
});

/*
 * F-2.2.7-013 rollback. Scope shipped, asserted exactly:
 *   created rows are force-deleted; updated rows get the field values the
 *   import overwrote restored; a row that already existed is NOT deleted.
 */
it('F-2.2.7-013 rolls back an import: created rows deleted, updated rows restored', function (): void {
    $existing = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'old@example.com']);
    app(FieldValueService::class)->set($existing, $this->field, 'BeforeImport');

    $untouched = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'untouched@example.com']);
    app(FieldValueService::class)->set($untouched, $this->field, 'Never');

    $import = ($this->csvImport)("email,first name\nnew@example.com,Fresh\nold@example.com,Overwritten\n");
    ($this->runImport)($import);

    $import->refresh();
    expect($import->imported_count)->toBe(1)
        ->and($import->updated_count)->toBe(1)
        ->and(app(FieldValueService::class)->get($existing->refresh(), $this->field))->toBe('Overwritten');

    // Provenance was recorded for exactly the two touched rows.
    $ledger = ImportJobSubscriber::query()->where('import_job_id', $import->id)->get();
    expect($ledger)->toHaveCount(2)
        ->and($ledger->where('action', ImportJobSubscriber::ACTION_CREATED)->count())->toBe(1)
        ->and($ledger->firstWhere('action', ImportJobSubscriber::ACTION_UPDATED)->previous_values)
        ->toBe(['FNAME' => 'BeforeImport']);

    (new RollbackImportJob((int) $import->id))->handle(app(CurrentCustomer::class));

    $import->refresh();

    expect($import->rolled_back_at)->not->toBeNull()
        ->and($import->rolled_back_count)->toBe(2)
        // created → gone for real, not soft-deleted
        ->and(Subscriber::withTrashed()->where('email', 'new@example.com')->count())->toBe(0)
        // updated → the overwritten value is back
        ->and(app(FieldValueService::class)->get($existing->refresh(), $this->field))->toBe('BeforeImport')
        // never touched by the import → untouched by the rollback
        ->and(app(FieldValueService::class)->get($untouched->refresh(), $this->field))->toBe('Never')
        // the auto-created/pre-existing list field survives (documented scope)
        ->and(ListField::query()->whereKey($this->field->id)->exists())->toBeTrue()
        // provenance is consumed, so the run can only be rolled back once
        ->and(ImportJobSubscriber::query()->where('import_job_id', $import->id)->count())->toBe(0);
});

// F-2.2.7-013: a field the import CREATED on a row that had none is removed
// again, not left behind as a blank-but-present value row.
it('F-2.2.7-013 deletes field values the import added to a previously empty field', function (): void {
    $existing = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'blank@example.com']);

    $import = ($this->csvImport)("email,first name\nblank@example.com,Added\n");
    ($this->runImport)($import);

    expect(app(FieldValueService::class)->get($existing->refresh(), $this->field))->toBe('Added')
        ->and(ImportJobSubscriber::query()->where('import_job_id', $import->id)->first()->previous_values)
        ->toBe(['FNAME' => null]);

    (new RollbackImportJob((int) $import->id))->handle(app(CurrentCustomer::class));

    expect(app(FieldValueService::class)->get($existing->refresh(), $this->field))->toBeNull()
        ->and($existing->fieldValues()->count())->toBe(0);
});

// F-2.2.7-013: rollback is heavy → queued, and the history row is badged.
it('F-2.2.7-013 queues the rollback from the import history page', function (): void {
    Queue::fake();

    $import = ImportJob::create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'source' => ImportSource::Csv,
        'status' => ImportJob::STATUS_COMPLETED,
        'options' => ['imported_status' => 'confirmed'],
        'imported_count' => 5,
        'finished_at' => now(),
    ]);

    Livewire::test(ListImports::class, ['record' => $this->list->getRouteKey()])
        ->assertSuccessful()
        ->assertTableActionVisible('rollback', $import)
        ->callTableAction('rollback', $import);

    Queue::assertPushed(RollbackImportJob::class, fn (RollbackImportJob $job): bool => $job->importJobId === (int) $import->id
        && $job->queue === Queues::IMPORTS);

    // Once rolled back the action is gone — an import undoes exactly once.
    $import->update(['rolled_back_at' => now(), 'rolled_back_count' => 5]);

    Livewire::test(ListImports::class, ['record' => $this->list->getRouteKey()])
        ->assertTableActionHidden('rollback', $import->refresh());
});

// F-2.2.7-013: re-run clones source + mapping + options into a fresh queued run.
it('F-2.2.7-013 re-runs a finished import from its stored source and mapping', function (): void {
    Queue::fake();

    $path = 'customers/'.$this->customer->uid.'/imports/kept.csv';
    Storage::disk('local')->put($path, "email\nrerun@example.com\n");

    $finished = ImportJob::create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'source' => ImportSource::Csv,
        'status' => ImportJob::STATUS_COMPLETED,
        'file_path' => $path,
        'options' => ['has_header' => true, 'mapping' => [0 => 'EMAIL'], 'imported_status' => 'confirmed'],
        'imported_count' => 7,
        'finished_at' => now(),
    ]);

    Livewire::test(ListImports::class, ['record' => $this->list->getRouteKey()])
        ->callTableAction('rerun', $finished);

    $rerun = ImportJob::query()->where('list_id', $this->list->id)->whereKeyNot($finished->id)->firstOrFail();

    expect($rerun->status)->toBe(ImportJob::STATUS_PENDING)
        ->and($rerun->processed_rows)->toBe(0)
        ->and($rerun->imported_count)->toBe(0)
        ->and($rerun->file_path)->toBe($path)
        ->and($rerun->options)->toBe($finished->options)
        ->and($rerun->uid)->not->toBe($finished->uid);

    Queue::assertPushed(RunImportJob::class, fn (RunImportJob $job): bool => $job->importJobId === (int) $rerun->id);
});

// F-2.2.7-013: a file-backed re-run whose upload is gone is REFUSED with a
// translated reason — no queued run that would only fail later.
it('F-2.2.7-013 refuses to re-run a CSV import whose source file no longer exists', function (): void {
    Queue::fake();

    $finished = ImportJob::create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'source' => ImportSource::Csv,
        'status' => ImportJob::STATUS_COMPLETED,
        'file_path' => 'customers/'.$this->customer->uid.'/imports/pruned.csv',
        'options' => ['mapping' => [0 => 'EMAIL'], 'imported_status' => 'confirmed'],
        'finished_at' => now(),
    ]);

    expect(fn () => app(ImportHistoryService::class)->rerun($finished))
        ->toThrow(ImportFailedException::class, __('imports.errors.rerun_file_missing'));

    Livewire::test(ListImports::class, ['record' => $this->list->getRouteKey()])
        ->callTableAction('rerun', $finished)
        ->assertNotified(__('imports.errors.rerun_file_missing'));

    expect(ImportJob::query()->count())->toBe(1);
    Queue::assertNotPushed(RunImportJob::class);
});

// F-2.2.7-013: a self-refetching source re-fetches instead of replaying the
// snapshot file the previous run downloaded.
it('F-2.2.7-013 clears the downloaded file when re-running a URL or ESP import', function (): void {
    Queue::fake();

    foreach ([ImportSource::Url, ImportSource::Esp] as $source) {
        $finished = ImportJob::create([
            'customer_id' => $this->customer->id,
            'list_id' => $this->list->id,
            'source' => $source,
            'status' => ImportJob::STATUS_COMPLETED,
            'file_path' => 'customers/'.$this->customer->uid.'/imports/snapshot-'.$source->value.'.csv',
            'options' => ['imported_status' => 'confirmed'],
            'finished_at' => now(),
        ]);

        expect(app(ImportHistoryService::class)->rerun($finished)->file_path)->toBeNull();
    }
});

// Cross-tenant: neither re-run nor rollback may touch another tenant's run.
it('F-2.2.7-013 refuses to re-run or roll back another tenant\'s import', function (): void {
    Queue::fake();

    $other = Customer::factory()->create();
    $otherList = EmailList::factory()->for($other)->create();

    $foreign = ImportJob::withoutTenancy()->create([
        'customer_id' => $other->id,
        'list_id' => $otherList->id,
        'source' => ImportSource::Csv,
        'status' => ImportJob::STATUS_COMPLETED,
        'options' => ['imported_status' => 'confirmed'],
        'imported_count' => 3,
        'finished_at' => now(),
    ]);

    // Still acting as $this->customer from beforeEach().
    expect(fn () => app(ImportHistoryService::class)->rerun($foreign))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ImportHistoryService::class)->rollback($foreign))->toThrow(AuthorizationException::class);

    expect(ImportJob::withoutTenancy()->count())->toBe(1);
    Queue::assertNotPushed(RunImportJob::class);
    Queue::assertNotPushed(RollbackImportJob::class);

    // And it is not even listed on this tenant's history page.
    Livewire::test(ListImports::class, ['record' => $this->list->getRouteKey()])
        ->assertCanNotSeeTableRecords([$foreign]);
});
