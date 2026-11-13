<?php

declare(strict_types=1);

namespace App\Sending\Drivers;

use App\Models\DeliveryServer;

/**
 * PowerMTA via SMTP + VMTA routing headers (spec §2.9.2 F-2.9.2-012, D-21 key
 * "powermta"). Sends through the PowerMTA SMTP listener and stamps
 * X-VirtualMTA (config.virtual_mta) plus optional X-Job (config.job_id) so
 * PowerMTA routes the message through the right virtual MTA / accounting job.
 * Classification table: see AbstractSmtpDriver.
 */
class PowerMtaDriver extends SmtpDriver
{
    public function key(): string
    {
        return 'powermta';
    }

    public function extraHeaders(DeliveryServer $server): array
    {
        $headers = [];

        $vmta = $server->configValue('virtual_mta');

        if (is_string($vmta) && $vmta !== '') {
            $headers['X-VirtualMTA'] = $vmta;
        }

        $job = $server->configValue('job_id');

        if (is_string($job) && $job !== '') {
            $headers['X-Job'] = $job;
        }

        return $headers;
    }

    public function formSchema(): array
    {
        return [
            ...parent::formSchema(),
            $this->field('config.virtual_mta', 'text', ['help' => 'sending.help.virtual_mta']),
            $this->field('config.job_id', 'text', ['help' => 'sending.help.job_id']),
        ];
    }
}
