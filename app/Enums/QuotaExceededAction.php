<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What happens when a delivery server exhausts a quota window
 * (spec §2.9.2 F-2.9.2-030: pause after quota vs move to next server).
 */
enum QuotaExceededAction: string
{
    /** Hold the campaign until the window resets. */
    case Pause = 'pause';

    /** Skip this server and let the picker move on to the next one. */
    case MoveToNext = 'next';

    public function label(): string
    {
        return match ($this) {
            self::Pause => __('sending.quota_action.pause'),
            self::MoveToNext => __('sending.quota_action.next'),
        };
    }
}
