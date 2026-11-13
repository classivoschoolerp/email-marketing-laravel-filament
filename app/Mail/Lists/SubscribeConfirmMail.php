<?php

declare(strict_types=1);

namespace App\Mail\Lists;

/**
 * The double opt-in confirmation email (spec §1.5 F-1.5-004): rendered from
 * the `subscribe-confirm-email` list page type with the signed [CONFIRM_URL]
 * substituted. Queued on the transactional queue.
 */
class SubscribeConfirmMail extends ListPageEmail {}
