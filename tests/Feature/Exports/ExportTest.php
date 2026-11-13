<?php

declare(strict_types=1);

use App\Filament\Customer\Pages\ExportHistory;
use App\Filament\Customer\Resources\EmailListResource\Pages\ListEmailLists;
use App\Jobs\Exports\RunExportJob;
use App\Jobs\Subscribers\ExportSelectedSubscribersJob;
use App\Mail\ExportReadyMail;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ExportJob;
use App\Models\ListField;
use App\Models\ListSegment;
use App\Models\Subscriber;
use App\Services\Lists\FieldValueService;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);
    Storage::fake('local');
    Mail::fake();

    $this->list = EmailList::factory()->for($this->customer)->create();

    $this->runExport = fn (ExportJob $export) => (new RunExportJob((int) $export->id))
        ->handle(app(CurrentCustomer::class), app(\App\Services\Exports\SubscriberExportWriter::class));
});

// F-2.2.8-001/002/004/005: full export with chosen columns, mail + signed link.
it('exports all subscribers with chosen columns and emails a signed download link', function (): void {
    $field = ListField::factory()->for($this->list, 'list')->create(['tag' => 'FNAME', 'label' => 'First name']);

    $one = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'one@example.com']);
    app(FieldValueService::class)->set($one, $field, '山田 太郎 🚀');
    Subscriber::factory()->for($this->list, 'list')->unconfirmed()->create(['email' => 'two@example.com']);

    $export = ExportJob::create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'scope' => ExportJob::SCOPE_ALL,
        'columns' => ['email', 'status', 'field:FNAME'],
    ]);

    ($this->runExport)($export);
    $export->refresh();

    expect($export->status)->toBe(ExportJob::STATUS_COMPLETED)
        ->and($export->total_rows)->toBe(2)
        ->and($export->processed_rows)->toBe(2)
        ->and($export->file_path)->toBe('customers/'.$this->customer->uid.'/exports/'.$export->uid.'.csv')
        // Retention (7 days default) drives the expiry (F-2.2.8-005).
        ->and($export->expires_at)->not->toBeNull()
        ->and((int) round(now()->diffInDays($export->expires_at)))->toBe(7);

    $csv = Storage::disk('local')->get($export->file_path);
    expect($csv)->toContain('EMAIL,STATUS,FNAME')
        ->and($csv)->toContain('one@example.com,confirmed,"山田 太郎 🚀"')
        ->and($csv)->toContain('two@example.com,unconfirmed');

    Mail::assertQueued(ExportReadyMail::class, fn (ExportReadyMail $mail): bool => $mail->hasTo($this->customer->email)
        && str_contains($mail->downloadUrl, 'signature='));
});

// F-2.2.8-001: filtered scope.
it('exports only subscribers matching the filters', function (): void {
    Subscriber::factory()->for($this->list, 'list')->create(['email' => 'keep@example.com']);
    Subscriber::factory()->for($this->list, 'list')->unconfirmed()->create(['email' => 'drop@example.com']);

    $export = ExportJob::create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'scope' => ExportJob::SCOPE_FILTERED,
        'columns' => ['email'],
        'filters' => ['statuses' => ['confirmed']],
    ]);

    ($this->runExport)($export);

    $csv = Storage::disk('local')->get($export->refresh()->file_path);
    expect($csv)->toContain('keep@example.com')->and($csv)->not->toContain('drop@example.com');
});

// F-2.2.8-001: segment scope via the dynamic segment query.
it('exports a segment', function (): void {
    Subscriber::factory()->for($this->list, 'list')->create(['email' => 'seg@example.com']);
    Subscriber::factory()->for($this->list, 'list')->unconfirmed()->create(['email' => 'notseg@example.com']);

    $segment = ListSegment::factory()->for($this->list, 'list')->create();
    $segment->conditions()->create([
        'customer_id' => $this->customer->id,
        'type' => 'status',
        'operator' => 'is',
        'value' => 'confirmed',
        'sort_order' => 0,
    ]);

    $export = ExportJob::create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'scope' => ExportJob::SCOPE_SEGMENT,
        'columns' => ['email'],
        'list_segment_id' => $segment->id,
    ]);

    ($this->runExport)($export);

    $csv = Storage::disk('local')->get($export->refresh()->file_path);
    expect($csv)->toContain('seg@example.com')->and($csv)->not->toContain('notseg@example.com');
});

// F-2.2.8-001 "selected" + F-2.2.4-007: the bulk action job feeds the engine.
it('exports selected subscribers through the bulk-action job', function (): void {
    $field = ListField::factory()->for($this->list, 'list')->create(['tag' => 'CITY', 'label' => 'City']);
    $picked = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'picked@example.com']);
    app(FieldValueService::class)->set($picked, $field, 'Beirut');
    Subscriber::factory()->for($this->list, 'list')->create(['email' => 'unpicked@example.com']);

    (new ExportSelectedSubscribersJob($this->customer->id, [$picked->id], (string) Str::uuid()))
        ->handle(app(CurrentCustomer::class));

    $export = ExportJob::query()->where('scope', ExportJob::SCOPE_SELECTED)->firstOrFail();

    expect($export->status)->toBe(ExportJob::STATUS_COMPLETED) // sync queue ran RunExportJob
        ->and($export->columns)->toContain('field:CITY');

    $csv = Storage::disk('local')->get($export->file_path);
    expect($csv)->toContain('picked@example.com')
        ->and($csv)->toContain('Beirut')
        ->and($csv)->not->toContain('unpicked@example.com');
});

// Signed + policy-checked download; cross-tenant denial even WITH the URL.
it('serves the download to the owner only, even with a valid signed URL', function (): void {
    Subscriber::factory()->for($this->list, 'list')->create();

    $export = ExportJob::create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'scope' => ExportJob::SCOPE_ALL,
        'columns' => ['email'],
    ]);
    ($this->runExport)($export);
    $export->refresh();

    $signedUrl = URL::temporarySignedRoute('lists.exports.download', now()->addHour(), ['uid' => $export->uid]);

    // Owner: 200 CSV.
    $this->get($signedUrl)->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

    // Tampered / unsigned: 403.
    $this->get(route('lists.exports.download', ['uid' => $export->uid]))->assertForbidden();

    // Customer B with the SAME valid signed URL: 403 (policy beats signature).
    $other = Customer::factory()->create();
    $this->actingAs($other, 'customer');
    app(CurrentCustomer::class)->set($other);
    $this->get($signedUrl)->assertForbidden();
});

// F-2.2.8-005: retention pruning expires files but keeps the history row.
it('prunes expired exports and refuses their download', function (): void {
    Subscriber::factory()->for($this->list, 'list')->create();

    $export = ExportJob::create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'scope' => ExportJob::SCOPE_ALL,
        'columns' => ['email'],
    ]);
    ($this->runExport)($export);

    $export->refresh();
    $export->expires_at = now()->subDay();
    $export->save();

    // Still-valid signature, but the export itself is past retention → 410.
    $signedUrl = URL::temporarySignedRoute('lists.exports.download', now()->addHour(), ['uid' => $export->uid]);
    $this->get($signedUrl)->assertStatus(410);

    $this->artisan('exports:prune')->assertSuccessful();

    $export->refresh();
    expect($export->status)->toBe(ExportJob::STATUS_EXPIRED)
        ->and($export->file_path)->toBeNull()
        ->and(Storage::disk('local')->exists('customers/'.$this->customer->uid.'/exports/'.$export->uid.'.csv'))->toBeFalse();
});

// The per-list export action queues the job with the chosen configuration.
it('queues an export from the list table action', function (): void {
    Queue::fake();

    Livewire::test(ListEmailLists::class)
        ->callTableAction('export', $this->list, data: [
            'scope' => ExportJob::SCOPE_ALL,
            'columns' => ['email', 'status'],
        ]);

    $export = ExportJob::query()->where('list_id', $this->list->id)->firstOrFail();
    expect($export->scope)->toBe(ExportJob::SCOPE_ALL)
        ->and($export->columns)->toContain('email')
        ->and($export->columns)->toContain('status');

    Queue::assertPushed(RunExportJob::class, fn (RunExportJob $job): bool => $job->exportJobId === $export->id
        && $job->queue === \App\Support\Queue\Queues::EXPORTS);
});

// F-2.2.8-005: history page is tenant-fenced.
it('shows the export history to its owner only', function (): void {
    $mine = ExportJob::create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'scope' => ExportJob::SCOPE_ALL,
        'columns' => ['email'],
    ]);

    $foreignCustomer = Customer::factory()->create();
    $foreignList = EmailList::factory()->for($foreignCustomer)->create();
    $foreign = ExportJob::withoutTenancy()->create([
        'customer_id' => $foreignCustomer->id,
        'list_id' => $foreignList->id,
        'scope' => ExportJob::SCOPE_ALL,
        'columns' => ['email'],
    ]);

    Livewire::test(ExportHistory::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$foreign]);
});
