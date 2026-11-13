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
 * Postmark Email API driver (spec §2.9.2 F-2.9.2-007, D-21 key
 * "postmark-api"). POST https://api.postmarkapp.com/email with
 * X-Postmark-Server-Token: {api_key}.
 *
 * Transient-vs-permanent classification:
 *  | condition                                        | classification |
 *  |--------------------------------------------------|----------------|
 *  | connection error / HTTP 408, 429, 5xx            | transient      |
 *  | 422 ErrorCode 405 (not allowed yet, activation)  | transient      |
 *  | 422 other ErrorCodes (300 invalid email, 406     | permanent      |
 *  |   inactive recipient, 401/403 auth)              | permanent      |
 */
class PostmarkDriver extends AbstractApiDriver
{
    public const ENDPOINT = 'https://api.postmarkapp.com/email';

    /** Postmark ErrorCodes that are worth retrying. */
    private const TRANSIENT_ERROR_CODES = [405];

    public function key(): string
    {
        return 'postmark-api';
    }

    /** @return array<string, mixed> */
    public function payload(Message $message, DeliveryServer $server): array
    {
        [$fromEmail, $fromName] = $this->mime->effectiveFrom($message, $server);
        $replyTo = $this->mime->effectiveReplyTo($message, $server);

        $to = [];

        foreach ($message->recipients() as $email => $name) {
            $to[] = $name !== null && $name !== '' ? sprintf('%s <%s>', $name, $email) : $email;
        }

        $payload = [
            'From' => $fromName !== null && $fromName !== '' ? sprintf('%s <%s>', $fromName, $fromEmail) : $fromEmail,
            'To' => implode(',', $to),
            'Subject' => $message->subject,
        ];

        if ($message->html !== null) {
            $payload['HtmlBody'] = $message->html;
        }

        if ($message->text !== null || $message->html === null) {
            $payload['TextBody'] = $message->text ?? '';
        }

        if ($replyTo !== null && $replyTo !== '') {
            $payload['ReplyTo'] = $replyTo;
        }

        foreach ($this->mime->effectiveHeaders($message, $server) as $name => $value) {
            $payload['Headers'][] = ['Name' => $name, 'Value' => $value];
        }

        foreach ($message->attachments as $attachment) {
            $payload['Attachments'][] = [
                'Name' => $attachment['name'],
                'Content' => base64_encode($attachment['content']),
                'ContentType' => $attachment['mime'] ?? 'application/octet-stream',
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
                'X-Postmark-Server-Token' => (string) $server->api_key,
                'Accept' => 'application/json',
            ])->post(self::ENDPOINT, $this->payload($message, $server));

            if ($response->successful()) {
                return SendResult::success($response->json('MessageID'));
            }

            $errorCode = (int) $response->json('ErrorCode', 0);

            if (in_array($errorCode, self::TRANSIENT_ERROR_CODES, true)) {
                return SendResult::transientFailure('Postmark ErrorCode '.$errorCode.': '.$response->body());
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
            $this->field('api_key', 'password', ['required' => true, 'label' => 'sending.fields.server_token']),
        ];
    }
}
