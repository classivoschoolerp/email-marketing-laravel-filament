<?php

declare(strict_types=1);

use App\Enums\MailboxEncryption;
use App\Enums\MailboxProtocol;
use App\Services\Mailboxes\MailboxConnection;
use App\Services\Mailboxes\Pop3Client;
use Tests\Fakes\FakeSocket;

function pop3_connection(MailboxEncryption $encryption = MailboxEncryption::None): MailboxConnection
{
    return new MailboxConnection(
        hostname: 'pop.example.net',
        port: 110,
        protocol: MailboxProtocol::Pop3,
        username: 'mailer',
        password: 'secret-pass',
        encryption: $encryption,
        validateCert: true,
    );
}

// §2.9.4 — full POP3 session against a scripted RFC 1939 transcript
it('speaks the pop3 protocol: auth, stat, retr with dot-unstuffing, dele, quit', function (): void {
    $socket = new FakeSocket([
        '+OK POP3 server ready',
        '+OK user accepted',
        '+OK pass accepted',
        '+OK 2 640',
        '+OK message follows',
        'Subject: hello',
        '',
        'Line one.',
        '..starts-with-dot', // byte-stuffed (RFC 1939 §3)
        '.',
        '+OK marked deleted',
        '+OK bye',
    ]);

    $client = new Pop3Client($socket);
    $client->connect(pop3_connection());

    expect($client->listMessages(10))->toBe([1, 2]);

    $raw = $client->fetch(1);
    expect($raw)->toBe("Subject: hello\r\n\r\nLine one.\r\n.starts-with-dot");

    $client->delete(1);
    $client->disconnect();

    expect($socket->written)->toBe(['USER mailer', 'PASS secret-pass', 'STAT', 'RETR 1', 'DELE 1', 'QUIT'])
        ->and($socket->opened)->toBe([
            'hostname' => 'pop.example.net',
            'port' => 110,
            'tls' => false,
            'validateCert' => true,
        ])
        ->and($socket->closed)->toBeTrue();
});

it('caps listMessages at the batch limit', function (): void {
    $socket = new FakeSocket([
        '+OK ready', '+OK', '+OK',
        '+OK 250 1024000',
    ]);

    $client = new Pop3Client($socket);
    $client->connect(pop3_connection());

    expect($client->listMessages(5))->toBe([1, 2, 3, 4, 5]);
});

it('uses implicit tls for ssl encryption and stls for starttls', function (): void {
    // ssl → tls flag on open, no STLS command
    $ssl = new FakeSocket(['+OK ready', '+OK', '+OK']);
    (new Pop3Client($ssl))->connect(pop3_connection(MailboxEncryption::Ssl));

    expect($ssl->opened['tls'])->toBeTrue()
        ->and($ssl->written)->toBe(['USER mailer', 'PASS secret-pass'])
        ->and($ssl->cryptoEnabled)->toBeFalse();

    // tls → plaintext connect, STLS, crypto upgrade
    $stls = new FakeSocket(['+OK ready', '+OK begin TLS', '+OK', '+OK']);
    (new Pop3Client($stls))->connect(pop3_connection(MailboxEncryption::Tls));

    expect($stls->opened['tls'])->toBeFalse()
        ->and($stls->written)->toBe(['STLS', 'USER mailer', 'PASS secret-pass'])
        ->and($stls->cryptoEnabled)->toBeTrue();
});

it('throws an honest error when authentication fails', function (): void {
    $socket = new FakeSocket([
        '+OK ready',
        '+OK',
        '-ERR [AUTH] invalid credentials',
    ]);

    (new Pop3Client($socket))->connect(pop3_connection());
})->throws(RuntimeException::class, 'POP3 PASS failed');

it('throws when the greeting is not ok', function (): void {
    $socket = new FakeSocket(['-ERR service unavailable']);

    (new Pop3Client($socket))->connect(pop3_connection());
})->throws(RuntimeException::class, 'POP3 greeting failed');

// ImapClient: honest failure without ext-imap (documented limitation)
it('imap client throws a clear error when ext-imap is missing', function (): void {
    if (extension_loaded('imap')) {
        $this->markTestSkipped('ext-imap is installed on this machine.');
    }

    (new App\Services\Mailboxes\ImapClient)->connect(pop3_connection());
})->throws(RuntimeException::class, 'ext-imap');
