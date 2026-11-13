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
 * Blastengine transactional API driver (spec §2.9.2 F-2.9.2-018, D-21 key
 * "blastengine" — Japanese market). POST
 * https://app.engn.jp/api/v1/deliveries/transaction with
 * Authorization: Bearer base64(lowercase(sha256(user_id + api_key))) per the
 * Blastengine API auth doc. username column = Blastengine login id.
 * The transaction endpoint takes ONE recipient ("to" is a string); success is
 * HTTP 201 with a delivery_id.
 *
 * Transient-vs-permanent classification:
 *  | condition                              | classification |
 *  |----------------------------------------|----------------|
 *  | connection error / HTTP 408, 429, 5xx  | transient      |
 *  | 400 (payload), 401/403 (auth)          | permanent      |
 */
class BlastengineDriver extends AbstractApiDriver
{
    public const ENDPOINT = 'https://app.engn.jp/api/v1/deliveries/transaction';

    public function key(): string
    {
        return 'blastengine';
    }

    /** Documented Blastengine bearer token derivation. */
    public function bearerToken(DeliveryServer $server): string
    {
        return base64_encode(strtolower(hash('sha256', $server->username.$server->api_key)));
    }

    /** @return array<string, mixed> */
    public function payload(Message $message, DeliveryServer $server): array
    {
        [$fromEmail, $fromName] = $this->mime->effectiveFrom($message, $server);

        $payload = [
            'from' => array_filter(['email' => $fromEmail, 'name' => $fromName], fn ($v) => $v !== null && $v !== ''),
            'to' => (string) $message->firstRecipient(),
            'subject' => $message->subject,
            'encode' => 'UTF-8',
            'text_part' => $message->text ?? ($message->html !== null ? strip_tags($message->html) : ''),
        ];

        if ($message->html !== null) {
            $payload['html_part'] = $message->html;
        }

        foreach ($message->attachments as $attachment) {
            $payload['attachments'][] = $attachment['name'];
        }

        return $payload;
    }

    public function send(Message $message, DeliveryServer $server): SendResult
    {
        if (($errors = $this->validateConfig($server)) !== []) {
            return SendResult::permanentFailure(implode(' ', $errors));
        }

        try {
            $response = Http::withToken($this->bearerToken($server))
                ->post(self::ENDPOINT, $this->payload($message, $server));

            if ($response->successful()) {
                $id = $response->json('delivery_id');

                return SendResult::success($id !== null ? (string) $id : null);
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
            $errors['username'] = __('sending.validation.blastengine_user_required');
        }

        if ($server->api_key === null || $server->api_key === '') {
            $errors['api_key'] = __('sending.validation.api_key_required');
        }

        return $errors;
    }

    public function capabilities(): array
    {
        return [
            'transport' => 'api',
            'attachments' => false, // transaction endpoint takes names only; binary upload is a separate API
            'custom_headers' => false,
            'dkim' => false,
            'single_recipient' => true,
        ];
    }

    public function formSchema(): array
    {
        return [
            $this->field('username', 'text', ['required' => true, 'label' => 'sending.fields.blastengine_user']),
            $this->field('api_key', 'password', ['required' => true]),
        ];
    }
}
