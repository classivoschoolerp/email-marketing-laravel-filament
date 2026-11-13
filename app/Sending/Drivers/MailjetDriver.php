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
 * Mailjet v3.1 Send API driver (spec §2.9.2 F-2.9.2-009, D-21 key
 * "mailjet-api"). POST https://api.mailjet.com/v3.1/send with HTTP basic
 * auth — username column = API key, api_key column = API secret.
 *
 * Transient-vs-permanent classification:
 *  | condition                              | classification |
 *  |----------------------------------------|----------------|
 *  | connection error / HTTP 408, 429, 5xx  | transient      |
 *  | 400 (payload), 401 (auth)              | permanent      |
 *  | per-message Status "error" in the 200  | permanent      |
 *  |   response (bad recipient etc.)        | permanent      |
 */
class MailjetDriver extends AbstractApiDriver
{
    public const ENDPOINT = 'https://api.mailjet.com/v3.1/send';

    public function key(): string
    {
        return 'mailjet-api';
    }

    /** @return array<string, mixed> */
    public function payload(Message $message, DeliveryServer $server): array
    {
        [$fromEmail, $fromName] = $this->mime->effectiveFrom($message, $server);
        $replyTo = $this->mime->effectiveReplyTo($message, $server);
        $headers = $this->mime->effectiveHeaders($message, $server);

        $to = [];

        foreach ($message->recipients() as $email => $name) {
            $to[] = array_filter(['Email' => $email, 'Name' => $name], fn ($v) => $v !== null);
        }

        $entry = [
            'From' => array_filter(['Email' => $fromEmail, 'Name' => $fromName], fn ($v) => $v !== null && $v !== ''),
            'To' => $to,
            'Subject' => $message->subject,
        ];

        if ($message->text !== null || $message->html === null) {
            $entry['TextPart'] = $message->text ?? '';
        }

        if ($message->html !== null) {
            $entry['HTMLPart'] = $message->html;
        }

        if ($replyTo !== null && $replyTo !== '') {
            $entry['ReplyTo'] = ['Email' => $replyTo];
        }

        if ($headers !== []) {
            $entry['Headers'] = $headers;
        }

        foreach ($message->attachments as $attachment) {
            $entry['Attachments'][] = [
                'ContentType' => $attachment['mime'] ?? 'application/octet-stream',
                'Filename' => $attachment['name'],
                'Base64Content' => base64_encode($attachment['content']),
            ];
        }

        return ['Messages' => [$entry]];
    }

    public function send(Message $message, DeliveryServer $server): SendResult
    {
        if (($errors = $this->validateConfig($server)) !== []) {
            return SendResult::permanentFailure(implode(' ', $errors));
        }

        try {
            $response = Http::withBasicAuth((string) $server->username, (string) $server->api_key)
                ->post(self::ENDPOINT, $this->payload($message, $server));

            if ($response->successful()) {
                if ($response->json('Messages.0.Status') === 'success') {
                    $id = $response->json('Messages.0.To.0.MessageID');

                    return SendResult::success($id !== null ? (string) $id : null);
                }

                return SendResult::permanentFailure(
                    'Mailjet message error: '.json_encode($response->json('Messages.0.Errors'))
                );
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
        $errors = [];

        if ($server->username === null || $server->username === '') {
            $errors['username'] = __('sending.validation.mailjet_key_required');
        }

        if ($server->api_key === null || $server->api_key === '') {
            $errors['api_key'] = __('sending.validation.mailjet_secret_required');
        }

        return $errors;
    }

    public function formSchema(): array
    {
        return [
            $this->field('username', 'text', ['required' => true, 'label' => 'sending.fields.mailjet_api_key']),
            $this->field('api_key', 'password', ['required' => true, 'label' => 'sending.fields.mailjet_api_secret']),
        ];
    }
}
