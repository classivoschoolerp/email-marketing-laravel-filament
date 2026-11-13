<?php

declare(strict_types=1);

namespace App\Services\Blacklist;

use App\Models\Subscriber;
use App\Services\Settings\SettingsReader;

/**
 * Pre-send blacklist check (§3.17 enforcement, WP-16). Called from exactly
 * one place — the clearly-marked guard in SendToSubscriberJob — after the
 * delivery-log claim, so a blocked address leaves a durable `skipped` log
 * row. Applies only when BlacklistSettings apply_at ∈ send|both.
 */
final class BlacklistSendGuard
{
    public function __construct(private readonly BlacklistMatcher $matcher) {}

    public function blocked(Subscriber $subscriber): bool
    {
        if (! SettingsReader::blacklist()->appliesAtSend()) {
            return false;
        }

        return $this->matcher->matches($subscriber->email, $subscriber->customer_id);
    }
}
