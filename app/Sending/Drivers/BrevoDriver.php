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
 * Brevo (ex-Sendinblue) transactional API driver (spec §2.9.2 F-2.9.2-010,
 * D-21 key "brevo-api"). POST https://api.brevo.com/v3/smtp/email with
 * api-key: {api_key}; success is HTTP 201 with a messageId.
 *
 * Transient-vs-permanent classification:
 *  | condition                              | classification |
 *  |----------------------------------------|----------------|
 *  | connection error / HTTP 408, 429, 5xx  | transient      |
 *  | 400 (payload/sender), 401 (auth), 402  | permanent      |
 *  |   (credit)                             | permanent      |
 */
class BrevoDriver extends AbstractApiDriver
{
    public const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

    public function key(): string
    {
        return 'brevo-api';
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

        $payload = [
            'sender' => array_filter(['email' => $fromEmail, 'name' => $fromName], fn ($v) => $v !== null && $v !== ''),
            'to' => $to,
            'subject' => $message->subject,
        ];

        if ($message->html !== null) {
            $payload['htmlContent'] = $message->html;
        }

        if ($message->text !== null || $message->html === null) {
            $payload['textContent'] = $message->text ?? '';
        }

        if ($replyTo !== null && $replyTo !== '') {
            $payload['replyTo'] = ['email' => $replyTo];
        }

        if ($headers !== []) {
            $payload['headers'] = $headers;
        }

        foreach ($message->attachments as $attachment) {
            $payload['attachment'][] = [
                'name' => $attachment['name'],
                'content' => base64_encode($attachment['content']),
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
            $response = Http::withHeaders([
                'api-key' => (string) $server->api_key,
                'Accept' => 'application/json',
            ])->post(self::ENDPOINT, $this->payload($message, $server));

            if ($response->successful()) {
                return SendResult::success($response->json('messageId'));
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
