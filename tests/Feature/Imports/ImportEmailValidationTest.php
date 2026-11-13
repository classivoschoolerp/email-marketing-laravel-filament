<?php

declare(strict_types=1);

use App\Enums\ImportSource;
use App\Filament\Customer\Resources\EmailListResource\Pages\ImportSubscribers;
use App\Jobs\Imports\RunImportJob;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ImportJob;
use App\Models\Subscriber;
use App\Services\Dns\DnsResolverInterface;
use App\Services\Imports\ImportEmailValidator;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Fakes\FakeDnsResolver;

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);
    Storage::fake('local');

    $this->list = EmailList::factory()->for($this->customer)->create();

    $this->runImport = fn (ImportJob $import) => (new RunImportJob((int) $import->id))
        ->handle(app(CurrentCustomer::class));

    // D-16: no real DNS in tests — MX answers are scripted.
    $this->fakeDns = function (array $mx): FakeDnsResolver {
        $resolver = new FakeDnsResolver(mx: $mx);
        app()->instance(DnsResolverInterface::class, $resolver);

        return $resolver;
    };

    $this->csvImport = function (string $csv, bool $validate): ImportJob {
        $path = 'customers/'.$this->customer->uid.'/imports/validate-'.str()->random(6).'.csv';
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
                'mapping' => [0 => 'EMAIL'],
                'imported_status' => 'confirmed',
                'duplicate_strategy' => 'update',
                'validate_emails' => $validate,
            ],
        ]);
    };
});

/*
 * F-2.2.7-014: with the option on, every row runs through the D-12 built-in
 * engine. Syntax failures and domains with no MX are counted as failed and
 * written to the downloadable error CSV; everything else imports normally.
 */
it('F-2.2.7-014 fails rows with bad syntax or no MX records and lists them in the error CSV', function (): void {
    ($this->fakeDns)(['good.example' => ['mx1.good.example']]);

    $import = ($this->csvImport)(
        "email\nok@good.example\nnomx@ghost.example\nnot-an-email\nalso.ok@good.example\n",
        validate: true,
    );

    ($this->runImport)($import);
    $import->refresh();

    expect($import->status)->toBe(ImportJob::STATUS_COMPLETED_WITH_ERRORS)
        ->and($import->total_rows)->toBe(4)
        ->and($import->imported_count)->toBe(2)
        ->and($import->failed_count)->toBe(2)
        ->and(Subscriber::query()->where('list_id', $this->list->id)->pluck('email')->sort()->values()->all())
        ->toBe(['also.ok@good.example', 'ok@good.example']);

    // The error CSV names the offending line, address and engine reason.
    $rows = array_map(
        static fn (string $line): array => str_getcsv($line, ',', '"', '\\'),
        array_values(array_filter(preg_split('/\r\n|\n/', Storage::disk('local')->get((string) $import->error_file_path)) ?: [])),
    );

    expect($rows)->toBe([
        [__('imports.error_csv.line'), __('imports.error_csv.email'), __('imports.error_csv.errors')],
        ['3', 'nomx@ghost.example', __('imports.errors.no_mx_records', ['email' => 'nomx@ghost.example'])],
        ['4', 'not-an-email', __('imports.errors.invalid_email', ['email' => 'not-an-email'])],
    ]);
});

// F-2.2.7-014: the option is opt-in — with it off, an MX-less domain that is
// syntactically valid still imports (previous P1 behaviour is untouched).
it('F-2.2.7-014 leaves rows alone when the validation option is off', function (): void {
    ($this->fakeDns)([]); // no domain has MX

    $import = ($this->csvImport)("email\nnomx@ghost.example\n", validate: false);
    ($this->runImport)($import);

    expect($import->refresh()->status)->toBe(ImportJob::STATUS_COMPLETED)
        ->and($import->imported_count)->toBe(1)
        ->and($import->failed_count)->toBe(0);
});

/*
 * F-2.2.7-014 performance guard: MX is resolved ONCE PER DOMAIN for the run,
 * not once per row, so a large import does not blow the per-chunk time
 * budget. Proven with a counting resolver over 6 rows on 2 domains.
 */
it('F-2.2.7-014 resolves MX once per domain instead of once per row', function (): void {
    $lookups = [];

    $counting = new class($lookups) implements DnsResolverInterface
    {
        /** @param array<string, int> $lookups */
        public function __construct(public array $lookups) {}

        public function txtRecords(string $host): array
        {
            return [];
        }

        public function cnameTarget(string $host): ?string
        {
            return null;
        }

        public function mxRecords(string $domain): array
        {
            $this->lookups[$domain] = ($this->lookups[$domain] ?? 0) + 1;

            return ['mx.'.$domain];
        }

        public function aRecords(string $host): array
        {
            return [];
        }
    };

    app()->instance(DnsResolverInterface::class, $counting);

    $validator = app(ImportEmailValidator::class);

    foreach (['a@one.example', 'b@one.example', 'c@one.example', 'd@two.example', 'e@two.example', 'f@one.example'] as $email) {
        expect($validator->reject($email))->toBeNull();
    }

    expect($counting->lookups)->toBe(['one.example' => 1, 'two.example' => 1])
        ->and($validator->resolvedDomainCount())->toBe(2);
});

// F-2.2.7-014: risky verdicts (role account, disposable domain) are NOT
// rejected — the row asks for syntax + MX, and silently dropping role
// accounts would discard addresses the operator never asked to drop.
it('F-2.2.7-014 imports role accounts and disposable domains that pass syntax and MX', function (): void {
    ($this->fakeDns)(['good.example' => ['mx1.good.example']]);

    $import = ($this->csvImport)("email\ninfo@good.example\n", validate: true);
    ($this->runImport)($import);

    expect($import->refresh()->status)->toBe(ImportJob::STATUS_COMPLETED)
        ->and($import->imported_count)->toBe(1)
        ->and($import->failed_count)->toBe(0);
});

// F-2.2.7-014: the option is reachable from the import page and persisted on
// the run, so the queued job knows to validate.
it('F-2.2.7-014 stores the validation option on the import created from the page', function (): void {
    Queue::fake();

    Livewire::test(ImportSubscribers::class, ['record' => $this->list->getRouteKey()])
        ->fillForm([
            'source' => ImportSource::Paste->value,
            'paste_text' => 'one@example.com',
            'imported_status' => 'confirmed',
            'duplicate_strategy' => 'update',
            'validate_emails' => true,
        ])
        ->call('startImport')
        ->assertHasNoFormErrors();

    $import = ImportJob::query()->where('list_id', $this->list->id)->firstOrFail();

    expect(((array) $import->options)['validate_emails'])->toBeTrue();

    Queue::assertPushed(RunImportJob::class);
});
