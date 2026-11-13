<?php

declare(strict_types=1);

use App\Filament\Customer\Resources\SubscriberResource\Pages\ListSubscribers;
use App\Filament\Customer\Resources\VerificationJobResource;
use App\Filament\Customer\Resources\VerificationJobResource\Pages\ListVerificationJobs;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Models\VerificationJob;
use App\Services\Preferences\CustomerPreferences;
use App\Services\Verification\Credentials;
use App\Services\Verification\ProviderRegistry;
use App\Services\Verification\VerificationCredits;
use App\Services\Verification\VerificationProvider;
use App\Services\Verification\VerificationResult;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * WP-16 §2.9.12 — the customer verification UI: index + progress columns,
 * new-verification action (queued), single-address action, provider
 * settings, credits display, result download authorization, verify-selected
 * bulk action on the subscribers table, tenancy fencing.
 */

beforeEach(function (): void {
    app(ProviderRegistry::class)->register('ui-scripted', fn (): VerificationProvider => new class implements VerificationProvider
    {
        public function key(): string
        {
            return 'ui-scripted';
        }

        public function name(): string
        {
            return 'UI Scripted';
        }

        public function requiresApiKey(): bool
        {
            return false;
        }

        public function verify(string $email): VerificationResult
        {
            return VerificationResult::deliverable('ok');
        }
    });

    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->for($this->customer)->create();
});

afterEach(fn () => app(CurrentCustomer::class)->set(null));

it('renders the verification index with progress, counts and credits (F-2.9.12-008)', function (): void {
    $job = VerificationJob::query()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'target' => VerificationJob::TARGET_LIST,
        'provider' => 'built-in',
        'status' => VerificationJob::STATUS_RUNNING,
        'total' => 10,
        'processed' => 5,
        'credits_used' => 5,
    ]);

    $this->customer->limitOverrides()->create(['key' => VerificationCredits::LIMIT_KEY, 'value' => 42]);

    $this->get(VerificationJobResource::getUrl('index', panel: 'customer'))->assertOk();

    Livewire::test(ListVerificationJobs::class)
        ->assertTableColumnExists('provider')
        ->assertTableColumnExists('status')
        ->assertTableColumnExists('progress')
        ->assertTableColumnExists('credits_used')
        ->assertCanSeeTableRecords([$job])
        ->assertSee(__('verification.credits.remaining', ['count' => 42]));

    expect($job->progressPercent())->toBe(50);
});

it('fences verification runs per tenant', function (): void {
    $foreign = VerificationJob::withoutTenancy()->create([
        'customer_id' => Customer::factory()->create()->id,
        'target' => VerificationJob::TARGET_LIST,
        'provider' => 'built-in',
    ]);
    $own = VerificationJob::query()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'target' => VerificationJob::TARGET_LIST,
        'provider' => 'built-in',
    ]);

    Livewire::test(ListVerificationJobs::class)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$foreign]);
});

it('queues a whole-list verification from the header action (F-2.9.12-004)', function (): void {
    Storage::fake('local');

    Subscriber::factory()->for($this->customer)->for($this->list, 'list')->create(['email' => 'ui1@example.com']);

    Livewire::test(ListVerificationJobs::class)
        ->callAction('newVerification', data: [
            'target' => VerificationJob::TARGET_LIST,
            'list_id' => $this->list->id,
            'provider' => 'ui-scripted',
            'auto_action' => VerificationJob::ACTION_TAG,
        ])
        ->assertHasNoActionErrors();

    // Sync queue: the run finished inline.
    $job = VerificationJob::query()->firstOrFail();
    expect($job->status)->toBe(VerificationJob::STATUS_COMPLETED)
        ->and($job->provider)->toBe('ui-scripted')
        ->and($job->processed)->toBe(1);
});

it('verifies a single address from the header action showing the verdict', function (): void {
    Livewire::test(ListVerificationJobs::class)
        ->callAction('verifySingle', data: [
            'email' => 'single-ui@example.com',
            'provider' => 'ui-scripted',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect(VerificationJob::query()->where('target', VerificationJob::TARGET_SINGLE)->exists())->toBeTrue();
});

it('stores per-customer provider choice and API keys (F-2.9.12-003)', function (): void {
    Livewire::test(ListVerificationJobs::class)
        ->callAction('providerSettings', data: [
            'provider' => 'kickbox',
            'api_key_kickbox' => 'kb-secret',
        ])
        ->assertHasNoActionErrors();

    $prefs = app(CustomerPreferences::class);
    expect($prefs->get($this->customer, Credentials::PREF_PROVIDER))->toBe('kickbox')
        ->and($prefs->get($this->customer, Credentials::PREF_API_KEY_PREFIX.'kickbox'))->toBe('kb-secret')
        ->and(app(Credentials::class)->providerKeyFor($this->customer))->toBe('kickbox');
});

it('authorizes the result CSV download per owner (F-2.9.12-008)', function (): void {
    Storage::fake('local');

    $path = 'customers/'.$this->customer->uid.'/verifications/x-results.csv';
    Storage::disk('local')->put($path, "email,status\n");

    $job = VerificationJob::query()->create([
        'customer_id' => $this->customer->id,
        'target' => VerificationJob::TARGET_LIST,
        'provider' => 'built-in',
        'status' => VerificationJob::STATUS_COMPLETED,
        'result_path' => $path,
    ]);

    $this->get(route('verification.results.download', ['uid' => $job->uid]))->assertOk();

    $intruder = Customer::factory()->create();
    $this->actingAs($intruder, 'customer');
    app(CurrentCustomer::class)->set($intruder);

    $this->get(route('verification.results.download', ['uid' => $job->uid]))->assertForbidden();
});

it('verifies selected subscribers via the subscribers bulk action (F-2.9.12-004)', function (): void {
    $picked = Subscriber::factory()->for($this->customer)->for($this->list, 'list')->create(['email' => 'bulk1@example.com']);
    Subscriber::factory()->for($this->customer)->for($this->list, 'list')->create(['email' => 'bulk2@example.com']);

    app(CustomerPreferences::class)->set($this->customer, Credentials::PREF_PROVIDER, 'ui-scripted');

    Livewire::test(ListSubscribers::class)
        ->callTableBulkAction('verify', [$picked]);

    $job = VerificationJob::query()->where('target', VerificationJob::TARGET_SUBSCRIBERS)->firstOrFail();
    expect($job->subscriber_ids)->toBe([$picked->id])
        ->and($job->status)->toBe(VerificationJob::STATUS_COMPLETED)
        ->and($picked->refresh()->verification_status)->not->toBeNull();
});
