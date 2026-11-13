<?php

declare(strict_types=1);

use App\Models\DeliveryServer;
use App\Sending\DriverRegistry;
use App\Sending\Message;
use Illuminate\Support\Facades\File;

/*
 * F-2.9.2-015 — pickup-directory driver: full round trip writing a real
 * .eml file and reading the RFC 2822 message back, including utf8mb4
 * subject/body (CJK + emoji) per spec §0.1.
 */

beforeEach(function (): void {
    $this->dir = storage_path('framework/testing/pickup-'.uniqid());
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

function pickupServer(string $dir, array $attributes = []): DeliveryServer
{
    return DeliveryServer::factory()->makeOne(array_merge([
        'customer_id' => null,
        'type' => 'pickup-directory',
        'from_email' => 'spool@example.org',
        'from_name' => 'Spooler',
        'config' => ['pickup_path' => $dir],
    ], $attributes));
}

it('spools a full RFC 2822 message with CJK and emoji intact', function (): void {
    $driver = app(DriverRegistry::class)->driver('pickup-directory');

    $subject = '新製品のお知らせ 🎉 — 割引は今日まで';
    $body = "こんにちは田中様、\n\n新しいキャンペーン 🚀 が始まりました。";

    // Text-only message keeps the file single-part, so the transfer encoding
    // can be decoded deterministically below.
    $result = $driver->send(new Message(
        fromEmail: 'spool@example.org',
        fromName: '株式会社Acme',
        to: ['tanaka@example.jp' => '田中太郎'],
        subject: $subject,
        text: $body,
    ), pickupServer($this->dir));

    expect($result->ok)->toBeTrue()->and($result->messageId)->not->toBeNull();

    $files = glob($this->dir.DIRECTORY_SEPARATOR.'*.eml');
    expect($files)->toHaveCount(1)
        ->and(glob($this->dir.DIRECTORY_SEPARATOR.'*.tmp'))->toBe([]); // atomic rename

    $raw = (string) file_get_contents($files[0]);

    // RFC 2822 structure: headers, blank line, body; required headers present.
    [$headerBlock] = explode("\r\n\r\n", $raw, 2);
    expect($headerBlock)->toContain('From:')
        ->and($headerBlock)->toContain('To:')
        ->and($headerBlock)->toContain('Subject:')
        ->and($headerBlock)->toContain('MIME-Version: 1.0')
        ->and($raw)->toContain('Message-ID:');

    // Subject survives RFC 2047 encoding round trip (CJK + emoji).
    preg_match('/^Subject:\s*(.+?)(?=\r\n[^ \t])/ms', $raw, $m);
    $decodedSubject = iconv_mime_decode(str_replace("\r\n", ' ', $m[1] ?? ''), ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
    expect($decodedSubject)->toBe($subject);

    // Body survives its transfer encoding round trip.
    [, $bodyBlock] = explode("\r\n\r\n", $raw, 2);
    $decodedBody = str_contains($headerBlock, 'base64')
        ? (string) base64_decode($bodyBlock, false)
        : quoted_printable_decode($bodyBlock);
    expect(str_replace("\r\n", "\n", $decodedBody))->toContain('新しいキャンペーン 🚀');
});

it('applies server policy (force-from, custom headers, DKIM off) to the spooled file', function (): void {
    $driver = app(DriverRegistry::class)->driver('pickup-directory');

    $server = pickupServer($this->dir, [
        'force_from' => true,
        'custom_headers' => ['X-Spool-Pool' => 'p1'],
    ]);

    $driver->send(new Message(fromEmail: 'other@example.net', to: ['x@example.io'], subject: 's', text: 'b'), $server);

    $raw = (string) file_get_contents(glob($this->dir.DIRECTORY_SEPARATOR.'*.eml')[0]);

    expect($raw)->toContain('spool@example.org')   // forced sender
        ->and($raw)->not->toContain('other@example.net')
        ->and($raw)->toContain('X-Spool-Pool: p1');
});

it('fails permanently without a configured pickup path', function (): void {
    $driver = app(DriverRegistry::class)->driver('pickup-directory');

    $result = $driver->send(new Message(fromEmail: 'a@b.c', to: ['x@y.z'], subject: 's', text: 'b'), pickupServer('', ['config' => []]));

    expect($result->ok)->toBeFalse()
        ->and($result->transient)->toBeFalse()
        ->and($driver->validateConfig(pickupServer('', ['config' => []])))->toHaveKey('config.pickup_path');
});

it('fails transiently when the directory cannot be created', function (): void {
    // A regular FILE at the target path makes mkdir() fail.
    File::ensureDirectoryExists(dirname($this->dir));
    file_put_contents($this->dir, 'not a directory');

    $driver = app(DriverRegistry::class)->driver('pickup-directory');
    $result = $driver->send(new Message(fromEmail: 'a@b.c', to: ['x@y.z'], subject: 's', text: 'b'), pickupServer($this->dir));

    expect($result->ok)->toBeFalse()->and($result->transient)->toBeTrue();

    unlink($this->dir);
});
