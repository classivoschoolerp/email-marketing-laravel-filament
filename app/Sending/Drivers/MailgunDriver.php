<?php

declare(strict_types=1);

namespace App\Sending\Drivers;

use App\Models\DeliveryServer;
use App\Sending\Message;
use App\Sending\SendResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Mailgun Messages API driver (spec §2.9.2 F-2.9.2-005, D-21 key
 * "mailgun-api"). POST https://api.mailgun.net/v3/{domain}/messages
 * (api.eu.mailgun.net for the EU region) as multipart form data with HTTP
 * basic auth "api:{key}". Sending domain comes from config.domain.
 *
 * Transient-vs-permanent classification:
 *  | condition                               | classification |
 *  |-----------------------------------------|----------------|
 *  | connection error / HTTP 408, 429, 5xx   | transient      |
 *  | 400 (bad params), 401 (auth), 404 (bad  | permanent      |
 *  |   domain), 413 (too large)              | permanent      |
 */
class MailgunDriver extends AbstractApiDriver
{
    public function key(): string
    {
        return 'mailgun-api';
    }

    public function endpoint(DeliveryServer $server): string
    {
        $base = ($server->region === 'eu') ? 'https://api.eu.mailgun.net' : 'https://api.mailgun.net';

        return $base.'/v3/'.$server->configValue('domain').'/messages';
    }

    /** Form fields (multipart) — public for payload-shape tests. */
    public function payload(Message $message, DeliveryServer $server): array
    {
        [$fromEmail, $fromName] = $this->mime->effectiveFrom($message, $server);
        $replyTo = $this->mime->effectiveReplyTo($message, $server);

        $to = [];

        foreach ($message->recipients() as $email => $name) {
            $to[] = $name !== null && $name !== '' ? sprintf('%s <%s>', $name, $email) : $email;
        }

        $payload = [
            'from' => $fromName !== null && $fromName !== '' ? sprintf('%s <%s>', $fromName, $fromEmail) : $fromEmail,
            'to' => implode(', ', $to),
            'subject' => $message->subject,
        ];

        if ($message->html !== null) {
            $payload['html'] = $message->html;
        }

        if ($message->text !== null || $message->html === null) {
            $payload['text'] = $message->text ?? '';
        }

        if ($replyTo !== null && $replyTo !== '') {
            $payload['h:Reply-To'] = $replyTo;
        }

        foreach ($this->mime->effectiveHeaders($message, $server) as $name => $value) {
            $payload['h:'.$name] = $value;
        }

        return $payload;
    }

    public function send(Message $message, DeliveryServer $server): SendResult
    {
        if (($errors = $this->validateConfig($server)) !== []) {
            return SendResult::permanentFailure(implode(' ', $errors));
        }

        try {
            $request = Http::withBasicAuth('api', (string) $server->api_key)->asMultipart();
            $request = $this->attach($request, $message);

            $response = $request->post($this->endpoint($server), $this->multipart($message, $server));

            if ($response->successful()) {
                return SendResult::success($response->json('id'));
            }

            return $this->failureFromResponse($response);
        } catch (ConnectionException $e) {
            return $this->failureFromConnection($e);
        } catch (Throwable $e) {
            return SendResult::permanentFailure($e->getMessage());
        }
    }

    private function attach(PendingRequest $request, Message $message): PendingRequest
    {
        foreach ($message->attachments as $attachment) {
            $request = $request->attach(
                'attachment',
                $attachment['content'],
                $attachment['name'],
                isset($attachment['mime']) ? ['Content-Type' => $attachment['mime']] : [],
            );
        }

        return $request;
    }

    /** @return list<array{name: string, contents: string}> */
    private function multipart(Message $message, DeliveryServer $server): array
    {
        $parts = [];

        foreach ($this->payload($message, $server) as $name => $value) {
            $parts[] = ['name' => $name, 'contents' => (string) $value];
        }

        return $parts;
    }

    public function validateConfig(DeliveryServer $server): array
    {
        $errors = [];

        if ($server->api_key === null || $server->api_key === '') {
            $errors['api_key'] = __('sending.validation.api_key_required');
        }

        $domain = $server->configValue('domain');

        if (! is_string($domain) || $domain === '') {
            $errors['config.domain'] = __('sending.validation.domain_required');
        }

        return $errors;
    }

    public function formSchema(): array
    {
        return [
            $this->field('config.domain', 'text', ['required' => true, 'label' => 'sending.fields.mailgun_domain']),
            $this->field('api_key', 'password', ['required' => true]),
            $this->field('region', 'select', [
                'default' => 'us',
                'options' => ['us' => 'sending.regions.us', 'eu' => 'sending.regions.eu'],
            ]),
        ];
    }
}
