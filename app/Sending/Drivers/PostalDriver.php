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
 * Postal (self-hosted MTA) API driver (spec §2.9.2 F-2.9.2-011, D-21 key
 * "postal"). POST https://{hostname}/api/v1/send/message with
 * X-Server-API-Key: {api_key}. Postal replies HTTP 200 even on failure with
 * {"status":"error"|"parameter-error", "data": {...}} — classified below.
 *
 * Transient-vs-permanent classification:
 *  | condition                                  | classification |
 *  |--------------------------------------------|----------------|
 *  | connection error / HTTP 408, 429, 5xx      | transient      |
 *  | body status "error" code SendLimitExceeded | transient      |
 *  | body status "error"/"parameter-error"      | permanent      |
 *  | (bad recipients, auth, suspended server)   | permanent      |
 */
class PostalDriver extends AbstractApiDriver
{
    public function key(): string
    {
        return 'postal';
    }

    public function endpoint(DeliveryServer $server): string
    {
        $host = rtrim((string) $server->hostname, '/');

        if (! str_starts_with($host, 'http://') && ! str_starts_with($host, 'https://')) {
            $host = 'https://'.$host;
        }

        return $host.'/api/v1/send/message';
    }

    /** @return array<string, mixed> */
    public function payload(Message $message, DeliveryServer $server): array
    {
        [$fromEmail, $fromName] = $this->mime->effectiveFrom($message, $server);
        $replyTo = $this->mime->effectiveReplyTo($message, $server);
        $headers = $this->mime->effectiveHeaders($message, $server);

        $payload = [
            'to' => array_keys($message->recipients()),
            'from' => $fromName !== null && $fromName !== '' ? sprintf('%s <%s>', $fromName, $fromEmail) : $fromEmail,
            'subject' => $message->subject,
        ];

        if ($message->html !== null) {
            $payload['html_body'] = $message->html;
        }

        if ($message->text !== null || $message->html === null) {
            $payload['plain_body'] = $message->text ?? '';
        }

        if ($replyTo !== null && $replyTo !== '') {
            $payload['reply_to'] = $replyTo;
        }

        if ($headers !== []) {
            $payload['headers'] = $headers;
        }

        foreach ($message->attachments as $attachment) {
            $payload['attachments'][] = [
                'name' => $attachment['name'],
                'content_type' => $attachment['mime'] ?? 'application/octet-stream',
                'data' => base64_encode($attachment['content']),
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
            $response = Http::withHeaders(['X-Server-API-Key' => (string) $server->api_key])
                ->post($this->endpoint($server), $this->payload($message, $server));

            if (! $response->successful()) {
                return $this->failureFromResponse($response);
            }

            if ($response->json('status') === 'success') {
                return SendResult::success($response->json('data.message_id'));
            }

            $error = 'Postal '.$response->json('status').': '.json_encode($response->json('data'));

            if (str_contains($error, 'SendLimitExceeded')) {
                return SendResult::transientFailure($error);
            }

            return SendResult::permanentFailure($error);
        } catch (ConnectionException $e) {
            return $this->failureFromConnection($e);
        } catch (Throwable $e) {
            return SendResult::permanentFailure($e->getMessage());
        }
    }

    public function validateConfig(DeliveryServer $server): array
    {
        $errors = [];

        if ($server->hostname === null || $server->hostname === '') {
            $errors['hostname'] = __('sending.validation.hostname_required');
        }

        if ($server->api_key === null || $server->api_key === '') {
            $errors['api_key'] = __('sending.validation.api_key_required');
        }

        return $errors;
    }

    public function formSchema(): array
    {
        return [
            $this->field('hostname', 'text', ['required' => true, 'label' => 'sending.fields.postal_host']),
            $this->field('api_key', 'password', ['required' => true, 'label' => 'sending.fields.server_api_key']),
        ];
    }
}
