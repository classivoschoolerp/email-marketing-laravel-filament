<?php

declare(strict_types=1);

use App\Models\DeliveryServer;
use App\Sending\Drivers\EximDriver;
use App\Sending\Drivers\GmailOauthDriver;
use App\Sending\Drivers\PostfixDriver;
use App\Sending\Drivers\PowerMtaDriver;
use App\Sending\Drivers\QmailDriver;
use App\Sending\Drivers\SendmailDriver;
use App\Sending\Drivers\SmtpAmazonSesDriver;
use App\Sending\Drivers\SmtpDriver;
use App\Sending\MimeBuilder;
use App\Sending\Support\GoogleOAuth;
use Symfony\Component\Mailer\Transport\SendmailTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/*
 * SMTP-family drivers are tested by asserting the BUILT transport
 * configuration, never live connections (docs/DECISIONS.md D-16).
 */

function smtpServer(array $attributes = []): DeliveryServer
{
    return new DeliveryServer(array_merge([
        'type' => 'smtp',
        'name' => 'Test relay',
        'hostname' => 'smtp.example.com',
        'port' => 587,
        'protocol' => 'tls',
        'username' => 'mailer',
        'password' => 'secret',
        'keep_alive' => true,
        'max_connection_messages' => 0,
        'connection_timeout' => 30,
    ], $attributes));
}

function restartThreshold(SmtpTransport $transport): int
{
    $property = new ReflectionProperty(SmtpTransport::class, 'restartThreshold');

    return (int) $property->getValue($transport);
}

// F-2.9.2-001 — generic SMTP: host/port/credentials mapping
it('builds the generic smtp transport from server columns', function (): void {
    $driver = new SmtpDriver(new MimeBuilder);
    $transport = $driver->buildTransport(smtpServer());

    /** @var SocketStream $stream */
    $stream = $transport->getStream();

    expect($transport)->toBeInstanceOf(EsmtpTransport::class)
        ->and($stream->getHost())->toBe('smtp.example.com')
        ->and($stream->getPort())->toBe(587)
        ->and($transport->getUsername())->toBe('mailer')
        ->and($transport->getPassword())->toBe('secret')
        ->and($stream->getTimeout())->toBe(30.0)
        ->and($driver->dsn(smtpServer()))->toBe('smtp://mailer@smtp.example.com:587?starttls=yes');
});

// F-2.9.2-025 — protocol none/tcp/ssl/tls mapping
it('maps the protocol column to TLS behaviour', function (): void {
    $driver = new SmtpDriver(new MimeBuilder);

    $ssl = $driver->buildTransport(smtpServer(['protocol' => 'ssl', 'port' => 465]));
    $tls = $driver->buildTransport(smtpServer(['protocol' => 'tls']));
    $plain = $driver->buildTransport(smtpServer(['protocol' => 'none', 'port' => 25]));
    $tcp = $driver->buildTransport(smtpServer(['protocol' => 'tcp', 'port' => 25]));

    expect($ssl->getStream())->toBeInstanceOf(SocketStream::class)
        ->and($ssl->getStream()->isTLS())->toBeTrue()      // implicit TLS (smtps)
        ->and($tls->getStream()->isTLS())->toBeFalse()     // STARTTLS upgrades later
        ->and($plain->getStream()->isTLS())->toBeFalse()
        ->and($tcp->getStream()->isTLS())->toBeFalse()
        ->and($driver->dsn(smtpServer(['protocol' => 'ssl', 'port' => 465])))
        ->toStartWith('smtps://');
});

// F-2.9.2-031 — max connection messages / timeout / keep-alive
it('maps connection tuning to the transport', function (): void {
    $driver = new SmtpDriver(new MimeBuilder);

    $limited = $driver->buildTransport(smtpServer(['max_connection_messages' => 50]));
    $noKeepAlive = $driver->buildTransport(smtpServer(['keep_alive' => false, 'max_connection_messages' => 50]));
    $slow = $driver->buildTransport(smtpServer(['connection_timeout' => 5]));

    expect(restartThreshold($limited))->toBe(50)
        ->and(restartThreshold($noKeepAlive))->toBe(1) // new connection per message
        ->and($slow->getStream()->getTimeout())->toBe(5.0);
});

it('validates generic smtp config honestly', function (): void {
    $driver = new SmtpDriver(new MimeBuilder);

    expect($driver->validateConfig(smtpServer()))->toBe([])
        ->and($driver->validateConfig(smtpServer(['hostname' => null])))->toHaveKey('hostname')
        ->and($driver->validateConfig(smtpServer(['port' => null])))->toHaveKey('port')
        ->and($driver->validateConfig(smtpServer(['port' => 99999])))->toHaveKey('port');
});

// F-2.9.2-002 — SES SMTP endpoint derives from the region
it('derives the SES SMTP endpoint from the region', function (): void {
    $driver = new SmtpAmazonSesDriver(new MimeBuilder);

    $server = smtpServer(['type' => 'smtp-amazon-ses', 'region' => 'eu-west-1', 'hostname' => null]);

    expect($driver->buildTransport($server)->getStream()->getHost())
        ->toBe('email-smtp.eu-west-1.amazonaws.com')
        ->and($driver->dsn($server))->toContain('email-smtp.eu-west-1.amazonaws.com:587')
        ->and($driver->validateConfig($server))->toBe([])
        ->and($driver->validateConfig(smtpServer(['region' => null, 'hostname' => null])))->toHaveKey('region')
        ->and($driver->validateConfig(smtpServer(['region' => 'us-east-1', 'password' => null])))->toHaveKey('password');
});

// F-2.9.2-012 — PowerMTA stamps VMTA routing headers from config
it('stamps X-VirtualMTA and X-Job headers for PowerMTA', function (): void {
    $driver = new PowerMtaDriver(new MimeBuilder);

    $server = smtpServer([
        'type' => 'powermta',
        'config' => ['virtual_mta' => 'vmta-pool-1', 'job_id' => 'campaign-42'],
    ]);

    expect($driver->extraHeaders($server))->toBe([
        'X-VirtualMTA' => 'vmta-pool-1',
        'X-Job' => 'campaign-42',
    ])
        ->and($driver->extraHeaders(smtpServer(['config' => null])))->toBe([]);
});

// F-2.9.2-016/019 — local MTA family: per-driver binary paths + pipe modes
it('builds each local MTA command with its own default binary path', function (): void {
    $mime = new MimeBuilder;
    $server = fn (array $config = []) => smtpServer(['config' => $config]);

    expect((new SendmailDriver($mime))->command($server()))->toBe('/usr/sbin/sendmail -bs')
        ->and((new PostfixDriver($mime))->command($server()))->toBe('/usr/sbin/sendmail -bs')
        ->and((new EximDriver($mime))->command($server()))->toBe('/usr/sbin/exim -bs')
        // qmail-inject has no SMTP stdio mode — pipe by default
        ->and((new QmailDriver($mime))->command($server()))->toBe('/var/qmail/bin/qmail-inject -t');
});

it('honours per-server binary path and mode overrides', function (): void {
    $driver = new SendmailDriver(new MimeBuilder);

    $server = smtpServer(['config' => ['binary_path' => '/opt/msmtp/sendmail', 'mode' => '-t']]);

    expect($driver->command($server))->toBe('/opt/msmtp/sendmail -t')
        ->and($driver->buildTransport($server))->toBeInstanceOf(SendmailTransport::class)
        ->and($driver->validateConfig($server))->toBe([]);
});

// F-2.9.2-017 — Gmail OAuth transport: fixed host/port, mailbox as username
it('pins the gmail transport to smtp.gmail.com:587 with the mailbox user', function (): void {
    $driver = new GmailOauthDriver(new MimeBuilder, new class extends GoogleOAuth
    {
        public function accessToken(DeliveryServer $server): string
        {
            return 'fake-access-token';
        }
    });

    $server = smtpServer([
        'type' => 'gmail-oauth',
        'username' => 'sender@workspace.example',
        'api_key' => 'client-secret',
        'password' => 'refresh-token',
        'config' => ['client_id' => 'client-id.apps.googleusercontent.com'],
    ]);

    $transport = $driver->buildTransport($server);

    expect($transport->getStream()->getHost())->toBe('smtp.gmail.com')
        ->and($transport->getStream()->getPort())->toBe(587)
        ->and($transport->getUsername())->toBe('sender@workspace.example')
        ->and($transport->getPassword())->toBe('fake-access-token') // XOAUTH2 token as password
        ->and($driver->validateConfig($server))->toBe([]);
});

it('validates the full gmail oauth config chain', function (): void {
    $driver = new GmailOauthDriver(new MimeBuilder, new GoogleOAuth);

    $errors = $driver->validateConfig(smtpServer([
        'type' => 'gmail-oauth',
        'username' => null,
        'password' => null,
        'api_key' => null,
        'config' => [],
    ]));

    expect($errors)->toHaveKeys(['username', 'config.client_id', 'api_key', 'password']);
});
