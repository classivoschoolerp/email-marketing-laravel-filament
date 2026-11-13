<?php

declare(strict_types=1);

return [

    'status' => [
        'active' => 'Active',
        'pending_confirm' => 'Pending confirmation',
        'pending_approve' => 'Pending approval',
        'blocked' => 'Blocked',
        'inactive' => 'Inactive',
    ],

    // WP-03 — shown inside the customer panel during an admin impersonation
    // session (spec §3.4 F-3.4-006).
    'impersonation' => [
        'banner' => 'You are logged in as :name (admin impersonation).',
        'return' => 'Return to admin',
    ],
];
