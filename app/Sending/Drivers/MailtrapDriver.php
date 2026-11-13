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
 * Mailtrap sending API driver (spec §2.9.2 F-2.9.2-013, D-21 key
 * "mailtrap-api"). POST https://send.api.mailtrap.io/api/send with
 * Api-Token: {api_key}; success is HTTP 200 with `success: true` and
 * `message_ids`.
 *
 * `config.sandbox_inbox_id` switches the host to the sandbox endpoint
 * (https://sandbox.api.mailtrap.io/api/send/{inbox}), which captures mail
 * instead of delivering it — the operator's explicit choice, surfaced in the
 * form help so a sandbox server is never mistaken for a live one.
 *
 * Transient-vs-permanent classification:
 *  | condition                             | classification |
 *  |---------------------------------------|----------------|
 *  | connection error / HTTP 429, 5xx      | transient      |
 *  | 400 (payload), 401 (auth), 403        | permanent      |
 */
class MailtrapDriver extends AbstractApiDriver
{
    public const ENDPOINT = 'https://send.api.mailtrap.io/api/send';

    public const SANDBOX_ENDPOINT = 'https://sandbox.api.mailtrap.io/api/send';

    public function key(): string
    {
        return 'mailtrap-api';
    }

    public function endpointFor(DeliveryServer $server): string
    {
        $config = (array) ($server->config ?? []);
        $inbox = trim((string) ($config['sandbox_inbox_id'] ?? ''));

        return $inbox !== ''
            ? self::SANDBOX_ENDPOINT.'/'.$inbox
            : self::ENDPOINT;
    }

    /** @return array<string, mixed> */
    public function payload(Message $message, DeliveryServer $server): array
    {
        [$fromEmail, $fromName] = $this->mime->effectiveFrom($message, $server);
        $replyTo = $this->mime->effectiveReplyTo($message, $server);
        $headers = $this->mime->effectiveHeaders($message, $server);

        $to = [];

        foreach ($message->recipients() as $email => $name) {
            $to[] = array_filter(['email' => $email, 'name' => $name], fn ($v) => $v !== null && $v !== '');
        }

        $payload = [
            'from' => array_filter(['email' => $fromEmail, 'name' => $fromName], fn ($v) => $v !== null && $v !== ''),
            'to' => $to,
            'subject' => $message->subject,
        ];

        if ($message->html !== null) {
            $payload['html'] = $message->html;
        }

        if ($message->text !== null || $message->html === null) {
            $payload['text'] = $message->text ?? '';
        }

        if ($replyTo !== null && $replyTo !== '') {
            $payload['reply_to'] = ['email' => $replyTo];
        }

        if ($headers !== []) {
            $payload['headers'] = $headers;
        }

        foreach ($message->attachments as $attachment) {
            $payload['attachments'][] = [
                'filename' => $attachment['name'],
                'content' => base64_encode($attachment['content']),
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
            $response = Http::withHeaders([
                'Api-Token' => (string) $server->api_key,
                'Accept' => 'application/json',
            ])->post($this->endpointFor($server), $this->payload($message, $server));

            if ($response->successful() && $response->json('success') === true) {
                $ids = (array) $response->json('message_ids', []);

                return SendResult::success($ids[0] ?? null);
            }

            if ($response->successful()) {
                $errors = (array) $response->json('errors', []);

                return SendResult::permanentFailure(
                    $errors === [] ? 'Mailtrap rejected the message.' : implode(' ', $errors),
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
        return $server->api_key === null || $server->api_key === ''
            ? ['api_key' => __('sending.validation.api_key_required')]
            : [];
    }

    public function formSchema(): array
    {
        return [
            $this->field('api_key', 'password', ['required' => true]),
            $this->field('config.sandbox_inbox_id', 'text', [
                'help' => __('sending.help.mailtrap_sandbox_inbox'),
            ]),
        ];
    }
}
