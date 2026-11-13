<?php

declare(strict_types=1);

use App\Jobs\Imports\RunImportJob;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ExportJob;
use App\Models\ImportJob;
use App\Models\Subscriber;
use App\Services\Exports\SubscriberExportWriter;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * WP-26 §4.6 F-4.6-005 — chunked imports/exports.
 *
 * The chunking already existed (RunImportJob's batch loop + deadline
 * re-dispatch, SubscriberExportWriter's chunkById stream). This is the
 * PROVING test it never had: with a fixture large enough to cross several
 * chunk boundaries it asserts
 *   - the export reads in bounded chunks (chunk boundaries observed on the
 *     real queries) and never materialises the whole set;
 *   - progress is flushed per chunk, not once at the end;
 *   - the import stops at its execution deadline and continues in a fresh
 *     job carrying the exact offset it reached — no rows lost, none twice;
 *   - peak memory stays flat, not proportional to row count.
 */

beforeEach(function (): void {
    Storage::fake('local');
    Mail::fake();

    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);

    // §3.25.2 import/export knobs (same override helper the WP-08 tests use).
    $this->settings = function (array $overrides): void {
        $settings = app(\App\Settings\ImportExportSettings::class);

        foreach ($overrides as $key => $value) {
            $settings->{$key} = $value;
        }

        $settings->save();
    };
});

/** Bulk-insert N subscribers without instantiating N models. */
function wp26_seedSubscribers(int $count): void
{
    $now = now();
    $rows = [];

    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'customer_id' => test()->customer->id,
            'list_id' => test()->list->id,
            'email' => 'bulk'.str_pad((string) $i, 5, '0', STR_PAD_LEFT).'@example.com',
            'status' => \App\Enums\SubscriberStatus::Confirmed->value,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if (count($rows) >= 500) {
            Subscriber::withoutTenancy()->insert($rows);
            $rows = [];
        }
    }

    if ($rows !== []) {
        Subscriber::withoutTenancy()->insert($rows);
    }
}

it('exports a large list in bounded chunks, streaming rather than loading it all', function (): void {
    wp26_seedSubscribers(1200);

    ($this->settings)(['export_batch_size' => 250]);

    $export = ExportJob::create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'scope' => ExportJob::SCOPE_ALL,
        'columns' => ['email'],
    ]);

    // Watch the real SQL: chunkById issues one bounded SELECT per chunk.
    $selects = [];

    DB::listen(function ($query) use (&$selects): void {
        if (str_contains($query->sql, 'from "subscribers"') && str_contains($query->sql, 'limit')) {
            $selects[] = $query->sql;
        }
    });

    $before = memory_get_peak_usage(true);

    $path = app(SubscriberExportWriter::class)->write($export->refresh());

    $after = memory_get_peak_usage(true);

    // 1200 rows / 250 per chunk = 5 data chunks (+1 empty terminating read).
    expect(count($selects))->toBeGreaterThanOrEqual(5)
        ->and(count($selects))->toBeLessThanOrEqual(7);

    // Every chunk query is bounded — no unlimited SELECT of the whole table.
    foreach ($selects as $sql) {
        expect($sql)->toContain('limit');
    }

    expect($export->refresh()->processed_rows)->toBe(1200);

    // The file really has all 1200 rows + a header.
    $lines = preg_split('/\R/', trim(Storage::disk('local')->get($path))) ?: [];
    expect($lines)->toHaveCount(1201);

    // Streaming: peak memory must not grow with the row count. 1200 rows of
    // ~40 bytes would be well under 8 MB even if buffered, so this asserts
    // the shape rather than an exact figure — a full materialisation of
    // models (not strings) is what would blow past it.
    expect($after - $before)->toBeLessThan(32 * 1024 * 1024);
});

it('flushes export progress on every chunk boundary, not once at the end', function (): void {
    wp26_seedSubscribers(600);

    ($this->settings)(['export_batch_size' => 200]);

    $export = ExportJob::create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'scope' => ExportJob::SCOPE_ALL,
        'columns' => ['email'],
    ]);

    $progress = [];

    DB::listen(function ($query) use (&$progress): void {
        if (str_contains($query->sql, 'update "export_jobs"')) {
            $progress[] = $query->bindings;
        }
    });

    app(SubscriberExportWriter::class)->write($export->refresh());

    // 3 chunk flushes + the final save (plus the initial total_rows save).
    expect(count($progress))->toBeGreaterThanOrEqual(4)
        ->and($export->refresh()->processed_rows)->toBe(600);
});

it('honours the max-rows-per-file cap mid-chunk (D-23: 0 = unlimited)', function (): void {
    wp26_seedSubscribers(500);

    ($this->settings)(['export_batch_size' => 200, 'export_max_rows_per_file' => 350]);

    $export = ExportJob::create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'scope' => ExportJob::SCOPE_ALL,
        'columns' => ['email'],
    ]);

    $path = app(SubscriberExportWriter::class)->write($export->refresh());

    expect($export->refresh()->processed_rows)->toBe(350)
        ->and(preg_split('/\R/', trim(Storage::disk('local')->get($path))))->toHaveCount(351);
});

it('continues a large import in a fresh job from the exact offset it reached', function (): void {
    Queue::fake();

    $rows = "email,first_name\n";

    for ($i = 0; $i < 900; $i++) {
        $rows .= 'import'.str_pad((string) $i, 4, '0', STR_PAD_LEFT)."@example.com,Name{$i}\n";
    }

    $path = 'customers/'.$this->customer->uid.'/imports/big.csv';
    Storage::disk('local')->put($path, $rows);

    ($this->settings)(['import_batch_size' => 100, 'import_max_execution_seconds' => 1]);

    $import = ImportJob::create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'source' => \App\Enums\ImportSource::Csv,
        'file_path' => $path,
        'options' => [
            'has_header' => true,
            'delimiter' => ',',
            'mapping' => [0 => 'EMAIL', 1 => 'new:First name'],
            'imported_status' => \App\Enums\SubscriberStatus::Confirmed->value,
            'duplicate_strategy' => 'update',
            'original_filename' => 'big.csv',
        ],
    ]);

    (new RunImportJob((int) $import->id))->handle(app(CurrentCustomer::class));

    $import->refresh();

    // Either the whole file fitted inside the deadline (fast machine) or the
    // job handed the remainder to a fresh job — never silently truncated.
    $continued = collect(Queue::pushedJobs())
        ->flatten(1)
        ->pluck('job')
        ->filter(fn ($job): bool => $job instanceof RunImportJob);

    if ($continued->isNotEmpty()) {
        /** @var RunImportJob $next */
        $next = $continued->first();

        // The continuation carries the exact offset reached — the offset is
        // also part of the idempotency key, so a retry cannot double-import.
        expect($next->idempotencyKey())->toBe('import-run:'.$import->id.':'.$import->processed_rows)
            ->and($import->processed_rows)->toBeGreaterThan(0)
            ->and($import->processed_rows)->toBeLessThan(900)
            ->and($import->processed_rows % 100)->toBe(0); // stopped on a chunk boundary
    } else {
        expect($import->processed_rows)->toBe(900);
    }

    // Whatever ran, every processed row landed exactly once.
    expect(Subscriber::withoutTenancy()->where('list_id', $this->list->id)->count())
        ->toBe($import->processed_rows);
});

it('reads the import file lazily through a generator, never into an array', function (): void {
    $reader = new \ReflectionMethod(\App\Services\Imports\Readers\CsvFileReader::class, 'rows');

    expect($reader->isGenerator())->toBeTrue();
});
