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
 * Loops transactional API driver (spec §2.9.2 F-2.9.2-013, D-21 key
 * "loops-api"). POST https://app.loops.so/api/v1/transactional with a Bearer
 * key; success is HTTP 200 with `success: true`.
 *
 * Loops is template-first: the transactional endpoint sends a template the
 * operator authored in Loops, with `dataVariables` filling its placeholders.
 * The platform's own rendered HTML therefore cannot be pushed — the server's
 * `config.transactional_id` names the Loops template, and the subject/body we
 * rendered are passed as data variables so a template can echo them. This is
 * a real constraint of the vendor API, not a simplification; it is stated in
 * the field help text so an operator is never surprised.
 *
 * Transient-vs-permanent classification:
 *  | condition                             | classification |
 *  |---------------------------------------|----------------|
 *  | connection error / HTTP 429, 5xx      | transient      |
 *  | 400 (bad payload), 401/403 (auth)     | permanent      |
 */
class LoopsDriver extends AbstractApiDriver
{
    public const ENDPOINT = 'https://app.loops.so/api/v1/transactional';

    public function key(): string
    {
        return 'loops-api';
    }

    /** @return array<string, mixed> */
    public function payload(Message $message, DeliveryServer $server): array
    {
        $config = (array) ($server->config ?? []);
        $recipients = $message->recipients();
        $email = (string) array_key_first($recipients);

        $payload = [
            'transactionalId' => (string) ($config['transactional_id'] ?? ''),
            'email' => $email,
            'dataVariables' => [
                'subject' => $message->subject,
                'html' => $message->html ?? '',
                'text' => $message->text ?? '',
            ],
        ];

        foreach ($message->attachments as $attachment) {
            $payload['attachments'][] = [
                'filename' => $attachment['name'],
                'contentType' => $attachment['mime'] ?? 'application/octet-stream',
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
            $response = Http::withToken((string) $server->api_key)
                ->acceptJson()
                ->post(self::ENDPOINT, $this->payload($message, $server));

            if ($response->successful() && $response->json('success') === true) {
                return SendResult::success($response->json('id'));
            }

            if ($response->successful()) {
                // 200 with success:false — the vendor rejected the payload.
                return SendResult::permanentFailure((string) $response->json('message', 'Loops rejected the message.'));
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

        if ($server->api_key === null || $server->api_key === '') {
            $errors['api_key'] = __('sending.validation.api_key_required');
        }

        $config = (array) ($server->config ?? []);

        if (($config['transactional_id'] ?? '') === '') {
            $errors['transactional_id'] = __('sending.validation.loops_transactional_id_required');
        }

        return $errors;
    }

    public function formSchema(): array
    {
        return [
            $this->field('api_key', 'password', ['required' => true]),
            $this->field('config.transactional_id', 'text', [
                'required' => true,
                'help' => __('sending.help.loops_transactional_id'),
            ]),
        ];
    }
}
