<?php

declare(strict_types=1);

namespace App\Sending;

use App\Models\DeliveryServer;

/**
 * Sending driver contract (spec §2.9.2 F-2.9.2-020, docs/ARCHITECTURE.md rule 2). New
 * vendors implement this interface and register with DriverRegistry — never a
 * core change. Drivers live outside Filament and outside the campaigns
 * module; they receive a transport-agnostic Message plus the DeliveryServer
 * row holding the credentials/config.
 */
interface SendingDriver
{
    /** Stable D-21 driver key (CustomerSettings::SENDING_SERVER_TYPES). */
    public function key(): string;

    /** Human label for pickers. */
    public function label(): string;

    /**
     * Deliver one message through the given server. Implementations must
     * return an honest SendResult and never throw for delivery failures —
     * transient-vs-permanent classification is documented per driver.
     */
    public function send(Message $message, DeliveryServer $server): SendResult;

    /**
     * Validate the server row for this driver.
     *
     * @return array<string, string> attribute => error message; empty = valid
     */
    public function validateConfig(DeliveryServer $server): array;

    /**
     * Driver capability flags, e.g. transport family, attachment support,
     * custom-header support.
     *
     * @return array<string, bool|int|string>
     */
    public function capabilities(): array;

    /**
     * Declarative per-type form fields for the add/edit UI (§2.9.2 "each with
     * its own config form"). Deliberately NOT Filament components — the
     * sending engine stays UI-free (docs/ARCHITECTURE.md rule 1); the resource maps these
     * specs to components.
     *
     * Each spec: [
     *   'name'     => model attribute ("hostname") or config key ("config.binary_path"),
     *   'type'     => 'text'|'password'|'number'|'toggle'|'select',
     *   'label'    => translation key under lang/en/sending.php,
     *   'required' => bool,
     *   'options'  => array<string,string> (selects only),
     *   'default'  => scalar|null,
     *   'help'     => translation key|null,
     * ]
     *
     * @return list<array<string, mixed>>
     */
    public function formSchema(): array;
}
