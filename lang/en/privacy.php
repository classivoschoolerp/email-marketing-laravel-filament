<?php

declare(strict_types=1);

// WP-22 — public-facing compliance & privacy strings (spec §4.5, §3.25.16).
// Operator-facing setting labels live in lang/en/settings.php.
return [

    'navigation_group' => 'Privacy & Compliance',

    'cookies' => [
        'default_text' => 'We use cookies to keep this site working and to measure how our emails and pages perform.',
        'accept' => 'Accept',
        'decline' => 'Decline',
        'policy_link' => 'Cookie policy',
        'aria_label' => 'Cookie consent',
    ],

    'legal' => [
        'dpa_title' => 'Data processing agreement',
        'dpa_missing' => 'No data processing agreement has been published yet.',
        'sub_processors_title' => 'Sub-processors',
        'sub_processors_missing' => 'No sub-processors have been published yet.',
        'sub_processors_intro' => 'The third parties below may process personal data on our behalf.',
    ],

    'export' => [
        'title' => 'Download my data',
        'link' => 'Download a copy of my data',
        'description' => 'Download everything stored about this subscription as a JSON file (GDPR portability).',
        'disabled' => 'Data downloads are not enabled on this installation.',
    ],

    'erasure' => [
        'title' => 'Erase my data',
        'link' => 'Request erasure of my data',
        'intro' => 'Enter your email address and we will remove your personal data from every list on this platform. We will send you one confirmation email first — nothing is erased until you click the link in it.',
        'submit' => 'Request erasure',
        'sent' => 'Check your inbox — we sent you a link to confirm this request.',
        'disabled' => 'The data erasure workflow is not enabled on this installation.',
        'confirmed_title' => 'Erasure request confirmed',
        'confirmed_body' => 'Your erasure request for :email has been confirmed. Your data will be removed shortly.',
        'email_label' => 'Email address',

        'statuses' => [
            'pending' => 'Pending confirmation',
            'confirmed' => 'Confirmed — awaiting review',
            'completed' => 'Completed',
            'rejected' => 'Rejected',
        ],

        'mail' => [
            'subject' => 'Confirm your data erasure request',
            'line_1' => 'We received a request to erase your personal data from this platform.',
            'line_2' => 'If this was you, confirm it with the button below. This link expires in :hours hours.',
            'action' => 'Confirm erasure request',
            'line_3' => 'If you did not make this request you can safely ignore this email — nothing will be erased.',
        ],

        'admin' => [
            'navigation_label' => 'Erasure requests',
            'model_label' => 'Erasure request',
            'plural_label' => 'Erasure requests',
            'email' => 'Email',
            'status' => 'Status',
            'ip' => 'IP',
            'erased_count' => 'Subscribers erased',
            'requested_at' => 'Requested',
            'confirmed_at' => 'Confirmed',
            'processed_at' => 'Processed',
            'process' => 'Erase now',
            'reject' => 'Reject',
            'processed' => 'Erasure queued for every matching subscriber.',
            'rejected' => 'Erasure request rejected.',
            'workflow_on' => 'The public right-to-erasure workflow is ENABLED (§3.25.16).',
            'workflow_off' => 'The public right-to-erasure workflow is DISABLED — no new requests can be created.',
        ],
    ],

    'consent_events' => [
        'granted' => 'Granted',
        'withdrawn' => 'Withdrawn',
        'none' => 'Not set',
        'navigation_label' => 'Consent audit trail',
        'model_label' => 'Consent change',
        'plural_label' => 'Consent changes',
        'subscriber' => 'Subscriber',
        'field' => 'Consent field',
        'old_value' => 'Before',
        'new_value' => 'After',
        'ip' => 'IP',
        'user_agent' => 'User agent',
        'source' => 'Source',
        'created_at' => 'Changed at',
        'description' => 'Append-only record of every consent grant and withdrawal (spec §4.5). Retention follows the audit-log window in the Security settings.',

        'sources' => [
            'public' => 'Public page',
            'customer' => 'Customer panel',
            'admin' => 'Admin panel',
            'api' => 'API',
            'system' => 'System',
        ],
    ],

];
