<?php

declare(strict_types=1);

use App\Contracts\CampaignWebViewSource;
use App\Http\Controllers\Lists\PublicSubscribeController;
use App\Mail\CampaignForwardMail;
use App\Models\Campaign;
use App\Models\CampaignForward;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Services\Campaigns\CampaignForwardService;
use App\Services\Reports\CampaignReportService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/*
 * §1.6 F-1.6-002 forward to a friend + §1.7 F-1.7-004 forward tracker.
 * Endpoint: /campaigns/{campaign_uid}/track-forward/{subscriber_uid}
 */

beforeEach(function (): void {
    Mail::fake();

    $this->customer = Customer::factory()->create();
    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);

    $this->campaign = Campaign::factory()->sent()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
        'name' => 'August newsletter',
        'subject' => 'Hello 🎉',
        'content_html' => '<html><body><p>Body copy</p></body></html>',
    ]);

    $this->subscriber = Subscriber::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
        'email' => 'reader@example.test',
    ]);

    $this->url = route('campaigns.track.forward', [
        'campaign_uid' => $this->campaign->uid,
        'subscriber_uid' => $this->subscriber->uid,
    ]);

    $this->submitUrl = route('campaigns.track.forward.submit', [
        'campaign_uid' => $this->campaign->uid,
        'subscriber_uid' => $this->subscriber->uid,
    ]);
});

// F-1.7-004: the tracker URL serves the public form.
it('serves the forward form at the tracker url', function (): void {
    $this->get($this->url)
        ->assertOk()
        ->assertSee(__('campaigns.forward.title'))
        ->assertSee('Hello 🎉', escape: false)
        ->assertSee(PublicSubscribeController::HONEYPOT_FIELD, escape: false);
});

it('404s for an unknown campaign or subscriber uid', function (): void {
    $this->get(route('campaigns.track.forward', [
        'campaign_uid' => 'nope',
        'subscriber_uid' => $this->subscriber->uid,
    ]))->assertNotFound();

    $this->get(route('campaigns.track.forward', [
        'campaign_uid' => $this->campaign->uid,
        'subscriber_uid' => 'nope',
    ]))->assertNotFound();
});

// F-1.6-002: the forward is recorded and the mail is QUEUED.
it('records the forwards and queues one mail per recipient', function (): void {
    $this->post($this->submitUrl, [
        'recipients' => ['friend-one@example.test', 'FRIEND-TWO@example.test'],
        'note' => "Thought you'd like this",
    ])->assertRedirect()->assertSessionHas('public_status', 'campaign_forwarded');

    $forwards = CampaignForward::query()->where('campaign_uid', $this->campaign->uid)->get();

    expect($forwards)->toHaveCount(2)
        ->and($forwards->pluck('recipient_email')->sort()->values()->all())
        ->toBe(['friend-one@example.test', 'friend-two@example.test'])
        ->and($forwards->pluck('subscriber_uid')->unique()->all())->toBe([$this->subscriber->uid])
        ->and($forwards->pluck('status')->unique()->all())->toBe([CampaignForward::STATUS_SENT])
        ->and($forwards->first()->personal_note)->toBe("Thought you'd like this");

    Mail::assertQueued(CampaignForwardMail::class, 2);
    Mail::assertQueued(
        CampaignForwardMail::class,
        fn (CampaignForwardMail $mail): bool => $mail->subjectLine === 'Hello 🎉'
            && str_contains($mail->htmlBody, 'Body copy')
            && str_contains($mail->htmlBody, "Thought you&#039;d like this"),
    );
});

// The forwarded body is the WEB VIEW, so no personal identifiers ride along.
it('forwards the web-view body, not a personalised render', function (): void {
    $this->post($this->submitUrl, ['recipients' => ['friend@example.test']]);

    Mail::assertQueued(
        CampaignForwardMail::class,
        fn (CampaignForwardMail $mail): bool => ! str_contains($mail->htmlBody, $this->subscriber->uid)
            && ! str_contains($mail->htmlBody, 'track-opening'),
    );
});

// Anti-abuse: honeypot pretends success and stores nothing.
it('honours the honeypot without recording anything', function (): void {
    $this->post($this->submitUrl, [
        'recipients' => ['bot@example.test'],
        PublicSubscribeController::HONEYPOT_FIELD => 'filled by a bot',
    ])->assertRedirect()->assertSessionHas('public_status', 'campaign_forwarded');

    expect(CampaignForward::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

// Anti-abuse: a failing captcha blocks the submission.
it('blocks the submission when the captcha fails', function (): void {
    config()->set('emp.captcha.driver', 'recaptcha-v2');
    config()->set('emp.captcha.site_key', 'site-key-123');
    config()->set('emp.captcha.secret_key', 'secret-key-123');
    Http::fake(['*' => Http::response(['success' => false])]);

    $this->post($this->submitUrl, [
        'recipients' => ['friend@example.test'],
        'g-recaptcha-response' => 'token',
    ])->assertSessionHasErrors('captcha');

    expect(CampaignForward::query()->count())->toBe(0);
});

// Anti-abuse: the same (forwarder, recipient) pair is one-shot.
it('does not forward the same campaign to the same address twice', function (): void {
    $this->post($this->submitUrl, ['recipients' => ['friend@example.test']]);
    $this->post($this->submitUrl, ['recipients' => ['friend@example.test']]);

    expect(CampaignForward::query()->count())->toBe(1);
    Mail::assertQueued(CampaignForwardMail::class, 1);
});

it('caps the number of recipients per submission', function (): void {
    $addresses = [];

    for ($i = 0; $i < CampaignForwardService::MAX_RECIPIENTS + 3; $i++) {
        $addresses[] = 'friend'.$i.'@example.test';
    }

    $this->post($this->submitUrl, ['recipients' => $addresses])
        ->assertSessionHasErrors('recipients');

    expect(CampaignForward::query()->count())->toBe(0);
});

it('rejects a submission with no usable address', function (): void {
    $this->post($this->submitUrl, ['recipients' => ['', '  ']])
        ->assertSessionHasErrors('recipients');

    expect(CampaignForward::query()->count())->toBe(0);
});

it('rejects an invalid email address', function (): void {
    $this->post($this->submitUrl, ['recipients' => ['not-an-email']])
        ->assertSessionHasErrors('recipients.0');
});

// The IP is written through IpAnonymizer like every other §1.7 tracking row.
it('anonymises the recorded ip when the privacy toggle is on', function (): void {
    app(\App\Settings\PrivacySettings::class)->fill(['anonymize_ips' => true])->save();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.45'])
        ->post($this->submitUrl, ['recipients' => ['friend@example.test']]);

    expect(CampaignForward::query()->value('ip'))->toBe('203.0.113.0');
});

// F-2.3.8-001: the report's Forwards column is real now.
it('reports forwards on the campaign report header stats', function (): void {
    $this->post($this->submitUrl, ['recipients' => ['a@example.test', 'b@example.test']]);

    $stats = app(CampaignReportService::class)->headerStats($this->campaign);

    expect($stats['forwards'])->toBe(2)
        ->and($stats['forwarders'])->toBe(1);
});

// F-4.1-005: {FORWARD_FRIEND_URL} resolves to this endpoint.
it('resolves the forward merge tags to the tracker url', function (): void {
    $values = app(\App\Services\Content\MergeTags::class)
        ->values($this->campaign, $this->list, $this->subscriber);

    expect($values['FORWARD_FRIEND_URL'])->toBe($this->url)
        ->and($values['FORWARD_URL'])->toBe($this->url);
});

// A campaign with no renderable body cannot produce an empty forwarded email.
it('records nothing when the campaign has no web view', function (): void {
    app()->bind(CampaignWebViewSource::class, fn () => new class implements CampaignWebViewSource
    {
        public function webViewHtml(string $campaignUid): ?string
        {
            return null;
        }

        public function shareMeta(string $campaignUid): ?array
        {
            return null;
        }

        public function reportHtml(string $secret): ?string
        {
            return null;
        }
    });

    $this->post($this->submitUrl, ['recipients' => ['friend@example.test']]);

    expect(CampaignForward::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});
