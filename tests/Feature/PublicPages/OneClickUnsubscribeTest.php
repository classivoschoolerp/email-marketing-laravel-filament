<?php

declare(strict_types=1);

use App\Enums\SubscriberStatus;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Services\Sending\ListUnsubscribeHeaders;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->list = EmailList::factory()->for($this->customer)->create(['from_email' => 'news@acme.example']);
    $this->subscriber = Subscriber::factory()->for($this->customer)->for($this->list, 'list')
        ->create(['email' => 'oneclick@example.com']);
});

// F-1.5-015: RFC 8058 — POST with the standard body, immediate unsubscribe.
it('unsubscribes immediately on the RFC 8058 one-click POST', function (): void {
    $response = $this->call(
        'POST',
        route('lists.public.unsubscribe.one_click', [
            'list_uid' => $this->list->uid, 'subscriber_uid' => $this->subscriber->uid,
        ]),
        ['List-Unsubscribe' => 'One-Click'],
    );

    $response->assertOk();

    $this->subscriber->refresh();
    expect($this->subscriber->status)->toBe(SubscriberStatus::Unsubscribed)
        ->and($this->subscriber->unsubscribe_reason)->toBe('one-click')
        ->and($this->subscriber->unsubscribed_at)->not->toBeNull();

    // Idempotent: a second POST still answers 200.
    $this->call('POST', route('lists.public.unsubscribe.one_click', [
        'list_uid' => $this->list->uid, 'subscriber_uid' => $this->subscriber->uid,
    ]))->assertOk();
});

// The endpoint is CSRF-exempt — mail providers cannot carry a session token.
it('exempts the one-click endpoint from CSRF validation', function (): void {
    $route = Route::getRoutes()->getByName('lists.public.unsubscribe.one_click');

    expect($route)->not->toBeNull()
        ->and(in_array(ValidateCsrfToken::class, $route->excludedMiddleware(), true))->toBeTrue();
});

it('404s the one-click POST for unknown uids without changing anything', function (): void {
    $this->call('POST', route('lists.public.unsubscribe.one_click', [
        'list_uid' => $this->list->uid, 'subscriber_uid' => (string) \Illuminate\Support\Str::uuid(),
    ]))->assertNotFound();

    expect($this->subscriber->refresh()->status)->toBe(SubscriberStatus::Confirmed);
});

// F-1.5-015: the header helper WP-13 consumes when sending campaigns.
it('builds the RFC 8058 List-Unsubscribe headers for a subscriber', function (): void {
    $headers = app(ListUnsubscribeHeaders::class)->headersFor($this->subscriber);

    $expectedUrl = route('lists.public.unsubscribe.one_click', [
        'list_uid' => $this->list->uid, 'subscriber_uid' => $this->subscriber->uid,
    ]);

    expect($headers['List-Unsubscribe'])->toBe('<'.$expectedUrl.'>, <mailto:news@acme.example?subject=unsubscribe>')
        ->and($headers['List-Unsubscribe-Post'])->toBe('List-Unsubscribe=One-Click');

    // Without a list from address the mailto target is omitted.
    $this->list->update(['from_email' => null]);
    $headers = app(ListUnsubscribeHeaders::class)->headersFor($this->subscriber->refresh());

    expect($headers['List-Unsubscribe'])->toBe('<'.$expectedUrl.'>');
});
