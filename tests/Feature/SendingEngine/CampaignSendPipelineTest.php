<?php

declare(strict_types=1);

use App\Enums\CampaignStatus;
use App\Jobs\Sending\SendToSubscriberJob;
use App\Mail\CampaignFinishedMail;
use App\Models\Campaign;
use App\Models\CampaignDeliveryLog;
use App\Models\CampaignTrackUrl;
use App\Models\DeliveryServer;
use App\Models\EmailList;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Models\SubscriberFieldValue;
use App\Sending\DriverRegistry;
use App\Sending\Message;
use App\Sending\MimeBuilder;
use App\Sending\SendingDriver;
use App\Sending\SendResult;
use App\Services\Campaigns\CampaignStatusMachine;
use App\Services\SpamCheck\SpamCheckerInterface;
use App\Services\SpamCheck\SpamCheckResult;
use App\Services\Tracking\UrlSigner;
use App\Settings\CampaignSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Fakes\RecordingSendingDriver;

/*
 * WP-13 campaign send pipeline (spec §0.6 F-0.6-001, §4.6): batching,
 * per-recipient personalization, tracking instrumentation, idempotency,
 * throttling, windows, pause/resume, spam gate, retries and finish.
 */

/** Scripted driver: returns queued results in order (then success). */
function scriptedDriver(array $results = []): SendingDriver
{
    return new class($results) implements SendingDriver
    {
        /** @var list<Message> */
        public array $sent = [];

        public ?Closure $afterSend = null;

        public function __construct(public array $results) {}

        public function key(): string
        {
            return 'smtp';
        }

        public function label(): string
        {
            return 'Scripted fake';
        }

        public function send(Message $message, DeliveryServer $server): SendResult
        {
            $this->sent[] = $message;
            $result = array_shift($this->results) ?? SendResult::success('fake-'.count($this->sent));

            if ($this->afterSend !== null) {
                ($this->afterSend)($message, $result);
            }

            return $result;
        }

        public function validateConfig(DeliveryServer $server): array
        {
            return [];
        }

        public function capabilities(): array
        {
            return ['transport' => 'fake'];
        }

        public function formSchema(): array
        {
            return [];
        }
    };
}

beforeEach(function (): void {
    config(['app.url' => 'http://localhost']);

    $this->list = EmailList::factory()->create([
        'name' => 'Pipeline List',
        'company_name' => 'Acme Inc.',
        'company_address_1' => '1 Acme Way',
        'company_city' => 'Springfield',
        'company_country' => 'US',
    ]);

    $this->field = ListField::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->list->customer_id,
        'tag' => 'FNAME',
    ]);

    $this->subscriberA = Subscriber::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->list->customer_id,
        'email' => 'anna@example.com',
    ]);
    $this->subscriberB = Subscriber::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->list->customer_id,
        'email' => 'taro@example.jp',
    ]);

    foreach ([[$this->subscriberA, 'Anna'], [$this->subscriberB, '太郎']] as [$subscriber, $name]) {
        SubscriberFieldValue::withoutTenancy()->create([
            'customer_id' => $this->list->customer_id,
            'subscriber_id' => $subscriber->id,
            'list_field_id' => $this->field->id,
            'value' => $name,
        ]);
    }

    $this->server = DeliveryServer::factory()->create([
        'customer_id' => $this->list->customer_id,
        'type' => 'smtp',
    ]);

    $this->driver = scriptedDriver();
    app(DriverRegistry::class)->register('smtp', $this->driver);

    $this->campaign = Campaign::factory()->create([
        'customer_id' => $this->list->customer_id,
        'email_list_id' => $this->list->id,
        'status' => CampaignStatus::Scheduled,
        'send_at' => Carbon::now()->subMinute(),
        'subject' => 'ようこそ {FNAME|Friend} 🎉',
        'content_html' => '<html><body><p>Hi {FNAME|Friend}</p>'
            .'<p><a href="https://shop.example.com/product?x=1">Buy</a></p>'
            .'<p><a href="{UNSUBSCRIBE_URL}">Unsubscribe</a></p></body></html>',
        'utm_enabled' => true,
        'utm_params' => ['source' => 'newsletter', 'medium' => 'email', 'campaign' => '{CAMPAIGN_NAME}'],
        'custom_headers' => [['name' => 'X-Campaign-Tag', 'value' => '{CAMPAIGN_UID}']],
        'notify_on_finish' => true,
    ]);
});

it('sends a full campaign: personalization, tracking, headers, footer, finish', function (): void {
    Mail::fake();

    $this->artisan('campaigns:send')->assertSuccessful();

    expect($this->driver->sent)->toHaveCount(2);

    $byRecipient = collect($this->driver->sent)->keyBy(fn (Message $m): string => (string) $m->firstRecipient());

    $annaMail = $byRecipient['anna@example.com'];
    $taroMail = $byRecipient['taro@example.jp'];

    // Per-recipient personalization differs — subject and body (CJK e2e).
    expect($annaMail->subject)->toBe('ようこそ Anna 🎉')
        ->and($taroMail->subject)->toBe('ようこそ 太郎 🎉')
        ->and($annaMail->html)->toContain('Hi Anna')
        ->and($taroMail->html)->toContain('Hi 太郎')
        ->and($annaMail->html)->not->toBe($taroMail->html);

    // Open pixel present, per subscriber (F-1.7-001).
    expect($annaMail->html)->toContain('/track-opening/'.$this->subscriberA->uid)
        ->and($taroMail->html)->toContain('/track-opening/'.$this->subscriberB->uid);

    // Click URL rewritten through the signed redirect + track row + UTM.
    $trackUrl = CampaignTrackUrl::query()->where('campaign_id', $this->campaign->id)->first();

    expect($trackUrl)->not->toBeNull()
        ->and($trackUrl->url)->toContain('https://shop.example.com/product?x=1')
        ->and($trackUrl->url)->toContain('utm_source=newsletter')
        ->and($trackUrl->url)->toContain('utm_campaign='.rawurlencode($this->campaign->name))
        ->and($trackUrl->hash)->toBe(app(UrlSigner::class)->hash($trackUrl->url))
        ->and($annaMail->html)->toContain('/track-url/'.$this->subscriberA->uid.'/'.$trackUrl->hash)
        ->and($annaMail->html)->not->toContain('https://shop.example.com/product');

    // The unsubscribe merge-tag URL stays functional (never click-tracked).
    expect($annaMail->html)->toContain('/track-unsubscribe/'.$this->subscriberA->uid);

    // RFC 8058 + attribution + custom X-headers, tag-rendered.
    expect($annaMail->headers['List-Unsubscribe'] ?? '')->toContain('unsubscribe-one-click')
        ->and($annaMail->headers['List-Unsubscribe-Post'] ?? '')->toBe('List-Unsubscribe=One-Click')
        ->and($annaMail->headers['X-EMP-Campaign-Uid'] ?? '')->toBe($this->campaign->uid)
        ->and($annaMail->headers['X-EMP-Subscriber-Uid'] ?? '')->toBe($this->subscriberA->uid)
        ->and($annaMail->headers['X-Campaign-Tag'] ?? '')->toBe($this->campaign->uid);

    // CAN-SPAM footer (list company block).
    expect($annaMail->html)->toContain('Acme Inc.')
        ->and($annaMail->html)->toContain('1 Acme Way');

    // Auto plain text derived from the tracked html.
    expect($annaMail->text)->toContain('Hi Anna');

    // RFC 2822 construction round-trips CJK (F-0.1-018).
    $raw = app(MimeBuilder::class)->build($taroMail, $this->server)->toString();
    expect($raw)->toContain('=?utf-8')
        ->and(strlen($raw))->toBeGreaterThan(100);

    // Delivery logs + counters + finish transition.
    $campaign = $this->campaign->refresh();

    expect(CampaignDeliveryLog::query()->where('campaign_id', $campaign->id)->where('status', 'sent')->count())->toBe(2)
        ->and(CampaignDeliveryLog::query()->where('campaign_id', $campaign->id)->value('message_id'))->not->toBeNull()
        ->and((int) $campaign->delivery_total)->toBe(2)
        ->and((int) $campaign->delivery_sent)->toBe(2)
        ->and((int) $campaign->delivery_failed)->toBe(0)
        ->and($campaign->status)->toBe(CampaignStatus::Sent)
        ->and($campaign->send_finished_at)->not->toBeNull()
        ->and($campaign->send_started_at)->not->toBeNull()
        ->and($campaign->send_batch_id)->not->toBeNull()
        ->and($campaign->sendProgressPercent())->toBe(100);

    // notify_on_finish mail queued to the owner (F-2.3.4-021).
    Mail::assertQueued(CampaignFinishedMail::class);
});

it('honors url tracking exceptions', function (): void {
    $this->campaign->forceFill([
        'url_tracking_exceptions' => ['https://shop.example.com/*'],
    ])->saveQuietly();

    $this->artisan('campaigns:send')->assertSuccessful();

    $mail = $this->driver->sent[0];

    expect($mail->html)->toContain('https://shop.example.com/product')
        ->and($mail->html)->toContain('utm_source=newsletter')
        ->and(CampaignTrackUrl::query()->where('campaign_id', $this->campaign->id)->count())->toBe(0);
});

it('is idempotent: the same send job run twice delivers once', function (): void {
    $this->campaign->forceFill(['status' => CampaignStatus::Sending, 'send_started_at' => now()])->saveQuietly();

    $job = fn (): SendToSubscriberJob => new SendToSubscriberJob(
        $this->campaign->id,
        $this->subscriberA->id,
        $this->campaign->uid,
        $this->subscriberA->uid,
    );

    dispatch($job());
    dispatch($job());

    expect($this->driver->sent)->toHaveCount(1)
        ->and(CampaignDeliveryLog::query()->where('campaign_id', $this->campaign->id)->count())->toBe(1)
        ->and(DB::table('job_idempotency_keys')
            ->where('key', 'send:'.$this->campaign->uid.':'.$this->subscriberA->uid)
            ->exists())->toBeTrue();
});

it('treats an existing delivery-log row as the second idempotency arbiter', function (): void {
    $this->campaign->forceFill(['status' => CampaignStatus::Sending, 'send_started_at' => now()])->saveQuietly();

    CampaignDeliveryLog::query()->create([
        'campaign_id' => $this->campaign->id,
        'subscriber_id' => $this->subscriberA->id,
        'status' => CampaignDeliveryLog::STATUS_SENT,
        'message_id' => 'earlier-worker',
    ]);

    dispatch(new SendToSubscriberJob(
        $this->campaign->id,
        $this->subscriberA->id,
        $this->campaign->uid,
        $this->subscriberA->uid,
    ));

    expect($this->driver->sent)->toHaveCount(0)
        ->and(CampaignDeliveryLog::query()->where('campaign_id', $this->campaign->id)->count())->toBe(1);
});

it('throttles by send_rate_per_hour and resumes the remainder later', function (): void {
    $this->campaign->forceFill(['send_rate_per_hour' => 1])->saveQuietly();

    $this->artisan('campaigns:send')->assertSuccessful();

    $campaign = $this->campaign->refresh();

    // One sent, the second deferred: still sending, wave handle cleared.
    expect($this->driver->sent)->toHaveCount(1)
        ->and((int) $campaign->delivery_sent)->toBe(1)
        ->and($campaign->status)->toBe(CampaignStatus::Sending)
        ->and($campaign->send_batch_id)->toBeNull();

    // The deferred unit's idempotency key was released with the job.
    expect(DB::table('job_idempotency_keys')->where('key', 'like', 'send:%')->count())->toBe(1);

    // Rate limit lifted → the resume wave completes the campaign.
    $campaign->forceFill(['send_rate_per_hour' => null])->saveQuietly();
    $this->artisan('campaigns:send')->assertSuccessful();

    $campaign = $campaign->refresh();

    expect($this->driver->sent)->toHaveCount(2)
        ->and((int) $campaign->delivery_sent)->toBe(2)
        ->and($campaign->status)->toBe(CampaignStatus::Sent)
        ->and(collect($this->driver->sent)->map(fn (Message $m) => $m->firstRecipient())->unique())->toHaveCount(2);
});

it('defers pickup while the send window is closed', function (): void {
    // Window = only tomorrow's weekday.
    $this->campaign->forceFill([
        'send_window_days' => [(string) Carbon::now()->addDay()->dayOfWeek],
    ])->saveQuietly();

    $this->artisan('campaigns:send')->assertSuccessful();

    expect($this->campaign->refresh()->status)->toBe(CampaignStatus::Scheduled)
        ->and($this->driver->sent)->toHaveCount(0);

    // Window open again (no restriction) → the campaign goes out.
    $this->campaign->forceFill(['send_window_days' => null])->saveQuietly();
    $this->artisan('campaigns:send')->assertSuccessful();

    expect($this->campaign->refresh()->status)->toBe(CampaignStatus::Sent);
});

it('honors the send window start/end hours in the campaign timezone', function (): void {
    Carbon::setTestNow('2026-08-08 03:00:00'); // 03:00 UTC

    $this->campaign->forceFill([
        'send_at' => Carbon::now()->subMinute(),
        'send_window_start' => '09:00:00',
        'send_window_end' => '17:00:00',
        'timezone' => 'UTC',
    ])->saveQuietly();

    $this->artisan('campaigns:send')->assertSuccessful();
    expect($this->campaign->refresh()->status)->toBe(CampaignStatus::Scheduled);

    Carbon::setTestNow('2026-08-08 10:00:00');
    $this->artisan('campaigns:send')->assertSuccessful();
    expect($this->campaign->refresh()->status)->toBe(CampaignStatus::Sent);

    Carbon::setTestNow();
});

it('honors pause mid-batch and resumes without duplicate sends', function (): void {
    $third = Subscriber::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->list->customer_id,
        'email' => 'third@example.com',
    ]);

    // Pause the campaign right after the first successful delivery.
    $this->driver->afterSend = function (): void {
        $campaign = Campaign::withoutTenancy()->find($this->campaign->id);

        if ($campaign->status === CampaignStatus::Sending) {
            app(CampaignStatusMachine::class)->transition($campaign, CampaignStatus::Paused);
        }
    };

    $this->artisan('campaigns:send')->assertSuccessful();

    $campaign = $this->campaign->refresh();

    expect($this->driver->sent)->toHaveCount(1)
        ->and($campaign->status)->toBe(CampaignStatus::Paused)
        ->and($campaign->send_batch_id)->toBeNull()
        ->and(CampaignDeliveryLog::query()->where('campaign_id', $campaign->id)->count())->toBe(1);

    // Resume (D-57: paused → sending because send_started_at is set).
    $this->driver->afterSend = null;
    app(CampaignStatusMachine::class)->resume($campaign);
    expect($campaign->status)->toBe(CampaignStatus::Sending);

    $this->artisan('campaigns:send')->assertSuccessful();

    $campaign = $campaign->refresh();
    $recipients = collect($this->driver->sent)->map(fn (Message $m) => $m->firstRecipient());

    expect($this->driver->sent)->toHaveCount(3)
        ->and($recipients->unique())->toHaveCount(3)
        ->and($campaign->status)->toBe(CampaignStatus::Sent)
        ->and((int) $campaign->delivery_sent)->toBe(3)
        ->and((int) $campaign->delivery_total)->toBe(3);
});

it('skips the send when the spam gate blocks (D-49)', function (): void {
    app()->instance(SpamCheckerInterface::class, new class implements SpamCheckerInterface
    {
        public function check(string $rawMessage): SpamCheckResult
        {
            return new SpamCheckResult(checked: true, score: 9.9, requiredScore: 5.0, isSpam: true, blocked: true);
        }
    });

    $this->artisan('campaigns:send')->assertSuccessful();

    $campaign = $this->campaign->refresh();

    expect($this->driver->sent)->toHaveCount(0)
        ->and(CampaignDeliveryLog::query()->where('campaign_id', $campaign->id)->where('status', 'skipped')->count())->toBe(2)
        ->and((int) $campaign->delivery_sent)->toBe(0)
        ->and($campaign->status)->toBe(CampaignStatus::Sent);

    $log = CampaignDeliveryLog::query()->where('campaign_id', $campaign->id)->first();
    expect((string) $log->response)->toContain('9.9');
});

it('retries a transient failure and succeeds on the next attempt', function (): void {
    $this->campaign->forceFill(['status' => CampaignStatus::Sending, 'send_started_at' => now()])->saveQuietly();

    $this->driver->results = [SendResult::transientFailure('451 4.7.1 greylisted')];

    $job = fn (): SendToSubscriberJob => new SendToSubscriberJob(
        $this->campaign->id,
        $this->subscriberA->id,
        $this->campaign->uid,
        $this->subscriberA->uid,
    );

    dispatch($job());

    $log = CampaignDeliveryLog::query()
        ->where('campaign_id', $this->campaign->id)
        ->where('subscriber_id', $this->subscriberA->id)
        ->firstOrFail();

    expect($log->status)->toBe(CampaignDeliveryLog::STATUS_RETRYING)
        ->and((int) $log->retries)->toBe(1)
        ->and((string) $log->response)->toContain('greylisted')
        ->and((int) $this->campaign->refresh()->delivery_sent)->toBe(0);

    // Redelivery (the release freed the idempotency key).
    dispatch($job());

    $log = $log->refresh();

    expect($log->status)->toBe(CampaignDeliveryLog::STATUS_SENT)
        ->and((int) $log->retries)->toBe(1)
        ->and($this->driver->sent)->toHaveCount(2)
        ->and((int) $this->campaign->refresh()->delivery_sent)->toBe(1);
});

it('marks a permanent failure failed without retrying', function (): void {
    $this->driver->results = [
        SendResult::permanentFailure('550 5.1.1 user unknown'),
        SendResult::success('ok-2'),
    ];

    $this->artisan('campaigns:send')->assertSuccessful();

    $campaign = $this->campaign->refresh();

    expect(CampaignDeliveryLog::query()->where('campaign_id', $campaign->id)->where('status', 'failed')->count())->toBe(1)
        ->and(CampaignDeliveryLog::query()->where('campaign_id', $campaign->id)->where('status', 'sent')->count())->toBe(1)
        ->and((int) $campaign->delivery_failed)->toBe(1)
        ->and((int) $campaign->delivery_sent)->toBe(1)
        ->and($campaign->status)->toBe(CampaignStatus::Sent);

    $failed = CampaignDeliveryLog::query()->where('status', 'failed')->first();
    expect((string) $failed->response)->toContain('user unknown');
});

it('auto-pauses when the bounce rate crosses the threshold (WP-15 seam)', function (): void {
    Schema::dropIfExists('campaign_bounce_logs');
    Schema::create('campaign_bounce_logs', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('campaign_id')->index();
        $table->unsignedBigInteger('subscriber_id')->nullable();
        $table->string('bounce_type', 8)->nullable();
        $table->timestamps();
    });

    $settings = app(CampaignSettings::class);
    $settings->bounce_auto_pause_percent = 50.0;
    $settings->save();

    // After the first delivery, register a bounce → rate 100% ≥ 50%.
    $this->driver->afterSend = function (): void {
        DB::table('campaign_bounce_logs')->insert([
            'campaign_id' => $this->campaign->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    $this->artisan('campaigns:send')->assertSuccessful();

    $campaign = $this->campaign->refresh();

    expect($campaign->status)->toBe(CampaignStatus::Paused)
        ->and($this->driver->sent)->toHaveCount(1)
        ->and((string) $campaign->notes)->toContain('50');

    Schema::dropIfExists('campaign_bounce_logs');
});

it('embeds images as CID parts when embed_images is on', function (): void {
    \Illuminate\Support\Facades\Http::fake([
        'cdn.example.com/*' => \Illuminate\Support\Facades\Http::response('PNGBYTES', 200, ['Content-Type' => 'image/png']),
    ]);

    $this->campaign->forceFill([
        'embed_images' => true,
        'content_html' => '<html><body><img src="https://cdn.example.com/logo.png">'
            .'<p>Hi {FNAME|Friend}</p><p><a href="{UNSUBSCRIBE_URL}">bye</a></p></body></html>',
    ])->saveQuietly();

    $this->artisan('campaigns:send')->assertSuccessful();

    $mail = $this->driver->sent[0];

    expect($mail->embedded)->toHaveCount(1)
        ->and($mail->embedded[0]['content'])->toBe('PNGBYTES')
        ->and($mail->embedded[0]['mime'])->toBe('image/png')
        ->and($mail->html)->toContain('cid:'.$mail->embedded[0]['cid'])
        ->and($mail->html)->not->toContain('https://cdn.example.com/logo.png')
        // ... but the tracking pixel stays remote.
        ->and($mail->html)->toContain('/track-opening/');
});

it('sends plain-text-only campaigns without an html part', function (): void {
    $this->campaign->forceFill(['plain_text_only' => true])->saveQuietly();

    $this->artisan('campaigns:send')->assertSuccessful();

    $mail = $this->driver->sent[0];

    expect($mail->html)->toBeNull()
        ->and((string) $mail->text)->toContain('Hi ')
        ->and((string) $mail->text)->not->toContain('<p>');
});

it('respects the max_emails cap', function (): void {
    $this->campaign->forceFill(['max_emails' => 1])->saveQuietly();

    $this->artisan('campaigns:send')->assertSuccessful();

    $campaign = $this->campaign->refresh();

    expect($this->driver->sent)->toHaveCount(1)
        ->and((int) $campaign->delivery_total)->toBe(1)
        ->and($campaign->status)->toBe(CampaignStatus::Sent);
});

it('restricts server picking to the campaign delivery_server_ids', function (): void {
    $other = DeliveryServer::factory()->create([
        'customer_id' => $this->list->customer_id,
        'type' => 'smtp',
    ]);

    $this->campaign->forceFill(['delivery_server_ids' => [$other->id]])->saveQuietly();

    $this->artisan('campaigns:send')->assertSuccessful();

    expect(CampaignDeliveryLog::query()
        ->where('campaign_id', $this->campaign->id)
        ->pluck('delivery_server_id')
        ->unique()
        ->all())->toBe([$other->id]);
});

it('reports live progress and ETA while sending (F-2.3.1-003)', function (): void {
    $campaign = $this->campaign;

    $campaign->forceFill([
        'status' => CampaignStatus::Sending,
        'send_started_at' => Carbon::now()->subSeconds(100),
        'delivery_total' => 100,
        'delivery_sent' => 40,
        'delivery_failed' => 10,
    ])->saveQuietly();

    $campaign = $campaign->refresh();

    expect($campaign->sendProgressPercent())->toBe(50)
        // 50 done in 100s → 2s per unit → 50 remaining ≈ 100s.
        ->and($campaign->sendEtaSeconds())->toBeGreaterThanOrEqual(90)
        ->and($campaign->sendEtaSeconds())->toBeLessThanOrEqual(110);

    $campaign->forceFill(['delivery_sent' => 90])->saveQuietly();
    expect($campaign->refresh()->sendProgressPercent())->toBe(100)
        ->and($campaign->refresh()->sendEtaSeconds())->toBeNull();
});
