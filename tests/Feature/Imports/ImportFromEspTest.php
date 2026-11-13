<?php

declare(strict_types=1);

use App\Enums\ImportSource;
use App\Filament\Customer\Resources\EmailListResource\Pages\ImportSubscribers;
use App\Jobs\Imports\RunImportJob;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ImportJob;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Services\Imports\Esp\EspImporterRegistry;
use App\Services\Imports\Esp\Importers\BrevoImporter;
use App\Services\Imports\Esp\Importers\ConstantContactImporter;
use App\Services\Imports\Esp\Importers\KlaviyoImporter;
use App\Services\Imports\Esp\Importers\MailchimpImporter;
use App\Services\Lists\FieldValueService;
use App\Support\Queue\Queues;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
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

    $this->espImport = function (string $driver, array $credentials): ImportJob {
        return ImportJob::create([
            'customer_id' => $this->customer->id,
            'list_id' => $this->list->id,
            'source' => ImportSource::Esp,
            'options' => [
                'esp' => [
                    'driver' => $driver,
                    'credentials_encrypted' => Crypt::encryptString((string) json_encode($credentials)),
                ],
                'auto_create_fields' => true,
                'imported_status' => 'confirmed',
                'duplicate_strategy' => 'update',
            ],
        ]);
    };
});

// F-2.2.7-006: Mailchimp — datacenter host derived from the key suffix,
// Basic auth, /3.0/lists/{id}/members, offset pagination, merge_fields mapped.
it('F-2.2.7-006 imports Mailchimp audience members over two paged API calls', function (): void {
    Http::fake([
        'https://us14.api.mailchimp.com/3.0/lists/aud123/members*' => Http::sequence()
            ->push([
                'total_items' => 3,
                'members' => [
                    ['email_address' => 'mc1@example.com', 'merge_fields' => ['FNAME' => 'Ada', 'LNAME' => 'Lovelace']],
                    ['email_address' => 'mc2@example.com', 'merge_fields' => ['FNAME' => '田中', 'LNAME' => '花子 🌸']],
                ],
            ])
            ->push([
                'total_items' => 3,
                'members' => [
                    ['email_address' => 'mc3@example.com', 'merge_fields' => ['FNAME' => 'Omar', 'LNAME' => 'Haddad']],
                ],
            ]),
    ]);

    $import = ($this->espImport)('mailchimp', ['api_key' => 'secret-key-us14', 'list_id' => 'aud123']);
    ($this->runImport)($import);

    expect($import->refresh()->status)->toBe(ImportJob::STATUS_COMPLETED)
        ->and($import->total_rows)->toBe(3)
        ->and($import->imported_count)->toBe(3);

    // Exact request shape: host from the key's "-us14" suffix, documented
    // path, page size, subscribed-only, offset paging, HTTP Basic auth.
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://us14.api.mailchimp.com/3.0/lists/aud123/members?count=500&offset=0&status=subscribed'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('emp:secret-key-us14')));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://us14.api.mailchimp.com/3.0/lists/aud123/members?count=500&offset=2&status=subscribed');

    Http::assertSentCount(2);

    // Rows landed through the normal pipeline: auto-created fields + values.
    $fname = ListField::query()->where('list_id', $this->list->id)->where('tag', 'FNAME')->firstOrFail();
    $cjk = Subscriber::query()->where('email', 'mc2@example.com')->firstOrFail();

    expect(app(FieldValueService::class)->get($cjk, $fname))->toBe('田中')
        ->and(Subscriber::query()->where('list_id', $this->list->id)->pluck('email')->sort()->values()->all())
        ->toBe(['mc1@example.com', 'mc2@example.com', 'mc3@example.com']);
});

// F-2.2.7-006: Mailchimp keys carry the datacenter; a key without one is a
// configuration error, not a wrong-host request.
it('F-2.2.7-006 fails a Mailchimp import when the API key carries no datacenter suffix', function (): void {
    Http::fake();

    $import = ($this->espImport)('mailchimp', ['api_key' => 'nodatacenter', 'list_id' => 'aud123']);
    ($this->runImport)($import);

    expect($import->refresh()->status)->toBe(ImportJob::STATUS_FAILED)
        ->and($import->message)->toBe(__('imports.errors.esp_mailchimp_datacenter'));

    Http::assertNothingSent();
});

// F-2.2.7-006: Brevo — api-key header, /v3/contacts, attributes mapped,
// blacklisted (= unsubscribed) contacts never resurrected.
it('F-2.2.7-006 imports Brevo contacts and drops blacklisted ones', function (): void {
    Http::fake([
        'https://api.brevo.com/v3/contacts*' => Http::response([
            'count' => 2,
            'contacts' => [
                ['email' => 'brevo1@example.com', 'attributes' => ['FIRSTNAME' => 'Ines'], 'emailBlacklisted' => false],
                ['email' => 'brevo2@example.com', 'attributes' => ['FIRSTNAME' => 'Gone'], 'emailBlacklisted' => true],
            ],
        ]),
    ]);

    $import = ($this->espImport)('brevo', ['api_key' => 'xkeysib-abc']);
    ($this->runImport)($import);

    expect($import->refresh()->status)->toBe(ImportJob::STATUS_COMPLETED)
        ->and($import->imported_count)->toBe(1);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.brevo.com/v3/contacts?limit=500&offset=0'
        && $request->hasHeader('api-key', 'xkeysib-abc'));

    Http::assertSentCount(1);

    expect(Subscriber::query()->where('list_id', $this->list->id)->pluck('email')->all())
        ->toBe(['brevo1@example.com'])
        ->and(app(FieldValueService::class)->get(
            Subscriber::query()->where('email', 'brevo1@example.com')->firstOrFail(),
            ListField::query()->where('list_id', $this->list->id)->where('tag', 'FIRSTNAME')->firstOrFail(),
        ))->toBe('Ines');
});

// F-2.2.7-006: Constant Contact — OAuth bearer, /v3/contacts, cursor paging
// through the relative _links.next.href.
it('F-2.2.7-006 imports Constant Contact contacts following the _links cursor', function (): void {
    Http::fake([
        'https://api.cc.email/v3/contacts?limit=500&include=custom_fields' => Http::response([
            'contacts' => [[
                'email_address' => ['address' => 'cc1@example.com'],
                'first_name' => 'Rosa',
                'custom_fields' => [['name' => 'city', 'value' => 'Lisbon']],
            ]],
            '_links' => ['next' => ['href' => '/v3/contacts?cursor=NXQ']],
        ]),
        'https://api.cc.email/v3/contacts?cursor=NXQ' => Http::response([
            'contacts' => [[
                'email_address' => ['address' => 'cc2@example.com'],
                'first_name' => 'Nuno',
                'custom_fields' => [['name' => 'city', 'value' => 'Porto']],
            ]],
            '_links' => [],
        ]),
    ]);

    $import = ($this->espImport)('constant_contact', ['access_token' => 'oauth-token-123']);
    ($this->runImport)($import);

    expect($import->refresh()->status)->toBe(ImportJob::STATUS_COMPLETED)
        ->and($import->imported_count)->toBe(2);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.cc.email/v3/contacts?limit=500&include=custom_fields'
        && $request->hasHeader('Authorization', 'Bearer oauth-token-123'));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.cc.email/v3/contacts?cursor=NXQ'
        && $request->hasHeader('Authorization', 'Bearer oauth-token-123'));

    Http::assertSentCount(2);

    $city = ListField::query()->where('list_id', $this->list->id)->where('tag', 'CITY')->firstOrFail();

    expect(app(FieldValueService::class)->get(
        Subscriber::query()->where('email', 'cc2@example.com')->firstOrFail(),
        $city,
    ))->toBe('Porto');
});

// F-2.2.7-006: Klaviyo — Klaviyo-API-Key auth + mandatory revision header,
// /api/profiles, absolute links.next cursor.
it('F-2.2.7-006 imports Klaviyo profiles with the revision header and links.next cursor', function (): void {
    Http::fake([
        'https://a.klaviyo.com/api/profiles?page%5Bsize%5D=100' => Http::response([
            'data' => [[
                'attributes' => [
                    'email' => 'kl1@example.com',
                    'first_name' => 'Mina',
                    'location' => ['city' => 'Osaka'], // nested → never becomes a field
                    'properties' => ['plan' => 'gold'],
                ],
            ]],
            'links' => ['next' => 'https://a.klaviyo.com/api/profiles/?page%5Bcursor%5D=abc'],
        ]),
        'https://a.klaviyo.com/api/profiles/?page%5Bcursor%5D=abc' => Http::response([
            'data' => [[
                'attributes' => ['email' => 'kl2@example.com', 'first_name' => 'Tomas', 'properties' => ['plan' => 'free']],
            ]],
            'links' => ['next' => null],
        ]),
    ]);

    $import = ($this->espImport)('klaviyo', ['api_key' => 'pk_live_123']);
    ($this->runImport)($import);

    expect($import->refresh()->status)->toBe(ImportJob::STATUS_COMPLETED)
        ->and($import->imported_count)->toBe(2);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://a.klaviyo.com/api/profiles?page%5Bsize%5D=100'
        && $request->hasHeader('Authorization', 'Klaviyo-API-Key pk_live_123')
        && $request->hasHeader('revision', KlaviyoImporter::REVISION));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://a.klaviyo.com/api/profiles/?page%5Bcursor%5D=abc'
        && $request->hasHeader('revision', KlaviyoImporter::REVISION));

    Http::assertSentCount(2);

    expect(ListField::query()->where('list_id', $this->list->id)->where('tag', 'PLAN')->exists())->toBeTrue()
        ->and(ListField::query()->where('list_id', $this->list->id)->where('tag', 'LOCATION')->exists())->toBeFalse()
        ->and(app(FieldValueService::class)->get(
            Subscriber::query()->where('email', 'kl1@example.com')->firstOrFail(),
            ListField::query()->where('list_id', $this->list->id)->where('tag', 'PLAN')->firstOrFail(),
        ))->toBe('gold');
});

// F-2.2.7-006: a vendor error becomes a readable failed import, not a stack
// trace, and no partial subscriber survives the failed page.
it('F-2.2.7-006 fails the import with the vendor status when the ESP refuses the request', function (): void {
    Http::fake(['https://api.brevo.com/*' => Http::response(['message' => 'unauthorized'], 401)]);

    $import = ($this->espImport)('brevo', ['api_key' => 'wrong']);
    ($this->runImport)($import);

    expect($import->refresh()->status)->toBe(ImportJob::STATUS_FAILED)
        ->and($import->message)->toBe(__('imports.errors.esp_http', ['esp' => 'Brevo', 'status' => 401]))
        ->and(Subscriber::query()->where('list_id', $this->list->id)->count())->toBe(0);
});

// F-2.2.7-006 through the UI: credentials are stored ENCRYPTED and the heavy
// work is queued, never run in the request.
it('F-2.2.7-006 queues an ESP import from the page with encrypted credentials', function (): void {
    Queue::fake();

    Livewire::test(ImportSubscribers::class, ['record' => $this->list->getRouteKey()])
        ->fillForm([
            'source' => ImportSource::Esp->value,
            'esp' => ['driver' => 'mailchimp', 'credentials' => ['mailchimp' => ['api_key' => 'k-us9', 'list_id' => 'aud42']]],
            'imported_status' => 'confirmed',
            'duplicate_strategy' => 'update',
            'auto_create_fields' => true,
        ])
        ->call('startImport')
        ->assertHasNoFormErrors();

    $import = ImportJob::query()->where('list_id', $this->list->id)->firstOrFail();
    $esp = (array) ((array) $import->options)['esp'];

    expect($import->source)->toBe(ImportSource::Esp)
        ->and($esp['driver'])->toBe('mailchimp')
        ->and($esp)->not->toHaveKey('credentials')
        ->and(json_encode($import->options))->not->toContain('k-us9')
        ->and(json_decode(Crypt::decryptString((string) $esp['credentials_encrypted']), true))
        ->toBe(['api_key' => 'k-us9', 'list_id' => 'aud42']);

    Queue::assertPushed(RunImportJob::class, fn (RunImportJob $job): bool => $job->importJobId === (int) $import->id
        && $job->queue === Queues::IMPORTS);
});

// F-2.2.7-006 / docs/ARCHITECTURE.md rule 2: the four core connectors are registered and
// a plugin can add a vendor without a core change.
it('F-2.2.7-006 registers the four core ESP connectors in the driver registry', function (): void {
    $registry = app(EspImporterRegistry::class);

    expect($registry->keys())->toBe(['mailchimp', 'brevo', 'constant_contact', 'klaviyo'])
        ->and($registry->for('mailchimp'))->toBeInstanceOf(MailchimpImporter::class)
        ->and($registry->for('brevo'))->toBeInstanceOf(BrevoImporter::class)
        ->and($registry->for('constant_contact'))->toBeInstanceOf(ConstantContactImporter::class)
        ->and($registry->for('klaviyo'))->toBeInstanceOf(KlaviyoImporter::class)
        ->and($registry->options())->toBe([
            'mailchimp' => 'Mailchimp',
            'brevo' => 'Brevo',
            'constant_contact' => 'Constant Contact',
            'klaviyo' => 'Klaviyo',
        ])
        ->and($registry->has('acme_esp'))->toBeFalse();
});
