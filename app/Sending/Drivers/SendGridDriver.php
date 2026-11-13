<?php

declare(strict_types=1);

namespace App\Sending\Drivers;

use App\Models\DeliveryServer;
use App\Sending\Message;
use App\Sending\SendResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * SendGrid v3 Mail Send driver (spec §2.9.2 F-2.9.2-004, D-21 key
 * "sendgrid-api"). POST https://api.sendgrid.com/v3/mail/send with
 * Authorization: Bearer {api_key}; success is HTTP 202 with the id in the
 * X-Message-Id response header.
 *
 * Transient-vs-permanent classification:
 *  | condition                                | classification |
 *  |------------------------------------------|----------------|
 *  | connection error / HTTP 408, 429, 5xx    | transient      |
 *  | 400 (bad payload), 401/403 (auth), 413   | permanent      |
 */
class SendGridDriver extends AbstractApiDriver
{
    public const ENDPOINT = 'https://api.sendgrid.com/v3/mail/send';

    public function key(): string
    {
        return 'sendgrid-api';
    }

    /** @return array<string, mixed> */
    public function payload(Message $message, DeliveryServer $server): array
    {
        [$fromEmail, $fromName] = $this->mime->effectiveFrom($message, $server);
        $replyTo = $this->mime->effectiveReplyTo($message, $server);
        $headers = $this->mime->effectiveHeaders($message, $server);

        $to = [];

        foreach ($message->recipients() as $email => $name) {
            $to[] = array_filter(['email' => $email, 'name' => $name], fn ($v) => $v !== null);
        }

        $content = [];

        if ($message->text !== null || $message->html === null) {
            $content[] = ['type' => 'text/plain', 'value' => $message->text ?? ''];
        }

        if ($message->html !== null) {
            $content[] = ['type' => 'text/html', 'value' => $message->html];
        }

        $payload = [
            'personalizations' => [['to' => $to]],
            'from' => array_filter(['email' => $fromEmail, 'name' => $fromName], fn ($v) => $v !== null && $v !== ''),
            'subject' => $message->subject,
            'content' => $content,
        ];

        if ($replyTo !== null && $replyTo !== '') {
            $payload['reply_to'] = ['email' => $replyTo];
        }

        if ($headers !== []) {
            $payload['headers'] = $headers;
        }

        foreach ($message->attachments as $attachment) {
            $payload['attachments'][] = [
                'content' => base64_encode($attachment['content']),
                'filename' => $attachment['name'],
                'type' => $attachment['mime'] ?? 'application/octet-stream',
            ];
        }

        return $payload;
    }

    public function send(Message $message, DeliveryServer $server): SendResult
    {
        if (($errors = $this->validateConfig($server)) !== []) {
            return SendResult::permanentFailure(implode(' ', $errors));
        }

        try {
            $response = Http::withToken((string) $server->api_key)
                ->post(self::ENDPOINT, $this->payload($message, $server));

            if ($response->successful()) {
                return SendResult::success($response->header('X-Message-Id') ?: null);
            }

            return $this->failureFromResponse($response);
        } catch (ConnectionException $e) {
            return $this->failureFromConnection($e);
        } catch (Throwable $e) {
            return SendResult::permanentFailure($e->getMessage());
        }
    }

    public function validateConfig(DeliveryServer $server): array
    {
        return $server->api_key === null || $server->api_key === ''
            ? ['api_key' => __('sending.validation.api_key_required')]
            : [];
    }

    public function formSchema(): array
    {
        return [
            $this->field('api_key', 'password', ['required' => true]),
        ];
    }
}
