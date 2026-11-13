<?php

declare(strict_types=1);

namespace App\Mail\Lists;

/**
 * The post-confirmation welcome email (spec §1.5 F-1.5-005): rendered from
 * the `welcome-email` list page type. Queued on the transactional queue.
 */
class WelcomeMail extends ListPageEmail {}
