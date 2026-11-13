<?php

declare(strict_types=1);

namespace App\Support\Queue;

/**
 * Named queues per spec §0.4. Workers are started per priority tier — see
 * docs/deploy-mysql.md for the Supervisor layout.
 */
final class Queues
{
    public const CAMPAIGNS_HIGH = 'campaigns-high';

    public const CAMPAIGNS_SEND = 'campaigns-send';

    public const TRANSACTIONAL = 'transactional';

    public const AUTOMATION = 'automation';

    public const IMPORTS = 'imports';

    public const EXPORTS = 'exports';

    public const BOUNCES = 'bounces';

    public const WEBHOOKS = 'webhooks';

    public const MAINTENANCE = 'maintenance';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::CAMPAIGNS_HIGH,
            self::CAMPAIGNS_SEND,
            self::TRANSACTIONAL,
            self::AUTOMATION,
            self::IMPORTS,
            self::EXPORTS,
            self::BOUNCES,
            self::WEBHOOKS,
            self::MAINTENANCE,
        ];
    }
}
