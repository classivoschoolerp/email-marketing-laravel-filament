<?php

declare(strict_types=1);

namespace App\Mail\Lists;

/**
 * A one-off email sent to a single subscriber from the profile page
 * (spec §2.2.5 F-2.2.5-010 "send single email").
 *
 * Reuses the §1.5 lifecycle mailable wholesale: subject and body are rendered
 * (merge tags substituted, CAN-SPAM footer appended) before queueing, the
 * from/reply-to come from the list, and it rides the transactional queue —
 * exactly like the confirmation and welcome emails.
 */
class SingleSubscriberMail extends ListPageEmail {}
