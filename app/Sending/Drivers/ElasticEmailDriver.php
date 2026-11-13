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
 * Elastic Email v4 API driver (spec §2.9.2 F-2.9.2-008, D-21 key
 * "elastic-email-api"). POST https://api.elasticemail.com/v4/emails with
 * X-ElasticEmail-ApiKey: {api_key}.
 *
 * Transient-vs-permanent classification:
 *  | condition                             | classification |
 *  |---------------------------------------|----------------|
 *  | connection error / HTTP 408, 429, 5xx | transient      |
 *  | 400 (bad payload), 401/403 (auth),    | permanent      |
 *  |   402 (no credit)                     | permanent      |
 */
class ElasticEmailDriver extends AbstractApiDriver
{
    public const ENDPOINT = 'https://api.elasticemail.com/v4/emails';

    public function key(): string
    {
        return 'elastic-email-api';
    }

    /** @return array<string, mixed> */
    public function payload(Message $message, DeliveryServer $server): array
    {
        [$fromEmail, $fromName] = $this->mime->effectiveFrom($message, $server);
        $replyTo = $this->mime->effectiveReplyTo($message, $server);
        $headers = $this->mime->effectiveHeaders($message, $server);

        $body = [];

        if ($message->html !== null) {
            $body[] = ['ContentType' => 'HTML', 'Content' => $message->html, 'Charset' => 'utf-8'];
        }

        if ($message->text !== null || $message->html === null) {
            $body[] = ['ContentType' => 'PlainText', 'Content' => $message->text ?? '', 'Charset' => 'utf-8'];
        }

        $content = [
            'From' => $fromName !== null && $fromName !== '' ? sprintf('%s <%s>', $fromName, $fromEmail) : $fromEmail,
            'Subject' => $message->subject,
            'Body' => $body,
        ];

        if ($replyTo !== null && $replyTo !== '') {
            $content['ReplyTo'] = $replyTo;
        }

        if ($headers !== []) {
            $content['Headers'] = $headers;
        }

        foreach ($message->attachments as $attachment) {
            $content['Attachments'][] = [
                'BinaryContent' => base64_encode($attachment['content']),
                'Name' => $attachment['name'],
                'ContentType' => $attachment['mime'] ?? 'application/octet-stream',
            ];
        }

        return [
            'Recipients' => array_map(
                static fn (string $email): array => ['Email' => $email],
                array_keys($message->recipients()),
            ),
            'Content' => $content,
        ];
    }

    public function send(Message $message, DeliveryServer $server): SendResult
    {
        if (($errors = $this->validateConfig($server)) !== []) {
            return SendResult::permanentFailure(implode(' ', $errors));
        }

        try {
            $response = Http::withHeaders(['X-ElasticEmail-ApiKey' => (string) $server->api_key])
                ->post(self::ENDPOINT, $this->payload($message, $server));

            if ($response->successful()) {
                return SendResult::success($response->json('MessageID') ?? $response->json('TransactionID'));
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
