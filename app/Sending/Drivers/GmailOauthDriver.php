<?php

declare(strict_types=1);

namespace App\Sending\Drivers;

use App\Models\DeliveryServer;
use App\Sending\Message;
use App\Sending\MimeBuilder;
use App\Sending\SendResult;
use App\Sending\Support\GoogleOAuth;
use RuntimeException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * Gmail / Google Workspace OAuth driver (spec §2.9.2 F-2.9.2-017, D-21 key
 * "gmail-oauth"). SMTP submission to smtp.gmail.com:587 (STARTTLS) with SASL
 * XOAUTH2: the transport username is the mailbox and the password is a fresh
 * OAuth2 access token minted via refresh-token exchange (GoogleOAuth);
 * Symfony's XOAuth2Authenticator turns that into the XOAUTH2 SASL string.
 *
 * Transient-vs-permanent classification:
 *  | condition                                | classification |
 *  |------------------------------------------|----------------|
 *  | token exchange HTTP failure / 5xx        | transient      |
 *  | SMTP 4xx / connection failure            | transient      |
 *  | invalid_grant (revoked refresh token)    | permanent      |
 *  | SMTP 5xx (auth rejected, quota exceeded) | permanent      |
 */
class GmailOauthDriver extends AbstractSmtpDriver
{
    public const HOST = 'smtp.gmail.com';

    public const PORT = 587;

    public function __construct(MimeBuilder $mime, private readonly GoogleOAuth $oauth)
    {
        parent::__construct($mime);
    }

    public function key(): string
    {
        return 'gmail-oauth';
    }

    protected function host(DeliveryServer $server): string
    {
        return self::HOST;
    }

    protected function port(DeliveryServer $server): int
    {
        return self::PORT;
    }

    protected function password(DeliveryServer $server): ?string
    {
        // The SMTP "password" is the short-lived OAuth access token.
        return $this->oauth->accessToken($server);
    }

    public function buildTransport(DeliveryServer $server): EsmtpTransport
    {
        // Gmail is always STARTTLS on 587 regardless of the row's protocol.
        $transport = new EsmtpTransport(self::HOST, self::PORT);
        $transport->setUsername((string) $server->username);
        $transport->setPassword($this->password($server) ?? '');

        return $transport;
    }

    public function send(Message $message, DeliveryServer $server): SendResult
    {
        if (($errors = $this->validateConfig($server)) !== []) {
            return SendResult::permanentFailure(implode(' ', $errors));
        }

        try {
            // Mint (and cache) the access token up front so the exchange
            // outcome can be classified before touching the transport.
            $this->oauth->accessToken($server);
        } catch (RuntimeException $e) {
            // Revoked grants are permanent; Google/transport hiccups retryable.
            $permanent = str_contains($e->getMessage(), 'invalid_grant');

            return $permanent
                ? SendResult::permanentFailure($e->getMessage())
                : SendResult::transientFailure($e->getMessage());
        }

        return parent::send($message, $server);
    }

    public function validateConfig(DeliveryServer $server): array
    {
        $errors = [];

        if ($server->username === null || $server->username === '') {
            $errors['username'] = __('sending.validation.gmail_address_required');
        }

        if (! is_string($server->configValue('client_id')) || $server->configValue('client_id') === '') {
            $errors['config.client_id'] = __('sending.validation.client_id_required');
        }

        if ($server->api_key === null || $server->api_key === '') {
            $errors['api_key'] = __('sending.validation.client_secret_required');
        }

        if ($server->password === null || $server->password === '') {
            $errors['password'] = __('sending.validation.refresh_token_required');
        }

        return $errors;
    }

    public function capabilities(): array
    {
        return [
            'transport' => 'smtp',
            'auth' => 'xoauth2',
            'attachments' => true,
            'custom_headers' => true,
            'dkim' => false, // Google signs with its own DKIM
        ];
    }

    public function formSchema(): array
    {
        return [
            $this->field('username', 'text', ['required' => true, 'label' => 'sending.fields.gmail_address']),
            $this->field('config.client_id', 'text', ['required' => true]),
            $this->field('api_key', 'password', ['required' => true, 'label' => 'sending.fields.client_secret']),
            $this->field('password', 'password', ['required' => true, 'label' => 'sending.fields.refresh_token', 'help' => 'sending.help.refresh_token']),
        ];
    }
}
