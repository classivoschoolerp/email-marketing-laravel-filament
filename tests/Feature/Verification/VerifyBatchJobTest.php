<?php

declare(strict_types=1);

use App\Enums\SubscriberStatus;
use App\Enums\VerificationStatus;
use App\Jobs\Verification\VerifyBatchJob;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\GlobalBlacklistEmail;
use App\Models\ListField;
use App\Models\ListSegment;
use App\Models\Subscriber;
use App\Models\VerificationJob;
use App\Models\VerificationResult as ResultRow;
use App\Services\Lists\FieldValueService;
use App\Services\Preferences\CustomerPreferences;
use App\Services\Verification\Credentials;
use App\Services\Verification\ProviderRegistry;
use App\Services\Verification\SingleAddressVerifier;
use App\Services\Verification\VerificationCredits;
use App\Services\Verification\VerificationProvider;
use App\Services\Verification\VerificationResult;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
 * WP-16 §2.9.12 — the queued bulk verification engine: targets (list /
 * segment / selected / single), result rows + subscriber tagging, per-chunk
 * progress, auto-actions on undeliverable, credit consumption + exhaustion,
 * downloadable result CSV, per-list history.
 */

beforeEach(function (): void {
    // Scripted provider: verdict derives from the local part.
    app(ProviderRegistry::class)->register('scripted', fn (): VerificationProvider => new class implements VerificationProvider
    {
        public function key(): string
        {
            return 'scripted';
        }

        public function name(): string
        {
            return 'Scripted';
        }

        public function requiresApiKey(): bool
        {
            return false;
        }

        public function verify(string $email): VerificationResult
        {
            return match (true) {
                str_starts_with($email, 'good') => VerificationResult::deliverable('ok'),
                str_starts_with($email, 'bad') => VerificationResult::undeliverable('mailbox_not_found'),
                str_starts_with($email, 'risky') => VerificationResult::risky('accept_all'),
                default => VerificationResult::unknown('mystery'),
            };
        }
    });

    $this->customer = Customer::factory()->create();
    $this->list = EmailList::factory()->for($this->customer)->create();
});

afterEach(fn () => app(CurrentCustomer::class)->set(null));

function wp16_member(string $email): Subscriber
{
    return Subscriber::factory()
        ->for(test()->customer)
        ->for(test()->list, 'list')
        ->create(['email' => $email, 'status' => SubscriberStatus::Confirmed]);
}

function wp16_job(array $attributes = []): VerificationJob
{
    return VerificationJob::withoutTenancy()->create(array_merge([
        'customer_id' => test()->customer->id,
        'list_id' => test()->list->id,
        'target' => VerificationJob::TARGET_LIST,
        'provider' => 'scripted',
        'auto_action' => VerificationJob::ACTION_TAG,
        'status' => VerificationJob::STATUS_PENDING,
    ], $attributes));
}

it('verifies a whole list: tagging, counters, results and the CSV (F-2.9.12-004/006/008)', function (): void {
    Storage::fake('local');

    $good = wp16_member('good1@example.com');
    $bad = wp16_member('bad1@example.com');
    $risky = wp16_member('risky1@example.com');
    $meh = wp16_member('other1@example.com');

    $job = wp16_job();
    VerifyBatchJob::dispatch((int) $job->id);

    $job->refresh();
    expect($job->status)->toBe(VerificationJob::STATUS_COMPLETED)
        ->and($job->total)->toBe(4)
        ->and($job->processed)->toBe(4)
        ->and($job->deliverable_count)->toBe(1)
        ->and($job->undeliverable_count)->toBe(1)
        ->and($job->risky_count)->toBe(1)
        ->and($job->unknown_count)->toBe(1)
        ->and($job->credits_used)->toBe(4)
        ->and($job->finished_at)->not->toBeNull();

    // F-2.9.12-006: tags stored on the subscriber.
    expect($good->refresh()->verification_status)->toBe(VerificationStatus::Deliverable)
        ->and($good->verified_at)->not->toBeNull()
        ->and($bad->refresh()->verification_status)->toBe(VerificationStatus::Undeliverable)
        ->and($risky->refresh()->verification_status)->toBe(VerificationStatus::Risky)
        ->and($meh->refresh()->verification_status)->toBe(VerificationStatus::Unknown);

    // Result rows + downloadable CSV.
    expect(ResultRow::query()->where('verification_job_id', $job->id)->count())->toBe(4)
        ->and($job->result_path)->not->toBeNull()
        ->and(Storage::disk('local')->exists((string) $job->result_path))->toBeTrue();

    $csv = (string) Storage::disk('local')->get((string) $job->result_path);
    expect($csv)->toContain('bad1@example.com')->toContain('undeliverable');

    // tag-only: no status changes.
    expect($bad->status)->toBe(SubscriberStatus::Confirmed);
});

it('applies the auto-action to undeliverable results (F-2.9.12-007)', function (string $action, callable $assert): void {
    $bad = wp16_member('bad-action@example.com');
    wp16_member('good-action@example.com');

    $job = wp16_job(['auto_action' => $action]);
    VerifyBatchJob::dispatch((int) $job->id);

    $assert($bad->refresh());
})->with([
    'delete' => [
        VerificationJob::ACTION_DELETE,
        fn (Subscriber $s) => expect($s->trashed())->toBeTrue(),
    ],
    'unsubscribe' => [
        VerificationJob::ACTION_UNSUBSCRIBE,
        fn (Subscriber $s) => expect($s->status)->toBe(SubscriberStatus::Unsubscribed),
    ],
    'blacklist' => [
        VerificationJob::ACTION_BLACKLIST,
        function (Subscriber $s): void {
            expect($s->status)->toBe(SubscriberStatus::Blacklisted);

            $entry = GlobalBlacklistEmail::query()->where('email', $s->email)->firstOrFail();
            expect($entry->source)->toBe(GlobalBlacklistEmail::SOURCE_VERIFICATION)
                ->and($entry->customer_id)->toBe($s->customer_id);
        },
    ],
]);

it('verifies a segment and only the segment (F-2.9.12-004)', function (): void {
    app(CurrentCustomer::class)->set($this->customer);

    $field = ListField::factory()->for($this->list, 'list')->create(['tag' => 'CITY']);
    $service = app(FieldValueService::class);

    $in = wp16_member('good-in@example.com');
    $out = wp16_member('good-out@example.com');
    $service->set($in, $field, 'Casablanca');
    $service->set($out, $field, 'Rabat');

    $segment = ListSegment::factory()->for($this->list, 'list')->create(['match_type' => 'all']);
    $segment->conditions()->create([
        'customer_id' => $this->customer->id,
        'sort_order' => 0,
        'type' => 'field',
        'list_field_id' => $field->id,
        'operator' => 'is',
        'value' => 'Casablanca',
    ]);

    app(CurrentCustomer::class)->set(null);

    $job = wp16_job(['target' => VerificationJob::TARGET_SEGMENT, 'segment_id' => $segment->id]);
    VerifyBatchJob::dispatch((int) $job->id);

    expect($job->refresh()->total)->toBe(1)
        ->and($in->refresh()->verification_status)->toBe(VerificationStatus::Deliverable)
        ->and($out->refresh()->verification_status)->toBeNull();
});

it('verifies only the selected subscribers of the owning tenant (F-2.9.12-004)', function (): void {
    $picked = wp16_member('good-picked@example.com');
    $skipped = wp16_member('good-skipped@example.com');

    // A foreign subscriber id smuggled into the selection is ignored.
    $foreignList = EmailList::factory()->create();
    $foreign = Subscriber::factory()->create([
        'list_id' => $foreignList->id,
        'customer_id' => $foreignList->customer_id,
        'email' => 'good-foreign@example.com',
    ]);

    $job = wp16_job([
        'target' => VerificationJob::TARGET_SUBSCRIBERS,
        'list_id' => null,
        'subscriber_ids' => [$picked->id, $foreign->id],
    ]);
    VerifyBatchJob::dispatch((int) $job->id);

    expect($job->refresh()->total)->toBe(1)
        ->and($picked->refresh()->verification_status)->toBe(VerificationStatus::Deliverable)
        ->and($skipped->refresh()->verification_status)->toBeNull()
        ->and($foreign->refresh()->verification_status)->toBeNull();
});

it('consumes credits and stops cleanly when they run out (F-2.9.12-008/011)', function (): void {
    $this->customer->limitOverrides()->create(['key' => VerificationCredits::LIMIT_KEY, 'value' => 2]);

    wp16_member('good-c1@example.com');
    wp16_member('good-c2@example.com');
    wp16_member('good-c3@example.com');

    $job = wp16_job();
    VerifyBatchJob::dispatch((int) $job->id);

    $job->refresh();
    expect($job->status)->toBe(VerificationJob::STATUS_COMPLETED)
        ->and($job->processed)->toBe(2)
        ->and($job->credits_used)->toBe(2)
        ->and($job->error)->toBe(__('verification.errors.credits_exhausted'));

    expect(app(VerificationCredits::class)->remaining($this->customer))->toBe(0);
});

it('materialises the inherited credit allotment on first consumption (D-42)', function (): void {
    $group = \App\Models\CustomerGroup::factory()->create();
    $group->limits()->create(['key' => VerificationCredits::LIMIT_KEY, 'value' => 5]);
    $this->customer->forceFill(['customer_group_id' => $group->id])->save();

    $credits = app(VerificationCredits::class);

    expect($credits->remaining($this->customer))->toBe(5)
        ->and($credits->consume($this->customer))->toBeTrue()
        ->and($credits->remaining($this->customer))->toBe(4);

    // No row / no group limit → unlimited, nothing materialised.
    $free = Customer::factory()->create();
    expect($credits->remaining($free))->toBeNull()
        ->and($credits->consume($free))->toBeTrue()
        ->and($credits->remaining($free))->toBeNull();
});

it('verifies a single address in real time, logging history (F-2.9.12-004/009)', function (): void {
    wp16_member('bad-single@example.com');

    $row = app(SingleAddressVerifier::class)->verify($this->customer, 'Bad-Single@Example.com', 'scripted');

    expect($row->status)->toBe(VerificationStatus::Undeliverable)
        ->and($row->email)->toBe('bad-single@example.com');

    $job = VerificationJob::withoutTenancy()->where('target', VerificationJob::TARGET_SINGLE)->firstOrFail();
    expect($job->single_email)->toBe('bad-single@example.com')
        ->and($job->status)->toBe(VerificationJob::STATUS_COMPLETED)
        ->and($job->undeliverable_count)->toBe(1)
        ->and($job->credits_used)->toBe(1);

    // The matching subscriber is tagged, but never destroyed.
    $subscriber = Subscriber::withoutTenancy()->where('email', 'bad-single@example.com')->firstOrFail();
    expect($subscriber->verification_status)->toBe(VerificationStatus::Undeliverable)
        ->and($subscriber->status)->toBe(SubscriberStatus::Confirmed);
});

it('refuses a single verification without credits', function (): void {
    $this->customer->limitOverrides()->create(['key' => VerificationCredits::LIMIT_KEY, 'value' => 0]);

    expect(fn () => app(SingleAddressVerifier::class)->verify($this->customer, 'x@example.com', 'scripted'))
        ->toThrow(ValidationException::class);
});

it('keeps a per-list verification history (F-2.9.12-009)', function (): void {
    wp16_member('good-h@example.com');

    VerifyBatchJob::dispatch((int) wp16_job()->id);
    VerifyBatchJob::dispatch((int) wp16_job()->id);

    $otherList = EmailList::factory()->for($this->customer)->create();
    VerifyBatchJob::dispatch((int) wp16_job(['list_id' => $otherList->id])->id);

    expect(VerificationJob::withoutTenancy()->where('list_id', $this->list->id)->where('status', VerificationJob::STATUS_COMPLETED)->count())->toBe(2)
        ->and(VerificationJob::withoutTenancy()->where('list_id', $otherList->id)->count())->toBe(1);
});

it('resolves the customer\'s preferred provider and falls back to shared credentials (F-2.9.12-003)', function (): void {
    $credentials = app(Credentials::class);

    // Default: config default (built-in).
    expect($credentials->providerKeyFor($this->customer))->toBe('built-in');

    // Customer choice via preference.
    app(CustomerPreferences::class)->set($this->customer, Credentials::PREF_PROVIDER, 'scripted');
    expect($credentials->providerKeyFor($this->customer))->toBe('scripted');

    // API key: own preference wins, operator-shared config is the fallback.
    config(['emp-verification.providers.kickbox.api_key' => 'shared-key']);
    expect($credentials->apiKeyFor($this->customer, 'kickbox'))->toBe('shared-key');

    app(CustomerPreferences::class)->set($this->customer, Credentials::PREF_API_KEY_PREFIX.'kickbox', 'own-key');
    expect($credentials->apiKeyFor($this->customer, 'kickbox'))->toBe('own-key');
});
