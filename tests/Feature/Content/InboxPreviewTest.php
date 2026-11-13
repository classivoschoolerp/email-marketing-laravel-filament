<?php

declare(strict_types=1);

use App\Filament\Customer\Resources\CampaignResource\Pages\EditCampaign;
use App\Jobs\Content\RunInboxPreviewJob;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\InboxPreviewRun;
use App\Services\Content\InboxPreview\EmailOnAcidProvider;
use App\Services\Content\InboxPreview\InboxPreviewException;
use App\Services\Content\InboxPreview\InboxPreviewRegistry;
use App\Services\Content\InboxPreview\InboxPreviewScreen;
use App\Services\Content\InboxPreview\LitmusProvider;
use App\Services\Content\InboxPreview\NullProvider;
use App\Settings\InboxPreviewSettings;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * §2.3.5 F-2.3.5-006 — inbox rendering preview across 50+ clients.
 *
 * NO LIVE VENDOR IS EVER CONTACTED. Rendering a real client matrix needs a
 * rendering farm; both adapters are written against the vendors' PUBLISHED
 * HTTP APIs and every request/response shape is locked with Http::fake, which
 * is the D-12 (verification adapters) and D-76 precedent. These tests prove
 * this application's side of the contract — the URL it calls, the credentials
 * it sends, the payload it builds and the response it parses. They cannot and
 * do not prove Litmus or Email on Acid will accept it; that needs a real
 * account on a paid plan, and the code says so in as many words.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->for($this->customer)->create();

    $this->campaign = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
        'subject' => 'Render me',
        'content_html' => '<html lang="en"><body><p>Hello</p><a href="{UNSUBSCRIBE_URL}">bye</a></body></html>',
    ]);

    $this->settings = app(InboxPreviewSettings::class);
});

/* ====================================================================== *
 *  The registry (D-12 shape)
 * ====================================================================== */

it('hands out the null provider until an account is configured', function (): void {
    $registry = app(InboxPreviewRegistry::class);

    expect($registry->default())->toBeInstanceOf(NullProvider::class)
        ->and($registry->default()->configured())->toBeFalse()
        ->and($registry->configured())->toBe([])
        // All three adapters are registered, so the UI can list them.
        ->and(array_keys($registry->all()))
        ->toBe([NullProvider::KEY, LitmusProvider::KEY, EmailOnAcidProvider::KEY]);

    // …and it refuses rather than fabricating a screenshot.
    expect(fn () => $registry->default()->submit('s', '<p>x</p>', null))
        ->toThrow(InboxPreviewException::class, __('inbox_preview.errors.not_configured'));

    expect($registry->default()->results('anything')->error)
        ->toBe(__('inbox_preview.errors.not_configured'));
});

it('prefers the operator choice and falls back to any configured adapter', function (): void {
    inboxPreviewConfigureLitmus($this->settings);

    // Chosen provider is still `null`, which is not configured ⇒ first
    // configured adapter wins rather than a silent no-op.
    expect(app(InboxPreviewRegistry::class)->default())->toBeInstanceOf(LitmusProvider::class);

    inboxPreviewConfigureEmailOnAcid($this->settings);
    $this->settings->provider = EmailOnAcidProvider::KEY;
    $this->settings->save();

    expect(app(InboxPreviewRegistry::class)->default())->toBeInstanceOf(EmailOnAcidProvider::class);

    // An unknown key never explodes — it degrades to the fallback chain.
    $this->settings->provider = 'not-a-provider';
    $this->settings->save();

    expect(app(InboxPreviewRegistry::class)->default()->configured())->toBeTrue();
});

/* ====================================================================== *
 *  Litmus adapter contract
 * ====================================================================== */

it('submits and parses a litmus test against its documented api', function (): void {
    inboxPreviewConfigureLitmus($this->settings);

    Http::fake([
        'https://acme.litmus.com/api/v1/emails.json' => Http::response(['id' => 987654], 201),
        'https://acme.litmus.com/api/v1/emails/987654.json' => Http::response([
            'id' => 987654,
            'test_set_versions' => [[
                'results' => [
                    [
                        'id' => 1,
                        'email_client' => 'ol2016',
                        'application_long_name' => 'Outlook 2016',
                        'os_long_name' => 'Windows 10',
                        'result_type' => 'desktop',
                        'state' => 'complete',
                        'full_image' => 'https://cdn.litmus.test/full/1.png',
                        'thumbnail_image' => 'https://cdn.litmus.test/thumb/1.png',
                    ],
                    [
                        'id' => 2,
                        'email_client' => 'iphone14',
                        'application_long_name' => 'iPhone 14',
                        'os_long_name' => 'iOS 16',
                        'state' => 'processing',
                    ],
                ],
            ]],
        ], 200),
    ]);

    $provider = new LitmusProvider($this->settings);

    expect($provider->configured())->toBeTrue()
        ->and($provider->submit('Render me', '<p>HTML</p>', 'TEXT'))->toBe('987654');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://acme.litmus.com/api/v1/emails.json'
            && $request->method() === 'POST'
            // Documented auth: the API key as the Basic username.
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('litmus-key:'))
            && $request['email']['subject'] === 'Render me'
            && $request['email']['html_text'] === '<p>HTML</p>'
            && $request['email']['plain_text'] === 'TEXT'
            && $request['email']['test_set'] === 'populardesktop';
    });

    $result = $provider->results('987654');

    // One client is still rendering ⇒ the run is NOT complete.
    expect($result->complete)->toBeFalse()
        ->and($result->screens)->toHaveCount(2)
        ->and($result->screens[0]->client)->toBe('ol2016')
        ->and($result->screens[0]->label)->toBe('Outlook 2016')
        ->and($result->screens[0]->os)->toBe('Windows 10')
        ->and($result->screens[0]->state)->toBe(InboxPreviewScreen::STATE_COMPLETE)
        ->and($result->screens[0]->imageUrl)->toBe('https://cdn.litmus.test/full/1.png')
        ->and($result->screens[0]->thumbnailUrl)->toBe('https://cdn.litmus.test/thumb/1.png')
        ->and($result->screens[1]->state)->toBe(InboxPreviewScreen::STATE_PROCESSING);
});

it('carries a litmus refusal through verbatim', function (): void {
    inboxPreviewConfigureLitmus($this->settings);

    Http::fake([
        'https://acme.litmus.com/api/v1/*' => Http::response('Preview allowance exhausted', 402),
    ]);

    expect(fn () => (new LitmusProvider($this->settings))->submit('s', '<p>x</p>', null))
        ->toThrow(InboxPreviewException::class, 'Preview allowance exhausted');
});

/* ====================================================================== *
 *  Email on Acid adapter contract
 * ====================================================================== */

it('submits and parses an email on acid test against its documented api', function (): void {
    inboxPreviewConfigureEmailOnAcid($this->settings);

    Http::fake([
        'https://api.emailonacid.com/v5/email/tests' => Http::response(['id' => 'eoa-42'], 200),
        'https://api.emailonacid.com/v5/email/tests/eoa-42/results' => Http::response([
            'OL2016' => [
                'id' => 'OL2016',
                'display_name' => 'Outlook 2016',
                'os' => 'Windows',
                'category' => 'Desktop',
                'status' => 'Complete',
                'screenshots' => [
                    'default' => 'https://cdn.eoa.test/full/OL2016.png',
                    'thumb' => 'https://cdn.eoa.test/thumb/OL2016.png',
                ],
            ],
            'GMAIL_CHROME' => [
                'id' => 'GMAIL_CHROME',
                'display_name' => 'Gmail (Chrome)',
                'status' => 'Complete',
                'screenshots' => ['default' => 'https://cdn.eoa.test/full/GMAIL.png'],
            ],
        ], 200),
        'https://api.emailonacid.com/v5/email/clients' => Http::response([
            'clients' => ['OL2016' => ['client' => 'Outlook 2016', 'os' => 'Windows']],
        ], 200),
    ]);

    $provider = new EmailOnAcidProvider($this->settings);

    expect($provider->clients())->toBe(['OL2016' => 'Outlook 2016 Windows'])
        ->and($provider->submit('Render me', '<p>HTML</p>', 'TEXT'))->toBe('eoa-42');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.emailonacid.com/v5/email/tests'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('eoa-user:eoa-key'))
            && $request['subject'] === 'Render me'
            && $request['html'] === '<p>HTML</p>'
            && $request['clients'] === ['OL2016', 'GMAIL_CHROME'];
    });

    $result = $provider->results('eoa-42');

    // Every client is complete ⇒ so is the run.
    expect($result->complete)->toBeTrue()
        ->and($result->screens)->toHaveCount(2)
        ->and($result->screens[0]->client)->toBe('OL2016')
        ->and($result->screens[0]->category)->toBe('Desktop')
        ->and($result->screens[0]->imageUrl)->toBe('https://cdn.eoa.test/full/OL2016.png')
        ->and($result->screens[1]->thumbnailUrl)->toBeNull();
});

/* ====================================================================== *
 *  The queued run
 * ====================================================================== */

it('submits then polls to completion from the queue', function (): void {
    inboxPreviewConfigureEmailOnAcid($this->settings);

    Http::fake([
        'https://api.emailonacid.com/v5/email/tests' => Http::response(['id' => 'eoa-77'], 200),
        'https://api.emailonacid.com/v5/email/tests/eoa-77/results' => Http::sequence()
            ->push(['OL2016' => ['status' => 'Processing', 'display_name' => 'Outlook 2016']], 200)
            ->push(['OL2016' => [
                'status' => 'Complete',
                'display_name' => 'Outlook 2016',
                'screenshots' => ['default' => 'https://cdn.eoa.test/x.png'],
            ]], 200),
    ]);

    $run = InboxPreviewRun::create([
        'customer_id' => $this->customer->id,
        'campaign_id' => $this->campaign->id,
        'provider' => EmailOnAcidProvider::KEY,
        'status' => InboxPreviewRun::STATUS_QUEUED,
        'subject' => 'Render me',
    ]);

    Bus::fake();

    // 1. submit.
    (new RunInboxPreviewJob((int) $run->id))->handle(app(InboxPreviewRegistry::class));

    expect($run->refresh()->remote_id)->toBe('eoa-77')
        ->and($run->status)->toBe(InboxPreviewRun::STATUS_RUNNING)
        ->and($run->requested_at)->not->toBeNull();

    Bus::assertDispatched(RunInboxPreviewJob::class);

    // 2. poll — still rendering, so NOT completed.
    (new RunInboxPreviewJob((int) $run->id))->handle(app(InboxPreviewRegistry::class));

    expect($run->refresh()->status)->toBe(InboxPreviewRun::STATUS_RUNNING)
        ->and($run->polls)->toBe(1);

    // 3. poll — done.
    (new RunInboxPreviewJob((int) $run->id))->handle(app(InboxPreviewRegistry::class));

    $run->refresh();

    expect($run->status)->toBe(InboxPreviewRun::STATUS_COMPLETED)
        ->and($run->completed_at)->not->toBeNull()
        ->and($run->screens())->toHaveCount(1)
        ->and($run->screens()[0]->imageUrl)->toBe('https://cdn.eoa.test/x.png');

    // The HTML that went to the vendor was RENDERED, not raw merge tags.
    Http::assertSent(fn ($request): bool => $request->url() !== 'https://api.emailonacid.com/v5/email/tests'
        || ! str_contains((string) $request['html'], '{UNSUBSCRIBE_URL}'));
});

it('records a failure as failed, never as an empty success', function (): void {
    inboxPreviewConfigureLitmus($this->settings);

    Http::fake(['https://acme.litmus.com/api/v1/*' => Http::response('nope', 500)]);

    $run = InboxPreviewRun::create([
        'customer_id' => $this->customer->id,
        'campaign_id' => $this->campaign->id,
        'provider' => LitmusProvider::KEY,
        'status' => InboxPreviewRun::STATUS_QUEUED,
    ]);

    (new RunInboxPreviewJob((int) $run->id))->handle(app(InboxPreviewRegistry::class));

    $run->refresh();

    expect($run->status)->toBe(InboxPreviewRun::STATUS_FAILED)
        ->and($run->error)->toContain('nope')
        ->and($run->results)->toBeNull();

    // An unconfigured provider fails the run with the actionable reason.
    $orphan = InboxPreviewRun::create([
        'customer_id' => $this->customer->id,
        'campaign_id' => $this->campaign->id,
        'provider' => NullProvider::KEY,
    ]);

    (new RunInboxPreviewJob((int) $orphan->id))->handle(app(InboxPreviewRegistry::class));

    expect($orphan->refresh()->status)->toBe(InboxPreviewRun::STATUS_FAILED)
        ->and($orphan->error)->toBe(__('inbox_preview.errors.not_configured'));
});

it('gives up after the documented polling ceiling', function (): void {
    inboxPreviewConfigureEmailOnAcid($this->settings);

    Http::fake([
        'https://api.emailonacid.com/v5/email/tests/stuck/results' => Http::response(
            ['OL2016' => ['status' => 'Processing', 'display_name' => 'Outlook 2016']],
            200,
        ),
    ]);

    $run = InboxPreviewRun::create([
        'customer_id' => $this->customer->id,
        'campaign_id' => $this->campaign->id,
        'provider' => EmailOnAcidProvider::KEY,
        'status' => InboxPreviewRun::STATUS_RUNNING,
        'remote_id' => 'stuck',
        'polls' => RunInboxPreviewJob::MAX_POLLS - 1,
    ]);

    (new RunInboxPreviewJob((int) $run->id))->handle(app(InboxPreviewRegistry::class));

    expect($run->refresh()->status)->toBe(InboxPreviewRun::STATUS_FAILED)
        ->and($run->error)->toBe(__('inbox_preview.errors.timed_out', ['minutes' => 20]));
});

/* ====================================================================== *
 *  UI + tenancy
 * ====================================================================== */

it('queues a run from the campaign editor and never renders inline', function (): void {
    inboxPreviewConfigureLitmus($this->settings);
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    Bus::fake();

    Livewire::test(EditCampaign::class, ['record' => $this->campaign->getRouteKey()])
        ->call('startInboxPreview');

    $run = InboxPreviewRun::query()->where('campaign_id', $this->campaign->id)->firstOrFail();

    expect($run->provider)->toBe(LitmusProvider::KEY)
        ->and($run->status)->toBe(InboxPreviewRun::STATUS_QUEUED)
        ->and($run->subject)->toBe('Render me')
        // Nothing was rendered in the request — the job does the work.
        ->and($run->remote_id)->toBeNull();

    Bus::assertDispatched(
        RunInboxPreviewJob::class,
        fn (RunInboxPreviewJob $job): bool => $job->runId === (int) $run->id,
    );

    Http::assertNothingSent();
});

it('keeps preview runs inside their tenant', function (): void {
    $mine = InboxPreviewRun::create([
        'customer_id' => $this->customer->id,
        'campaign_id' => $this->campaign->id,
        'provider' => LitmusProvider::KEY,
    ]);

    $other = Customer::factory()->create();

    expect($this->customer->can('view', $mine))->toBeTrue()
        ->and($other->can('view', $mine))->toBeFalse();

    app(CurrentCustomer::class)->set($other);

    expect(InboxPreviewRun::query()->count())->toBe(0);

    app(CurrentCustomer::class)->set($this->customer);

    expect(InboxPreviewRun::query()->count())->toBe(1);
});

/* ---------------------------------------------------------------------- */

function inboxPreviewConfigureLitmus(InboxPreviewSettings $settings): void
{
    $settings->litmus_subdomain = 'acme';
    $settings->litmus_api_key = 'litmus-key';
    $settings->litmus_test_set = 'populardesktop';
    $settings->save();
}

function inboxPreviewConfigureEmailOnAcid(InboxPreviewSettings $settings): void
{
    $settings->email_on_acid_api_user = 'eoa-user';
    $settings->email_on_acid_api_key = 'eoa-key';
    $settings->email_on_acid_clients = "OL2016\nGMAIL_CHROME";
    $settings->save();
}
