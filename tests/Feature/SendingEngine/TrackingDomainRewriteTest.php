<?php

declare(strict_types=1);

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Models\TrackingDomain;
use App\Sending\Pipeline\CampaignUrlRewriter;
use App\Support\Tenancy\CurrentCustomer;

/**
 * F-1.7-005 — tracking links and the open pixel must be served through the
 * campaign's custom tracking domain when one is configured and verified.
 * Regression guard for the WP-13/WP-15 integration gap found in the §E audit:
 * the rewriter used bare route() and always emitted app.url.
 */
beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);
    $this->subscriber = Subscriber::factory()->create([
        'customer_id' => $this->customer->id,
        'list_id' => $this->list->id,
    ]);
});

function rewriteWith(?TrackingDomain $domain, Campaign $campaign): string
{
    $campaign->tracking_domain_id = $domain?->id;
    $campaign->save();

    return app(CampaignUrlRewriter::class)->process(
        $campaign,
        test()->subscriber,
        '<html><body><a href="https://example.com/offer">Buy</a></body></html>',
    );
}

it('serves click links and the open pixel on a verified custom tracking domain', function (): void {
    $campaign = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
    ]);

    $domain = TrackingDomain::factory()->create([
        'customer_id' => $this->customer->id,
        'name' => 'links.example.org',
        'status' => \App\Enums\DomainStatus::Verified,
        'verified' => true,
    ]);

    $html = rewriteWith($domain, $campaign);

    expect($html)->toContain('//links.example.org/campaigns/')
        ->and($html)->toContain('track-url')
        ->and($html)->toContain('track-opening')
        // no tracking URL may leak the app host when a domain is configured
        ->and($html)->not->toContain(parse_url((string) config('app.url'), PHP_URL_HOST).'/campaigns/');
});

it('falls back to the app url when no tracking domain is configured', function (): void {
    $campaign = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
    ]);

    $html = rewriteWith(null, $campaign);

    expect($html)->toContain((string) config('app.url'))
        ->and($html)->toContain('track-url');
});

it('ignores an unverified tracking domain and keeps the app url', function (): void {
    $campaign = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
    ]);

    $domain = TrackingDomain::factory()->create([
        'customer_id' => $this->customer->id,
        'name' => 'pending.example.org',
        'status' => \App\Enums\DomainStatus::Pending,
        'verified' => false,
    ]);

    $html = rewriteWith($domain, $campaign);

    expect($html)->not->toContain('pending.example.org')
        ->and($html)->toContain((string) config('app.url'));
});
