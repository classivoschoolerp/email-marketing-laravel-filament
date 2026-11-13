<?php

declare(strict_types=1);

use App\Models\DeliveryServer;
use App\Sending\DriverRegistry;
use App\Sending\Message;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * HTTP-API drivers (F-2.9.2-003..011, 018): every driver is exercised
 * against its real documented endpoint with Http::fake — URL, auth header
 * and payload shape asserted, plus SendResult mapping for success /
 * transient / permanent outcomes (docs/DECISIONS.md D-16: no live sends).
 */

function apiMessage(): Message
{
    return new Message(
        fromEmail: 'news@example.org',
        fromName: 'Newsroom',
        to: ['jane@example.net' => 'Jane'],
        subject: 'Hello',
        html: '<p>Hi</p>',
        text: 'Hi',
        headers: ['X-Campaign' => 'c-1'],
    );
}

function apiServer(string $type, array $attributes = []): DeliveryServer
{
    return DeliveryServer::factory()->makeOne(array_merge([
        'customer_id' => null,
        'type' => $type,
        'api_key' => 'test-api-key',
        'from_email' => 'news@example.org',
        'from_name' => 'Newsroom',
    ], $attributes));
}

function apiDriver(string $type): \App\Sending\SendingDriver
{
    return app(DriverRegistry::class)->driver($type);
}

// ---- Amazon SES v2 (F-2.9.2-003)

it('sends through the SES v2 API with a SigV4-signed request', function (): void {
    Http::fake(['email.eu-west-1.amazonaws.com/*' => Http::response(['MessageId' => 'ses-123'])]);

    $server = apiServer('amazon-ses-api', [
        'username' => 'AKIDEXAMPLE',
        'password' => 'secret',
        'region' => 'eu-west-1',
        'api_key' => null,
    ]);

    $result = apiDriver('amazon-ses-api')->send(apiMessage(), $server);

    expect($result->ok)->toBeTrue()->and($result->messageId)->toBe('ses-123');

    Http::assertSent(function (Request $request): bool {
        $auth = $request->header('Authorization')[0] ?? '';

        return $request->url() === 'https://email.eu-west-1.amazonaws.com/v2/email/outbound-emails'
            && str_starts_with($auth, 'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/')
            && str_contains($auth, '/eu-west-1/ses/aws4_request')
            && str_contains($auth, 'SignedHeaders=content-type;host;x-amz-date')
            && ($request->header('X-Amz-Date')[0] ?? '') !== ''
            && $request['FromEmailAddress'] === 'Newsroom <news@example.org>'
            && $request['Destination']['ToAddresses'] === ['jane@example.net']
            // custom headers force Raw MIME content (honest header support)
            && isset($request['Content']['Raw']['Data']);
    });
});

it('uses SES Simple content when no attachments or headers are present', function (): void {
    Http::fake(['email.us-east-1.amazonaws.com/*' => Http::response(['MessageId' => 'ses-9'])]);

    $server = apiServer('amazon-ses-api', ['username' => 'AK', 'password' => 'SK', 'region' => 'us-east-1', 'api_key' => null]);
    $message = new Message(fromEmail: 'a@b.io', to: ['c@d.io'], subject: 'Sub', html: '<b>x</b>', text: 'x');

    apiDriver('amazon-ses-api')->send($message, $server);

    Http::assertSent(fn (Request $r): bool => $r['Content']['Simple']['Subject']['Data'] === 'Sub'
        && $r['Content']['Simple']['Body']['Html']['Data'] === '<b>x</b>');
});

it('classifies SES throttling as transient and rejection as permanent', function (): void {
    $server = apiServer('amazon-ses-api', ['username' => 'AK', 'password' => 'SK', 'region' => 'us-east-1', 'api_key' => null]);
    $driver = apiDriver('amazon-ses-api');

    Http::fake(['email.us-east-1.amazonaws.com/*' => Http::sequence()
        ->push(['__type' => 'TooManyRequestsException'], 429)
        ->push(['__type' => 'MessageRejected', 'message' => 'Email address is not verified.'], 400)]);

    expect($driver->send(apiMessage(), $server)->transient)->toBeTrue();

    $rejected = $driver->send(apiMessage(), $server);
    expect($rejected->ok)->toBeFalse()->and($rejected->transient)->toBeFalse();
});

// ---- SendGrid (F-2.9.2-004)

it('sends through the SendGrid v3 API', function (): void {
    Http::fake(['api.sendgrid.com/*' => Http::response('', 202, ['X-Message-Id' => 'sg-1'])]);

    $result = apiDriver('sendgrid-api')->send(apiMessage(), apiServer('sendgrid-api'));

    expect($result->ok)->toBeTrue()->and($result->messageId)->toBe('sg-1');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://api.sendgrid.com/v3/mail/send'
        && ($r->header('Authorization')[0] ?? '') === 'Bearer test-api-key'
        && $r['personalizations'][0]['to'][0]['email'] === 'jane@example.net'
        && $r['from']['email'] === 'news@example.org'
        && $r['subject'] === 'Hello'
        && $r['headers']['X-Campaign'] === 'c-1'
        && collect($r['content'])->pluck('type')->all() === ['text/plain', 'text/html']);
});

it('maps SendGrid 429 to transient and 401 to permanent', function (): void {
    $driver = apiDriver('sendgrid-api');
    $server = apiServer('sendgrid-api');

    Http::fake(['api.sendgrid.com/*' => Http::sequence()
        ->push(['errors' => []], 429)
        ->push(['errors' => []], 401)]);

    expect($driver->send(apiMessage(), $server)->transient)->toBeTrue();

    $failed = $driver->send(apiMessage(), $server);
    expect($failed->ok)->toBeFalse()->and($failed->transient)->toBeFalse();
});

// ---- Mailgun (F-2.9.2-005)

it('sends through the Mailgun messages API with basic auth', function (): void {
    Http::fake(['api.mailgun.net/*' => Http::response(['id' => '<mg-1@example.org>', 'message' => 'Queued.'])]);

    $server = apiServer('mailgun-api', ['config' => ['domain' => 'mg.example.org']]);
    $result = apiDriver('mailgun-api')->send(apiMessage(), $server);

    expect($result->ok)->toBeTrue()->and($result->messageId)->toBe('<mg-1@example.org>');

    Http::assertSent(function (Request $r): bool {
        return $r->url() === 'https://api.mailgun.net/v3/mg.example.org/messages'
            && str_starts_with($r->header('Authorization')[0] ?? '', 'Basic '.base64_encode('api:test-api-key'))
            && str_contains($r->body(), 'name="from"')
            && str_contains($r->body(), 'Newsroom <news@example.org>')
            && str_contains($r->body(), 'name="h:X-Campaign"');
    });
});

it('routes Mailgun EU-region requests to the EU endpoint', function (): void {
    Http::fake(['api.eu.mailgun.net/*' => Http::response(['id' => 'x'])]);

    $server = apiServer('mailgun-api', ['region' => 'eu', 'config' => ['domain' => 'mg.example.org']]);
    apiDriver('mailgun-api')->send(apiMessage(), $server);

    Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), 'https://api.eu.mailgun.net/v3/mg.example.org/'));
});

it('maps Mailgun 500 to transient and 400 to permanent', function (): void {
    $driver = apiDriver('mailgun-api');
    $server = apiServer('mailgun-api', ['config' => ['domain' => 'mg.example.org']]);

    Http::fake(['api.mailgun.net/*' => Http::sequence()
        ->push('server error', 500)
        ->push(['message' => 'bad from'], 400)]);

    expect($driver->send(apiMessage(), $server)->transient)->toBeTrue()
        ->and($driver->send(apiMessage(), $server)->transient)->toBeFalse();
});

// ---- SparkPost (F-2.9.2-006)

it('sends through the SparkPost transmissions API', function (): void {
    Http::fake(['api.sparkpost.com/*' => Http::response(['results' => ['id' => 'sp-1', 'total_accepted_recipients' => 1]])]);

    $result = apiDriver('sparkpost-api')->send(apiMessage(), apiServer('sparkpost-api'));

    expect($result->ok)->toBeTrue()->and($result->messageId)->toBe('sp-1');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://api.sparkpost.com/api/v1/transmissions'
        && ($r->header('Authorization')[0] ?? '') === 'test-api-key'
        && $r['recipients'][0]['address']['email'] === 'jane@example.net'
        && $r['content']['from']['email'] === 'news@example.org'
        && $r['content']['headers']['X-Campaign'] === 'c-1');
});

it('treats SparkPost 420 throttling as transient', function (): void {
    Http::fake(['api.sparkpost.com/*' => Http::response(['errors' => [['message' => 'Exceeded']]], 420)]);

    expect(apiDriver('sparkpost-api')->send(apiMessage(), apiServer('sparkpost-api'))->transient)->toBeTrue();
});

// ---- Postmark (F-2.9.2-007)

it('sends through the Postmark email API', function (): void {
    Http::fake(['api.postmarkapp.com/*' => Http::response(['MessageID' => 'pm-1', 'ErrorCode' => 0])]);

    $result = apiDriver('postmark-api')->send(apiMessage(), apiServer('postmark-api'));

    expect($result->ok)->toBeTrue()->and($result->messageId)->toBe('pm-1');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://api.postmarkapp.com/email'
        && ($r->header('X-Postmark-Server-Token')[0] ?? '') === 'test-api-key'
        && $r['From'] === 'Newsroom <news@example.org>'
        && $r['To'] === 'Jane <jane@example.net>'
        && $r['Headers'][0] === ['Name' => 'X-Campaign', 'Value' => 'c-1']);
});

it('maps Postmark 422 inactive-recipient to permanent', function (): void {
    Http::fake(['api.postmarkapp.com/*' => Http::response(['ErrorCode' => 406, 'Message' => 'Inactive recipient'], 422)]);

    $result = apiDriver('postmark-api')->send(apiMessage(), apiServer('postmark-api'));

    expect($result->ok)->toBeFalse()->and($result->transient)->toBeFalse();
});

// ---- Elastic Email (F-2.9.2-008)

it('sends through the Elastic Email v4 API', function (): void {
    Http::fake(['api.elasticemail.com/*' => Http::response(['TransactionID' => 'tx-1', 'MessageID' => 'ee-1'])]);

    $result = apiDriver('elastic-email-api')->send(apiMessage(), apiServer('elastic-email-api'));

    expect($result->ok)->toBeTrue()->and($result->messageId)->toBe('ee-1');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://api.elasticemail.com/v4/emails'
        && ($r->header('X-ElasticEmail-ApiKey')[0] ?? '') === 'test-api-key'
        && $r['Recipients'][0]['Email'] === 'jane@example.net'
        && collect($r['Content']['Body'])->pluck('ContentType')->all() === ['HTML', 'PlainText']);
});

// ---- Mailjet (F-2.9.2-009)

it('sends through the Mailjet v3.1 API with key+secret basic auth', function (): void {
    Http::fake(['api.mailjet.com/*' => Http::response([
        'Messages' => [['Status' => 'success', 'To' => [['Email' => 'jane@example.net', 'MessageID' => 999]]]],
    ])]);

    $server = apiServer('mailjet-api', ['username' => 'mj-key', 'api_key' => 'mj-secret']);
    $result = apiDriver('mailjet-api')->send(apiMessage(), $server);

    expect($result->ok)->toBeTrue()->and($result->messageId)->toBe('999');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://api.mailjet.com/v3.1/send'
        && ($r->header('Authorization')[0] ?? '') === 'Basic '.base64_encode('mj-key:mj-secret')
        && $r['Messages'][0]['From']['Email'] === 'news@example.org'
        && $r['Messages'][0]['To'][0]['Email'] === 'jane@example.net');
});

it('maps a Mailjet per-message error to permanent', function (): void {
    Http::fake(['api.mailjet.com/*' => Http::response([
        'Messages' => [['Status' => 'error', 'Errors' => [['ErrorMessage' => 'bad recipient']]]],
    ])]);

    $server = apiServer('mailjet-api', ['username' => 'k', 'api_key' => 's']);
    $result = apiDriver('mailjet-api')->send(apiMessage(), $server);

    expect($result->ok)->toBeFalse()->and($result->transient)->toBeFalse();
});

// ---- Brevo (F-2.9.2-010)

it('sends through the Brevo transactional API', function (): void {
    Http::fake(['api.brevo.com/*' => Http::response(['messageId' => '<brevo-1@example>'], 201)]);

    $result = apiDriver('brevo-api')->send(apiMessage(), apiServer('brevo-api'));

    expect($result->ok)->toBeTrue()->and($result->messageId)->toBe('<brevo-1@example>');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://api.brevo.com/v3/smtp/email'
        && ($r->header('api-key')[0] ?? '') === 'test-api-key'
        && $r['sender']['email'] === 'news@example.org'
        && $r['to'][0]['email'] === 'jane@example.net'
        && $r['headers']['X-Campaign'] === 'c-1');
});

// ---- Postal (F-2.9.2-011)

it('sends through the Postal send API', function (): void {
    Http::fake(['postal.example.com/*' => Http::response([
        'status' => 'success',
        'data' => ['message_id' => 'postal-1@rp'],
    ])]);

    $server = apiServer('postal', ['hostname' => 'postal.example.com']);
    $result = apiDriver('postal')->send(apiMessage(), $server);

    expect($result->ok)->toBeTrue()->and($result->messageId)->toBe('postal-1@rp');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://postal.example.com/api/v1/send/message'
        && ($r->header('X-Server-API-Key')[0] ?? '') === 'test-api-key'
        && $r['to'] === ['jane@example.net']
        && $r['from'] === 'Newsroom <news@example.org>');
});

it('classifies Postal error-status bodies honestly despite HTTP 200', function (): void {
    $server = apiServer('postal', ['hostname' => 'postal.example.com']);
    $driver = apiDriver('postal');

    Http::fake(['postal.example.com/*' => Http::sequence()
        ->push(['status' => 'error', 'data' => ['code' => 'SendLimitExceeded']])
        ->push(['status' => 'error', 'data' => ['code' => 'NoRecipients']])]);

    expect($driver->send(apiMessage(), $server)->transient)->toBeTrue();

    $failed = $driver->send(apiMessage(), $server);
    expect($failed->ok)->toBeFalse()->and($failed->transient)->toBeFalse();
});

// ---- Blastengine (F-2.9.2-018)

it('sends through the Blastengine transaction API with the derived bearer token', function (): void {
    Http::fake(['app.engn.jp/*' => Http::response(['delivery_id' => 4711], 201)]);

    $server = apiServer('blastengine', ['username' => 'acme-user', 'api_key' => 'be-key']);
    $result = apiDriver('blastengine')->send(apiMessage(), $server);

    $expectedToken = base64_encode(strtolower(hash('sha256', 'acme-user'.'be-key')));

    expect($result->ok)->toBeTrue()->and($result->messageId)->toBe('4711');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://app.engn.jp/api/v1/deliveries/transaction'
        && ($r->header('Authorization')[0] ?? '') === 'Bearer '.$expectedToken
        && $r['to'] === 'jane@example.net'
        && $r['from']['email'] === 'news@example.org'
        && $r['encode'] === 'UTF-8'
        && $r['subject'] === 'Hello');
});

// ---- cross-driver invariants

it('fails permanently with honest errors when config is missing', function (): void {
    Http::fake();

    $missing = [
        'sendgrid-api' => apiServer('sendgrid-api', ['api_key' => null]),
        'mailgun-api' => apiServer('mailgun-api', ['config' => []]),
        'postal' => apiServer('postal', ['hostname' => null]),
        'amazon-ses-api' => apiServer('amazon-ses-api', ['username' => null, 'region' => 'us-east-1']),
        'blastengine' => apiServer('blastengine', ['username' => null]),
    ];

    foreach ($missing as $type => $server) {
        $result = apiDriver($type)->send(apiMessage(), $server);

        expect($result->ok)->toBeFalse()
            ->and($result->transient)->toBeFalse()
            ->and($result->error)->not->toBeNull();
    }

    Http::assertNothingSent();
});

it('classifies connection failures as transient for every API driver', function (): void {
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

    $servers = [
        'sendgrid-api' => apiServer('sendgrid-api'),
        'sparkpost-api' => apiServer('sparkpost-api'),
        'postmark-api' => apiServer('postmark-api'),
        'elastic-email-api' => apiServer('elastic-email-api'),
        'brevo-api' => apiServer('brevo-api'),
        'mailjet-api' => apiServer('mailjet-api', ['username' => 'k']),
        'mailgun-api' => apiServer('mailgun-api', ['config' => ['domain' => 'd.io']]),
        'postal' => apiServer('postal', ['hostname' => 'postal.example.com']),
        'blastengine' => apiServer('blastengine', ['username' => 'u']),
        'amazon-ses-api' => apiServer('amazon-ses-api', ['username' => 'AK', 'password' => 'SK', 'region' => 'us-east-1']),
    ];

    foreach ($servers as $type => $server) {
        $result = apiDriver($type)->send(apiMessage(), $server);

        expect($result->ok)->toBeFalse()
            ->and($result->transient)->toBeTrue();
    }
});
