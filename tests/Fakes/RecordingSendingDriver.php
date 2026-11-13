<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Models\DeliveryServer;
use App\Sending\Message;
use App\Sending\SendingDriver;
use App\Sending\SendResult;

/**
 * Recording fake driver for the validate-server flow and registry tests:
 * captures every (message, server) pair and returns a scripted result.
 */
final class RecordingSendingDriver implements SendingDriver
{
    /** @var list<array{message: Message, server: DeliveryServer}> */
    public array $sent = [];

    public function __construct(
        private readonly string $key = 'fake',
        public SendResult $result = new SendResult(ok: true, messageId: 'fake-id'),
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'Recording fake';
    }

    public function send(Message $message, DeliveryServer $server): SendResult
    {
        $this->sent[] = ['message' => $message, 'server' => $server];

        return $this->result;
    }

    public function validateConfig(DeliveryServer $server): array
    {
        return [];
    }

    public function capabilities(): array
    {
        return ['transport' => 'fake'];
    }

    public function formSchema(): array
    {
        return [];
    }
}
