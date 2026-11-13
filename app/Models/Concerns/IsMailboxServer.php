<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\InfraServerStatus;
use App\Enums\MailboxEncryption;
use App\Enums\MailboxProtocol;
use App\Models\Customer;
use App\Services\Mailboxes\MailboxConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared behaviour of the POP3/IMAP mailbox server models (bounce + FBL,
 * spec §2.9.4/§2.9.5): common casts, the connection DTO used by the mailbox
 * clients, and the system-row/ownership helpers mirroring DeliveryServer.
 *
 * @mixin Model
 */
trait IsMailboxServer
{
    /** @return array<string, string> */
    protected function mailboxCasts(): array
    {
        return [
            'status' => InfraServerStatus::class,
            'protocol' => MailboxProtocol::class,
            'encryption' => MailboxEncryption::class,
            'password' => 'encrypted',
            'validate_cert' => 'boolean',
            'delete_after_processing' => 'boolean',
            'locked' => 'boolean',
            'max_per_batch' => 'integer',
            'port' => 'integer',
            'last_run_at' => 'datetime',
        ];
    }

    public function connection(): MailboxConnection
    {
        return new MailboxConnection(
            hostname: (string) $this->hostname,
            port: (int) $this->port,
            protocol: $this->protocol,
            username: (string) $this->username,
            password: (string) ($this->password ?? ''),
            encryption: $this->encryption,
            validateCert: (bool) $this->validate_cert,
            charset: (string) ($this->search_charset ?? 'UTF-8'),
        );
    }

    /** Global/admin row (customer_id NULL — §3.13.2/§3.13.3)? */
    public function isSystem(): bool
    {
        return $this->customer_id === null;
    }

    /** A customer may edit only their own, unlocked rows. */
    public function isEditableByCustomer(Customer $customer): bool
    {
        return ! $this->isSystem()
            && (int) $this->customer_id === (int) $customer->id
            && ! $this->locked;
    }

    public function isActive(): bool
    {
        return $this->status === InfraServerStatus::Active;
    }
}
