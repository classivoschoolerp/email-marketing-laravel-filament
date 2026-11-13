<?php

declare(strict_types=1);

use App\Enums\CampaignStatus;
use App\Enums\SubscriberStatus;
use App\Models\Campaign;
use App\Models\DeliveryServer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Sending\DriverRegistry;
use App\Sending\Message;
use App\Sending\MimeBuilder;
use Illuminate\Support\Carbon;
use Tests\Fakes\RecordingSendingDriver;

/*
 * WP-22 — F-4.5-005: List-Unsubscribe + One-Click (RFC 8058) on REAL sends.
 * Proved end to end through the WP-13 pipeline with a recording driver: both
 * headers present on the wire, and the advertised POST endpoint actually
 * unsubscribes.
 */

beforeEach(function (): void {
    config(['app.url' => 'http://localhost']);

    $this->list = EmailList::factory()->create([
        'visible' => true,
        'status' => EmailList::STATUS_ACTIVE,
        'from_email' => 'list@acme.test',
        'company_name' => 'Acme Inc.',
        'company_address_1' => '1 Acme Way',
        'company_city' => 'Springfield',
    ]);

    $this->subscriber = Subscriber::factory()->create([
        'list_id' => $this->list->id,
        'customer_id' => $this->list->customer_id,
        'email' => 'reader@example.com',
        'status' => SubscriberStatus::Confirmed,
    ]);

    $this->driver = new RecordingSendingDriver('smtp');
    app(DriverRegistry::class)->register('smtp', $this->driver);

    $this->server = DeliveryServer::factory()->create([
        'customer_id' => $this->list->customer_id,
        'type' => 'smtp',
    ]);

    $this->campaign = Campaign::factory()->create([
        'customer_id' => $this->list->customer_id,
        'email_list_id' => $this->list->id,
        'status' => CampaignStatus::Scheduled,
        'send_at' => Carbon::now()->subMinute(),
        'subject' => 'Newsletter',
        'from_email' => 'news@acme.test',
        'content_html' => '<html><body><p>Hi</p><p><a href="{UNSUBSCRIBE_URL}">Unsubscribe</a></p></body></html>',
    ]);
});

// F-4.5-005 — both headers on a real send through the WP-13 pipeline.
it('stamps List-Unsubscribe and List-Unsubscribe-Post on every sent message', function (): void {
    $this->artisan('campaigns:send')->assertSuccessful();

    expect($this->driver->sent)->toHaveCount(1);

    /** @var Message $message */
    $message = $this->driver->sent[0]['message'];

    $oneClickUrl = route('lists.public.unsubscribe.one_click', [
        'list_uid' => $this->list->uid,
        'subscriber_uid' => $this->subscriber->uid,
    ]);

    expect($message->headers)->toHaveKey('List-Unsubscribe')
        ->and($message->headers)->toHaveKey('List-Unsubscribe-Post')
        // RFC 8058 §3.1: the POST directive must be exactly this token.
        ->and($message->headers['List-Unsubscribe-Post'])->toBe('List-Unsubscribe=One-Click')
        // RFC 2369 angle-bracket URI list, https target first.
        ->and($message->headers['List-Unsubscribe'])->toStartWith('<'.$oneClickUrl.'>')
        ->and($message->headers['List-Unsubscribe'])->toContain('<mailto:list@acme.test?subject=unsubscribe>');
});

it('carries both headers into the RFC 5322 message built for the wire', function (): void {
    $this->artisan('campaigns:send')->assertSuccessful();

    /** @var Message $message */
    $message = $this->driver->sent[0]['message'];

    $raw = app(MimeBuilder::class)->build($message, $this->server)->toString();

    expect($raw)->toContain('List-Unsubscribe:')
        ->and($raw)->toContain('List-Unsubscribe-Post: List-Unsubscribe=One-Click')
        ->and($raw)->toContain('unsubscribe-one-click');
});

// F-4.5-005 — the advertised endpoint really works (mail providers POST it).
it('unsubscribes through the advertised one-click endpoint without a session', function (): void {
    $this->artisan('campaigns:send')->assertSuccessful();

    /** @var Message $message */
    $message = $this->driver->sent[0]['message'];

    // Extract the URI exactly as a mail provider would parse the header.
    preg_match('/<(https?:\/\/[^>]+)>/', $message->headers['List-Unsubscribe'], $matches);

    expect($matches[1] ?? null)->not->toBeNull();

    // No CSRF token, no session — RFC 8058 machine POST with the required body.
    $this->post($matches[1], ['List-Unsubscribe' => 'One-Click'])
        ->assertOk()
        ->assertSee(__('public.lists.one_click_done'));

    expect($this->subscriber->fresh()->status)->toBe(SubscriberStatus::Unsubscribed)
        ->and($this->subscriber->fresh()->unsubscribed_at)->not->toBeNull();
});

it('answers the one-click endpoint idempotently', function (): void {
    $url = route('lists.public.unsubscribe.one_click', [
        'list_uid' => $this->list->uid,
        'subscriber_uid' => $this->subscriber->uid,
    ]);

    $this->post($url, ['List-Unsubscribe' => 'One-Click'])->assertOk();
    $this->post($url, ['List-Unsubscribe' => 'One-Click'])->assertOk();

    expect($this->subscriber->fresh()->status)->toBe(SubscriberStatus::Unsubscribed);
});
