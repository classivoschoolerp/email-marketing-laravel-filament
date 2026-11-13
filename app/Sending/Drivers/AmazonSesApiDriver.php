<?php

declare(strict_types=1);

namespace App\Sending\Drivers;

use App\Models\DeliveryServer;
use App\Sending\Message;
use App\Sending\MimeBuilder;
use App\Sending\SendResult;
use App\Sending\Support\SigV4Signer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Amazon SES v2 API driver (spec §2.9.2 F-2.9.2-003, D-21 key
 * "amazon-ses-api"). POST https://email.{region}.amazonaws.com/v2/email/outbound-emails
 * signed with SigV4 (SigV4Signer, service "ses"). Credentials: username =
 * IAM access key id, password = IAM secret key (encrypted at rest).
 *
 * Simple content for plain html/text sends; when attachments or custom
 * headers are present the full MIME (MimeBuilder, incl. DKIM) is sent as
 * Raw content.
 *
 * Transient-vs-permanent classification:
 *  | condition                                          | classification |
 *  |----------------------------------------------------|----------------|
 *  | connection error / timeout / HTTP 408, 429, 5xx    | transient      |
 *  | TooManyRequestsException / Throttling (any status) | transient      |
 *  | MessageRejected, MailFromDomainNotVerified,        | permanent      |
 *  |   AccountSuspended, BadRequest, auth (403)         | permanent      |
 */
class AmazonSesApiDriver extends AbstractApiDriver
{
    public const DEFAULT_REGION = 'us-east-1';

    public function __construct(MimeBuilder $mime, private readonly SigV4Signer $signer)
    {
        parent::__construct($mime);
    }

    public function key(): string
    {
        return 'amazon-ses-api';
    }

    public function endpoint(DeliveryServer $server): string
    {
        return 'https://email.'.($server->region ?? self::DEFAULT_REGION).'.amazonaws.com/v2/email/outbound-emails';
    }

    /** @return array<string, mixed> */
    public function payload(Message $message, DeliveryServer $server): array
    {
        [$fromEmail, $fromName] = $this->mime->effectiveFrom($message, $server);
        $replyTo = $this->mime->effectiveReplyTo($message, $server);
        $headers = $this->mime->effectiveHeaders($message, $server);

        $payload = [
            'FromEmailAddress' => $fromName !== null && $fromName !== ''
                ? sprintf('%s <%s>', $fromName, $fromEmail)
                : $fromEmail,
            'Destination' => ['ToAddresses' => array_keys($message->recipients())],
        ];

        if ($replyTo !== null && $replyTo !== '') {
            $payload['ReplyToAddresses'] = [$replyTo];
        }

        if ($message->attachments !== [] || $headers !== []) {
            // Raw MIME keeps attachments + custom headers honest.
            $payload['Content'] = [
                'Raw' => ['Data' => base64_encode($this->mime->build($message, $server)->toString())],
            ];

            return $payload;
        }

        $body = [];

        if ($message->html !== null) {
            $body['Html'] = ['Data' => $message->html, 'Charset' => 'UTF-8'];
        }

        if ($message->text !== null || $message->html === null) {
            $body['Text'] = ['Data' => $message->text ?? '', 'Charset' => 'UTF-8'];
        }

        $payload['Content'] = [
            'Simple' => [
                'Subject' => ['Data' => $message->subject, 'Charset' => 'UTF-8'],
                'Body' => $body,
            ],
        ];

        return $payload;
    }

    public function send(Message $message, DeliveryServer $server): SendResult
    {
        if (($errors = $this->validateConfig($server)) !== []) {
            return SendResult::permanentFailure(implode(' ', $errors));
        }

        try {
            $endpoint = $this->endpoint($server);
            $body = (string) json_encode($this->payload($message, $server));

            $signed = $this->signer->sign(
                'POST',
                $endpoint,
                ['content-type' => 'application/json'],
                $body,
                (string) $server->username,
                (string) $server->password,
                $server->region ?? self::DEFAULT_REGION,
                'ses',
                Carbon::now('UTC')->format('Ymd\THis\Z'),
            );

            $response = Http::withHeaders($signed + ['Content-Type' => 'application/json'])
                ->withBody($body, 'application/json')
                ->post($endpoint);

            if ($response->successful()) {
                return SendResult::success($response->json('MessageId'));
            }

            // Throttling can surface as 400 with a Throttling __type.
            if (str_contains($response->body(), 'Throttling')
                || str_contains($response->body(), 'TooManyRequests')) {
                return SendResult::transientFailure('HTTP '.$response->status().': '.$response->body());
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
            $errors['username'] = __('sending.validation.access_key_required');
        }

        if ($server->password === null || $server->password === '') {
            $errors['password'] = __('sending.validation.secret_key_required');
        }

        if ($server->region === null || $server->region === '') {
            $errors['region'] = __('sending.validation.region_required');
        }

        return $errors;
    }

    public function formSchema(): array
    {
        return [
            $this->field('region', 'select', [
                'required' => true,
                'default' => self::DEFAULT_REGION,
                'options' => array_combine(SmtpAmazonSesDriver::REGIONS, SmtpAmazonSesDriver::REGIONS),
            ]),
            $this->field('username', 'text', ['required' => true, 'label' => 'sending.fields.access_key_id']),
            $this->field('password', 'password', ['required' => true, 'label' => 'sending.fields.secret_access_key']),
        ];
    }
}
