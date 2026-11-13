<?php

declare(strict_types=1);

namespace App\Sending\Drivers;

use App\Models\DeliveryServer;

/**
 * Amazon SES over SMTP (spec §2.9.2 F-2.9.2-002, D-21 key "smtp-amazon-ses").
 *
 * Endpoint derived from the region: email-smtp.{region}.amazonaws.com
 * (SES SMTP endpoints doc); credentials are the SES *SMTP* credentials, not
 * IAM keys. Port 587 STARTTLS by default (SES also allows 465/2587).
 * Classification table: see AbstractSmtpDriver.
 */
class SmtpAmazonSesDriver extends AbstractSmtpDriver
{
    public const DEFAULT_REGION = 'us-east-1';

    /** @var list<string> SES regions with SMTP endpoints (common set) */
    public const REGIONS = [
        'us-east-1', 'us-east-2', 'us-west-1', 'us-west-2',
        'af-south-1', 'ap-south-1', 'ap-northeast-1', 'ap-northeast-2',
        'ap-northeast-3', 'ap-southeast-1', 'ap-southeast-2', 'ap-southeast-3',
        'ca-central-1', 'eu-central-1', 'eu-west-1', 'eu-west-2', 'eu-west-3',
        'eu-north-1', 'eu-south-1', 'me-south-1', 'sa-east-1',
    ];

    public function key(): string
    {
        return 'smtp-amazon-ses';
    }

    protected function host(DeliveryServer $server): string
    {
        return 'email-smtp.'.($server->region ?? self::DEFAULT_REGION).'.amazonaws.com';
    }

    public function validateConfig(DeliveryServer $server): array
    {
        $errors = [];

        if ($server->region === null || $server->region === '') {
            $errors['region'] = __('sending.validation.region_required');
        }

        if ($server->username === null || $server->username === '') {
            $errors['username'] = __('sending.validation.username_required');
        }

        if ($server->password === null || $server->password === '') {
            $errors['password'] = __('sending.validation.password_required');
        }

        return $errors;
    }

    public function formSchema(): array
    {
        return [
            $this->field('region', 'select', [
                'required' => true,
                'default' => self::DEFAULT_REGION,
                'options' => array_combine(self::REGIONS, self::REGIONS),
            ]),
            $this->field('port', 'number', ['required' => true, 'default' => 587]),
            $this->field('username', 'text', ['required' => true, 'help' => 'sending.help.ses_smtp_username']),
            $this->field('password', 'password', ['required' => true, 'help' => 'sending.help.ses_smtp_password']),
        ];
    }
}
