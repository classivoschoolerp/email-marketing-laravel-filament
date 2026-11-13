<?php

declare(strict_types=1);

use App\Enums\ImportSource;
use App\Filament\Customer\Resources\EmailListResource\Pages\ImportSubscribers;
use App\Filament\Customer\Resources\EmailListResource\Pages\ListImports;
use App\Jobs\Imports\RunImportJob;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ImportJob;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
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
});

// F-2.2.7-011/012: history log page with live progress + re-run.
it('lists import runs with their summary and re-runs a finished import', function (): void {
    Queue::fake();

    // WP-42 (F-2.2.7-013): re-running a file-backed source requires the
    // original upload to still be on disk — a pruned file is refused with a
    // translated error instead of queueing a run that can only fail. See the
    // refusal case in ImportRollbackTest.
    Storage::disk('local')->put('customers/'.$this->customer->uid.'/imports/prev.csv', "email\nprev@example.com\n");

    $finished = ImportJob::create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'source' => ImportSource::Csv,
        'status' => ImportJob::STATUS_COMPLETED,
        'options' => ['has_header' => false, 'mapping' => [0 => 'EMAIL'], 'imported_status' => 'confirmed'],
        'file_path' => 'customers/'.$this->customer->uid.'/imports/prev.csv',
        'total_rows' => 10,
        'processed_rows' => 10,
        'imported_count' => 8,
        'updated_count' => 1,
        'skipped_count' => 1,
        'finished_at' => now(),
    ]);

    Livewire::test(ListImports::class, ['record' => $this->list->getRouteKey()])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$finished])
        ->callTableAction('rerun', $finished);

    $rerun = ImportJob::query()
        ->where('list_id', $this->list->id)
        ->whereKeyNot($finished->id)
        ->firstOrFail();

    expect($rerun->status)->toBe(ImportJob::STATUS_PENDING)
        ->and($rerun->processed_rows)->toBe(0)
        ->and($rerun->file_path)->toBe($finished->file_path)
        ->and($rerun->options)->toBe($finished->options);

    Queue::assertPushed(RunImportJob::class, fn (RunImportJob $job): bool => $job->importJobId === $rerun->id);
});

// F-2.2.7-001: the CSV upload path through the Filament page, including the
// reactive analyze step that pre-fills delimiter/header/mapping.
it('uploads a CSV through the import page, auto-suggests the mapping and queues the run', function (): void {
    Queue::fake();

    $csv = "email,first name\npage1@example.com,Zoe\n";
    $upload = UploadedFile::fake()->createWithContent('subs.csv', $csv);

    $component = Livewire::test(ImportSubscribers::class, ['record' => $this->list->getRouteKey()])
        ->fillForm([
            'source' => ImportSource::Csv->value,
            'file' => $upload,
            'imported_status' => 'unconfirmed',
            'duplicate_strategy' => 'update',
            'auto_create_fields' => true,
        ]);

    // The analyzer pre-filled the mapping from the header row.
    $component->assertFormSet([
        'has_header' => true,
        'delimiter' => ',',
        'mapping.0' => 'EMAIL',
        'mapping.1' => 'new:first name',
    ]);

    $component->call('startImport')->assertHasNoFormErrors();

    $import = ImportJob::query()->where('list_id', $this->list->id)->firstOrFail();

    expect($import->source)->toBe(ImportSource::Csv)
        ->and(((array) $import->options)['mapping'])->toBe([0 => 'EMAIL', 1 => 'new:first name'])
        ->and(((array) $import->options)['original_filename'])->toBe('subs.csv')
        ->and(Storage::disk('local')->exists((string) $import->file_path))->toBeTrue();

    Queue::assertPushed(RunImportJob::class);
});

// F-2.2.7-015: an upload with a disallowed extension is rejected at submit.
it('rejects an upload whose extension is not allowed by settings', function (): void {
    Queue::fake();

    $upload = UploadedFile::fake()->createWithContent('subs.tsv', "email\na@example.com\n");

    Livewire::test(ImportSubscribers::class, ['record' => $this->list->getRouteKey()])
        ->fillForm([
            'source' => ImportSource::Csv->value,
            'file' => $upload,
            'imported_status' => 'unconfirmed',
            'duplicate_strategy' => 'update',
        ])
        ->call('startImport')
        ->assertHasErrors(['data.file']);

    expect(ImportJob::query()->count())->toBe(0);
    Queue::assertNotPushed(RunImportJob::class);
});
