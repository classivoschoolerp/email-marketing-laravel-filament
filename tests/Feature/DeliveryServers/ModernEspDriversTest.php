<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\DeliveryServer;
use App\Sending\DriverRegistry;
use App\Sending\Message;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Support\Facades\Http;

/**
 * F-2.9.2-013 — Resend / Loops / Mailtrap. Contract-tested against each
 * vendor's documented request shape (D-12/D-13 precedent: no live calls).
 */
beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    app(CurrentCustomer::class)->set($this->customer);
});

function espServer(string $type, array $attributes = []): DeliveryServer
{
    return DeliveryServer::factory()->create(array_merge([
        'customer_id' => test()->customer->id,
        'type' => $type,
        'api_key' => 'secret-key',
        'from_email' => 'ops@example.test',
        'from_name' => 'Ops',
    ], $attributes));
}

function espMessage(): Message
{
    return new Message(
        fromEmail: 'ops@example.test',
        fromName: 'Ops',
        to: ['rcpt@example.test' => 'Rcpt'],
        subject: 'Hello 世界 🎉',
        html: '<p>Hi</p>',
        text: 'Hi',
    );
}

it('registers all three modern ESP drivers under their D-21 keys', function (): void {
    $registry = app(DriverRegistry::class);

    expect($registry->has('resend-api'))->toBeTrue()
        ->and($registry->has('loops-api'))->toBeTrue()
        ->and($registry->has('mailtrap-api'))->toBeTrue();
});

it('sends through the Resend API with a bearer key', function (): void {
    Http::fake(['api.resend.com/*' => Http::response(['id' => 'res-1'], 200)]);

    $result = app(DriverRegistry::class)->driver('resend-api')
        ->send(espMessage(), espServer('resend-api'));

    expect($result->ok)->toBeTrue()
        ->and($result->messageId)->toBe('res-1');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.resend.com/emails'
            && $request->hasHeader('Authorization', 'Bearer secret-key')
            && $request['subject'] === 'Hello 世界 🎉'
            && $request['from'] === 'Ops <ops@example.test>'
            && $request['to'] === ['Rcpt <rcpt@example.test>'];
    });
});

it('classifies Resend rate limiting as transient and auth failure as permanent', function (): void {
    // One fake with a sequence: a second Http::fake() call ADDS a stub, it does
    // not replace the first, so the 401 would never be reached.
    Http::fake([
        'api.resend.com/*' => Http::sequence()
            ->push([], 429)
            ->push([], 401),
    ]);

    $transient = app(DriverRegistry::class)->driver('resend-api')
        ->send(espMessage(), espServer('resend-api'));

    $permanent = app(DriverRegistry::class)->driver('resend-api')
        ->send(espMessage(), espServer('resend-api'));

    expect($transient->ok)->toBeFalse()
        ->and($transient->transient)->toBeTrue()
        ->and($permanent->ok)->toBeFalse()
        ->and($permanent->transient)->toBeFalse();
});

it('sends a Loops transactional template with the rendered copy as data variables', function (): void {
    Http::fake(['app.loops.so/*' => Http::response(['success' => true, 'id' => 'loop-1'], 200)]);

    $server = espServer('loops-api', ['config' => ['transactional_id' => 'tpl_42']]);

    $result = app(DriverRegistry::class)->driver('loops-api')->send(espMessage(), $server);

    expect($result->ok)->toBeTrue();

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://app.loops.so/api/v1/transactional'
            && $request['transactionalId'] === 'tpl_42'
            && $request['email'] === 'rcpt@example.test'
            && $request['dataVariables']['subject'] === 'Hello 世界 🎉';
    });
});

it('refuses a Loops server with no transactional template id', function (): void {
    Http::fake();

    $result = app(DriverRegistry::class)->driver('loops-api')
        ->send(espMessage(), espServer('loops-api'));

    expect($result->ok)->toBeFalse()
        ->and($result->transient)->toBeFalse();

    Http::assertNothingSent();
});

it('treats a Loops 200 with success:false as a permanent failure', function (): void {
    Http::fake(['app.loops.so/*' => Http::response(['success' => false, 'message' => 'bad template'], 200)]);

    $result = app(DriverRegistry::class)->driver('loops-api')
        ->send(espMessage(), espServer('loops-api', ['config' => ['transactional_id' => 'tpl_42']]));

    expect($result->ok)->toBeFalse()
        ->and($result->transient)->toBeFalse()
        ->and($result->error)->toContain('bad template');
});

it('sends through the Mailtrap live endpoint by default', function (): void {
    Http::fake(['send.api.mailtrap.io/*' => Http::response(['success' => true, 'message_ids' => ['mt-1']], 200)]);

    $result = app(DriverRegistry::class)->driver('mailtrap-api')
        ->send(espMessage(), espServer('mailtrap-api'));

    expect($result->ok)->toBeTrue()
        ->and($result->messageId)->toBe('mt-1');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://send.api.mailtrap.io/api/send'
        && $request->hasHeader('Api-Token', 'secret-key'));
});

it('switches Mailtrap to the sandbox endpoint when an inbox id is configured', function (): void {
    Http::fake(['sandbox.api.mailtrap.io/*' => Http::response(['success' => true, 'message_ids' => ['mt-2']], 200)]);

    $server = espServer('mailtrap-api', ['config' => ['sandbox_inbox_id' => '9999']]);

    $result = app(DriverRegistry::class)->driver('mailtrap-api')->send(espMessage(), $server);

    expect($result->ok)->toBeTrue();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://sandbox.api.mailtrap.io/api/send/9999');
});
