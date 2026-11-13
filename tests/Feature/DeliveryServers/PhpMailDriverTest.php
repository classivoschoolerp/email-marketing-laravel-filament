<?php

declare(strict_types=1);

use App\Models\DeliveryServer;
use App\Sending\Drivers\PhpMailDriver;
use App\Sending\Message;
use App\Sending\MimeBuilder;
use App\Sending\Support\PhpMailer;

/*
 * F-2.9.2-014 — PHP mail() driver, tested through the PhpMailer seam with a
 * recording spy (mail() itself never runs in tests, D-16).
 */

final class SpyPhpMailer implements PhpMailer
{
    /** @var list<array{to: string, subject: string, body: string, headers: string, params: string}> */
    public array $calls = [];

    public function __construct(public bool $returns = true) {}

    public function mail(string $to, string $subject, string $body, string $headers, string $params = ''): bool
    {
        $this->calls[] = compact('to', 'subject', 'body', 'headers', 'params');

        return $this->returns;
    }
}

function phpMailServer(array $attributes = []): DeliveryServer
{
    return DeliveryServer::factory()->makeOne(array_merge([
        'customer_id' => null,
        'type' => 'php-mail',
        'from_email' => 'sys@example.org',
        'from_name' => 'System',
        'custom_headers' => ['X-App' => 'emp'],
        'config' => ['params' => '-fbounce@example.org'],
    ], $attributes));
}

it('hands the message to mail() with To and Subject split out', function (): void {
    $spy = new SpyPhpMailer;
    $driver = new PhpMailDriver(new MimeBuilder, $spy);

    $result = $driver->send(new Message(
        fromEmail: 'sys@example.org',
        fromName: 'System',
        to: ['jane@example.net' => 'Jane'],
        subject: 'Monthly report',
        html: '<p>Hi</p>',
        text: 'Hi',
        headers: ['X-Campaign' => 'c-9'],
    ), phpMailServer());

    expect($result->ok)->toBeTrue()
        ->and($spy->calls)->toHaveCount(1);

    $call = $spy->calls[0];

    expect($call['to'])->toBe('jane@example.net')
        ->and($call['subject'])->toBe('Monthly report')
        ->and($call['params'])->toBe('-fbounce@example.org')
        // mail() writes To/Subject itself — they must NOT repeat in headers
        // (Reply-To may legitimately appear, so match at line starts only)
        ->and($call['headers'])->not->toMatch('/^To:/mi')
        ->and($call['headers'])->not->toMatch('/^Subject:/mi')
        // everything else travels in the additional-headers block
        ->and($call['headers'])->toContain('From: System <sys@example.org>')
        ->and($call['headers'])->toContain('X-Campaign: c-9')
        ->and($call['headers'])->toContain('X-App: emp')
        ->and($call['headers'])->toContain('MIME-Version: 1.0')
        ->and($call['body'])->not->toBe('');
});

it('reports a false mail() return as a transient failure', function (): void {
    $driver = new PhpMailDriver(new MimeBuilder, new SpyPhpMailer(returns: false));

    $result = $driver->send(new Message(fromEmail: 'a@b.c', to: ['x@y.z'], subject: 's', text: 'b'), phpMailServer());

    expect($result->ok)->toBeFalse()
        ->and($result->transient)->toBeTrue();
});

it('resolves the container-bound PhpMailer seam', function (): void {
    $spy = new SpyPhpMailer;
    app()->instance(PhpMailer::class, $spy);

    $driver = app(PhpMailDriver::class);
    $driver->send(new Message(fromEmail: 'a@b.c', to: ['x@y.z'], subject: 's', text: 'b'), phpMailServer());

    expect($spy->calls)->toHaveCount(1);
});
