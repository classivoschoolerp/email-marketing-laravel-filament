<?php

declare(strict_types=1);

use App\Enums\ImportSource;
use App\Jobs\Imports\RunImportJob;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ImportJob;
use App\Settings\ImportExportSettings;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;

/**
 * F-2.2.7-015 / F-3.25.2-002 — the admin import memory setting grants head-room;
 * it must never clamp the running process down. Regression guard: a worker (or
 * a long test run) already using more than the configured value made ini_set()
 * throw, failing the import for a reason unrelated to the import.
 */
beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);
    Storage::fake('local');

    $this->list = EmailList::factory()->for($this->customer)->create();
});

it('imports successfully when the configured memory limit is below current usage', function (): void {
    $settings = app(ImportExportSettings::class);
    $settings->import_memory_limit_mb = 1; // absurdly low — must be ignored
    $settings->save();

    $before = ini_get('memory_limit');

    $path = 'customers/'.$this->customer->uid.'/imports/mem.csv';
    Storage::disk('local')->put($path, "email\nmem-test@example.com\n");

    $import = ImportJob::create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'source' => ImportSource::Csv,
        'file_path' => $path,
        'options' => [
            'has_header' => true,
            'mapping' => [0 => 'EMAIL'],
            'imported_status' => 'confirmed',
            'duplicate_strategy' => 'update',
            'original_filename' => 'mem.csv',
        ],
    ]);

    (new RunImportJob((int) $import->id))->handle(app(CurrentCustomer::class));

    expect($import->refresh()->status)->not->toBe(ImportJob::STATUS_FAILED)
        ->and($this->list->subscribers()->where('email', 'mem-test@example.com')->exists())->toBeTrue()
        // the ceiling is left exactly as it was found
        ->and(ini_get('memory_limit'))->toBe($before);
});
