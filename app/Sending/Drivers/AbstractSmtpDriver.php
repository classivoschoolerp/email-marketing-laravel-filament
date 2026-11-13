<?php

declare(strict_types=1);

namespace App\Sending\Drivers;

use App\Enums\ServerProtocol;
use App\Models\DeliveryServer;
use App\Sending\Message;
use App\Sending\SendResult;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Address;
use Throwable;

/**
 * SMTP-family base built on Symfony EsmtpTransport (WP-14; symfony/mailer
 * ships with Laravel — no extra packages).
 *
 * Connection tuning (F-2.9.2-031):
 *  - max_connection_messages → SmtpTransport::setRestartThreshold()
 *  - keep_alive=false        → restart threshold 1 (new connection per message)
 *  - connection_timeout      → SocketStream::setTimeout()
 *
 * Transient-vs-permanent classification (RetryPolicy contract):
 *  | condition                              | classification |
 *  |----------------------------------------|----------------|
 *  | SMTP 4xx reply (421 throttle, 450 ...) | transient      |
 *  | connection/handshake failure (code 0)  | transient      |
 *  | SMTP 5xx reply (550 rejected, auth ...)| permanent      |
 *  | unexpected non-transport exception     | permanent      |
 */
abstract class AbstractSmtpDriver extends AbstractDriver
{
    /** Host to connect to (driver-specific: column or derived from region). */
    abstract protected function host(DeliveryServer $server): string;

    protected function port(DeliveryServer $server): int
    {
        return $server->port ?? 587;
    }

    protected function username(DeliveryServer $server): ?string
    {
        return $server->username;
    }

    protected function password(DeliveryServer $server): ?string
    {
        return $server->password;
    }

    /** Extra routing headers stamped after MIME build (e.g. PowerMTA VMTA). */
    public function extraHeaders(DeliveryServer $server): array
    {
        return [];
    }

    /**
     * Build the fully-configured transport. Public so tests can assert the
     * mapping without opening live connections (docs/DECISIONS.md D-16).
     */
    public function buildTransport(DeliveryServer $server): EsmtpTransport
    {
        $protocol = $server->protocol ?? ServerProtocol::None;

        // Ssl = implicit TLS (SMTPS); Tls = STARTTLS (Symfony auto-negotiates
        // when $tls is null); None/Tcp = plain socket, TLS disabled.
        $tls = match ($protocol) {
            ServerProtocol::Ssl => true,
            ServerProtocol::Tls => null,
            ServerProtocol::None, ServerProtocol::Tcp => false,
        };

        $transport = new EsmtpTransport($this->host($server), $this->port($server), $tls);

        // None/Tcp mean a genuinely plain session: also stop the automatic
        // STARTTLS upgrade Symfony would otherwise negotiate via EHLO.
        if ($tls === false && method_exists($transport, 'setAutoTls')) {
            $transport->setAutoTls(false);
        }

        if (($username = $this->username($server)) !== null && $username !== '') {
            $transport->setUsername($username);
        }

        if (($password = $this->password($server)) !== null && $password !== '') {
            $transport->setPassword($password);
        }

        if (! $server->keep_alive) {
            $transport->setRestartThreshold(1);
        } elseif ($server->max_connection_messages > 0) {
            $transport->setRestartThreshold($server->max_connection_messages);
        }

        $stream = $transport->getStream();

        if ($stream instanceof SocketStream && $server->connection_timeout > 0) {
            $stream->setTimeout((float) $server->connection_timeout);
        }

        return $transport;
    }

    /** Human-readable DSN summary of the built transport (for tests/logs). */
    public function dsn(DeliveryServer $server): string
    {
        $protocol = $server->protocol ?? ServerProtocol::None;
        $scheme = $protocol === ServerProtocol::Ssl ? 'smtps' : 'smtp';
        $user = $this->username($server);

        return sprintf(
            '%s://%s%s:%d?starttls=%s',
            $scheme,
            $user !== null && $user !== '' ? rawurlencode($user).'@' : '',
            $this->host($server),
            $this->port($server),
            $protocol === ServerProtocol::Tls ? 'yes' : 'no',
        );
    }

    public function send(Message $message, DeliveryServer $server): SendResult
    {
        if (($errors = $this->validateConfig($server)) !== []) {
            return SendResult::permanentFailure(implode(' ', $errors));
        }

        try {
            $mime = $this->mime->build($message, $server);

            foreach ($this->extraHeaders($server) as $name => $value) {
                $mime->getHeaders()->addTextHeader($name, (string) $value);
            }

            [$fromEmail] = $this->mime->effectiveFrom($message, $server);
            $recipients = array_map(
                static fn (string $email): Address => new Address($email),
                array_keys($message->recipients()),
            );

            $sent = $this->buildTransport($server)->send(
                $mime,
                new Envelope(new Address($fromEmail), $recipients),
            );

            return SendResult::success($sent?->getMessageId());
        } catch (TransportExceptionInterface $e) {
            return $this->classifyTransportFailure($e);
        } catch (Throwable $e) {
            return SendResult::permanentFailure($e->getMessage());
        }
    }

    protected function classifyTransportFailure(TransportExceptionInterface $e): SendResult
    {
        $code = (int) $e->getCode();

        // 4xx SMTP replies and connection-level failures (code 0) may resolve
        // on retry; 5xx replies are final for this recipient/server.
        if ($code >= 500 && $code < 600) {
            return SendResult::permanentFailure($e->getMessage());
        }

        return SendResult::transientFailure($e->getMessage());
    }

    public function capabilities(): array
    {
        return [
            'transport' => 'smtp',
            'attachments' => true,
            'custom_headers' => true,
            'dkim' => true,
        ];
    }
}
