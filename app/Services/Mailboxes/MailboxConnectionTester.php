<?php

declare(strict_types=1);

namespace App\Services\Mailboxes;

use Throwable;

/**
 * "Validate connection" button backend for bounce/FBL servers (spec §2.9.4
 * F-2.9.4-005): opens the mailbox, lists nothing, disconnects. A single
 * short-lived connection attempt — the actual mail PROCESSING always runs in
 * the queued/cron pipeline, never in the UI request.
 */
class MailboxConnectionTester
{
    public function __construct(private readonly MailboxClientFactory $factory) {}

    /** @return array{ok: bool, error: string|null} */
    public function test(MailboxConnection $connection): array
    {
        $client = $this->factory->make($connection->protocol);

        try {
            $client->connect($connection);
            $client->disconnect();

            return ['ok' => true, 'error' => null];
        } catch (Throwable $exception) {
            try {
                $client->disconnect();
            } catch (Throwable) {
                // Already broken — nothing to clean up.
            }

            return ['ok' => false, 'error' => $exception->getMessage()];
        }
    }
}
