<?php

declare(strict_types=1);

namespace App\Sending\Drivers;

use App\Models\DeliveryServer;
use App\Sending\Message;
use App\Sending\SendResult;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\SendmailTransport;
use Symfony\Component\Mime\Address;
use Throwable;

/**
 * Local-MTA family base (spec §2.9.2 F-2.9.2-016/019) on Symfony
 * SendmailTransport. Each concrete driver ships its own default binary path
 * and pipe mode; both are overridable per server via config.binary_path /
 * config.mode ("-bs" = SMTP over the binary's stdio, "-t" = pipe the message
 * and let the MTA read recipients from headers).
 *
 * These drivers cannot be end-to-end tested on Windows (no MTA binaries) —
 * tests assert the built command/transport mapping instead (docs/DECISIONS.md
 * D-16); docs/e2e-checklist.md records the live-host verification steps.
 *
 * Transient-vs-permanent classification:
 *  | condition                                   | classification |
 *  |---------------------------------------------|----------------|
 *  | binary missing / process failed to start    | transient      |
 *  | non-zero exit / broken pipe                 | transient      |
 *  | 5xx reply in -bs mode                       | permanent      |
 *  | invalid message build (bad address, config) | permanent      |
 */
abstract class AbstractSendmailDriver extends AbstractDriver
{
    /** Absolute path of the MTA submission binary on the host. */
    abstract protected function defaultBinaryPath(): string;

    /** "-bs" (speak SMTP to the binary) or "-t" (pipe message on stdin). */
    protected function defaultMode(): string
    {
        return '-bs';
    }

    public function binaryPath(DeliveryServer $server): string
    {
        $path = $server->configValue('binary_path');

        return is_string($path) && $path !== '' ? $path : $this->defaultBinaryPath();
    }

    public function mode(DeliveryServer $server): string
    {
        $mode = $server->configValue('mode');

        return in_array($mode, ['-bs', '-t'], true) ? $mode : $this->defaultMode();
    }

    /** Full command handed to SendmailTransport — public for tests (D-16). */
    public function command(DeliveryServer $server): string
    {
        return $this->binaryPath($server).' '.$this->mode($server);
    }

    /** Public for tests: the configured transport, never started locally. */
    public function buildTransport(DeliveryServer $server): SendmailTransport
    {
        return new SendmailTransport($this->command($server));
    }

    public function send(Message $message, DeliveryServer $server): SendResult
    {
        if (($errors = $this->validateConfig($server)) !== []) {
            return SendResult::permanentFailure(implode(' ', $errors));
        }

        try {
            $mime = $this->mime->build($message, $server);
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
            $code = (int) $e->getCode();

            // Process/pipe failures are retryable; 5xx SMTP replies are not.
            if ($code >= 500 && $code < 600) {
                return SendResult::permanentFailure($e->getMessage());
            }

            return SendResult::transientFailure($e->getMessage());
        } catch (Throwable $e) {
            return SendResult::permanentFailure($e->getMessage());
        }
    }

    public function validateConfig(DeliveryServer $server): array
    {
        $errors = [];

        if ($this->binaryPath($server) === '') {
            $errors['config.binary_path'] = __('sending.validation.binary_path_required');
        }

        return $errors;
    }

    public function capabilities(): array
    {
        return [
            'transport' => 'sendmail',
            'attachments' => true,
            'custom_headers' => true,
            'dkim' => true,
        ];
    }

    public function formSchema(): array
    {
        return [
            $this->field('config.binary_path', 'text', [
                'required' => true,
                'default' => $this->defaultBinaryPath(),
                'help' => 'sending.help.binary_path',
            ]),
            $this->field('config.mode', 'select', [
                'default' => $this->defaultMode(),
                'options' => ['-bs' => 'sending.mode.bs', '-t' => 'sending.mode.t'],
                'help' => 'sending.help.sendmail_mode',
            ]),
        ];
    }
}
