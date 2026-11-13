<?php

declare(strict_types=1);

namespace App\Sending\Drivers;

use App\Enums\ServerProtocol;
use App\Models\DeliveryServer;

/**
 * Generic SMTP relay (spec §2.9.2 F-2.9.2-001, D-21 key "smtp").
 * Classification table: see AbstractSmtpDriver.
 */
class SmtpDriver extends AbstractSmtpDriver
{
    public function key(): string
    {
        return 'smtp';
    }

    protected function host(DeliveryServer $server): string
    {
        return (string) $server->hostname;
    }

    public function validateConfig(DeliveryServer $server): array
    {
        $errors = [];

        if ($server->hostname === null || $server->hostname === '') {
            $errors['hostname'] = __('sending.validation.hostname_required');
        }

        if ($server->port === null || $server->port < 1 || $server->port > 65535) {
            $errors['port'] = __('sending.validation.port_required');
        }

        return $errors;
    }

    public function formSchema(): array
    {
        return [
            $this->field('hostname', 'text', ['required' => true]),
            $this->field('port', 'number', ['required' => true, 'default' => 587]),
            $this->field('protocol', 'select', [
                'required' => true,
                'default' => ServerProtocol::Tls->value,
                'options' => [
                    ServerProtocol::None->value => 'sending.protocol.none',
                    ServerProtocol::Tcp->value => 'sending.protocol.tcp',
                    ServerProtocol::Ssl->value => 'sending.protocol.ssl',
                    ServerProtocol::Tls->value => 'sending.protocol.tls',
                ],
            ]),
            $this->field('username', 'text'),
            $this->field('password', 'password'),
        ];
    }
}
