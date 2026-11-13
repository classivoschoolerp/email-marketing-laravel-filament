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
 * SparkPost Transmissions API driver (spec §2.9.2 F-2.9.2-006, D-21 key
 * "sparkpost-api"). POST https://api.sparkpost.com/api/v1/transmissions
 * (api.eu.sparkpost.com for the EU region) with Authorization: {api_key}.
 *
 * Transient-vs-permanent classification:
 *  | condition                              | classification |
 *  |----------------------------------------|----------------|
 *  | connection error / HTTP 408, 420, 429, | transient      |
 *  |   5xx (420 = SparkPost throttle)       | transient      |
 *  | 400 (validation), 401/403 (auth), 413  | permanent      |
 */
class SparkPostDriver extends AbstractApiDriver
{
    public function key(): string
    {
        return 'sparkpost-api';
    }

    public function endpoint(DeliveryServer $server): string
    {
        $base = ($server->region === 'eu') ? 'https://api.eu.sparkpost.com' : 'https://api.sparkpost.com';

        return $base.'/api/v1/transmissions';
    }

    /** @return array<string, mixed> */
    public function payload(Message $message, DeliveryServer $server): array
    {
        [$fromEmail, $fromName] = $this->mime->effectiveFrom($message, $server);
        $replyTo = $this->mime->effectiveReplyTo($message, $server);
        $headers = $this->mime->effectiveHeaders($message, $server);

        $recipients = [];

        foreach ($message->recipients() as $email => $name) {
            $recipients[] = ['address' => array_filter(['email' => $email, 'name' => $name], fn ($v) => $v !== null)];
        }

        $content = [
            'from' => array_filter(['email' => $fromEmail, 'name' => $fromName], fn ($v) => $v !== null && $v !== ''),
            'subject' => $message->subject,
        ];

        if ($message->html !== null) {
            $content['html'] = $message->html;
        }

        if ($message->text !== null || $message->html === null) {
            $content['text'] = $message->text ?? '';
        }

        if ($replyTo !== null && $replyTo !== '') {
            $content['reply_to'] = $replyTo;
        }

        if ($headers !== []) {
            $content['headers'] = $headers;
        }

        foreach ($message->attachments as $attachment) {
            $content['attachments'][] = [
                'name' => $attachment['name'],
                'type' => $attachment['mime'] ?? 'application/octet-stream',
                'data' => base64_encode($attachment['content']),
            ];
        }

        return ['recipients' => $recipients, 'content' => $content];
    }

    public function send(Message $message, DeliveryServer $server): SendResult
    {
        if (($errors = $this->validateConfig($server)) !== []) {
            return SendResult::permanentFailure(implode(' ', $errors));
        }

        try {
            $response = Http::withHeaders(['Authorization' => (string) $server->api_key])
                ->post($this->endpoint($server), $this->payload($message, $server));

            if ($response->successful()) {
                return SendResult::success($response->json('results.id'));
            }

            if ($response->status() === 420) {
                return SendResult::transientFailure('HTTP 420: '.$response->body());
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
            $this->field('region', 'select', [
                'default' => 'us',
                'options' => ['us' => 'sending.regions.us', 'eu' => 'sending.regions.eu'],
            ]),
        ];
    }
}
