<?php

declare(strict_types=1);

return [

    'navigation_group' => 'Sending Infrastructure',

    'sections' => [
        'admin' => 'Administration',
        'admin_description' => 'Assignment, locking and global rotation controls (admin only).',
        'mailbox' => 'Mailbox connection',
        'processing' => 'Processing',
        'bounce_actions' => 'Bounce actions',
    ],

    'fields' => [
        'name' => 'Name',
        'customer' => 'Customer',
        'customer_groups' => 'Customer groups',
        'locked' => 'Locked',
        'priority' => 'Priority',
        'hostname' => 'Hostname',
        'port' => 'Port',
        'protocol' => 'Protocol',
        'encryption' => 'Encryption',
        'username' => 'Username',
        'password' => 'Password',
        'validate_cert' => 'Validate SSL/TLS certificate',
        'delete_after_processing' => 'Delete messages after processing',
        'max_per_batch' => 'Max messages per batch',
        'search_charset' => 'Search charset',
        'status' => 'Status',
        'last_run' => 'Last run',
        'hard_bounce_action' => 'Action on hard bounce',
        'soft_bounce_threshold' => 'Soft bounce threshold',
        'soft_bounce_action' => 'Action after N soft bounces',
    ],

    'help' => [
        'customer_assignment' => 'Assign to a specific customer, or leave empty for a system server.',
        'customer_groups' => 'System servers only: restrict to these customer groups. Empty = every group.',
        'locked' => 'Locked records are read-only for the owning customer.',
        'priority' => 'Rotation preference: lower values are preferred; weight (probability) balances within a priority tier.',
        'mailbox_customer' => 'Assign to a specific customer, or leave empty for a global server.',
        'validate_cert' => 'Disable only for servers with self-signed certificates.',
        'delete_after_processing' => 'Remove messages from the mailbox after they have been processed.',
        'max_per_batch' => 'Maximum number of messages processed per run.',
        'search_charset' => 'Charset used for IMAP server-side searches.',
        'hard_bounce_action' => 'The subscriber is always marked bounced; this action goes further.',
        'soft_bounce_threshold' => 'Apply the action once a subscriber accumulates this many bounces. 0 disables.',
        'sending_domain_link' => 'Linking a verified sending domain copies its DKIM signing key onto this server.',
        'sending_domain_name' => 'The domain your campaigns send from, e.g. mail.example.com.',
        'tracking_domain_name' => 'The domain used for open/click tracking links, e.g. track.example.com.',
        'domain_customer' => 'Assign to a specific customer, or leave empty for a system domain.',
        'dkim_selector' => 'DNS selector for the DKIM key, published at selector._domainkey.domain.',
        'cname_hint' => 'Point a CNAME record for your domain at :target.',
        'sync_dkim' => 'Copies this domain\'s DKIM selector and private key onto every linked delivery server.',
    ],

    'labels' => [
        'system' => 'System',
    ],

    'filters' => [
        'system_only' => 'System servers',
    ],

    'actions' => [
        'usage_logs' => 'Usage logs',
        'logs' => 'Logs',
        'test_connection' => 'Validate connection',
        'process_now' => 'Process now',
        'verify' => 'Verify DNS',
        'verify_all' => 'Verify all',
        'sync_dkim' => 'Sync DKIM to servers',
    ],

    'notifications' => [
        'connection_ok' => 'Mailbox connection succeeded.',
        'connection_failed' => 'Mailbox connection failed.',
        'processing_queued' => 'Processing has been queued.',
        'domain_verified' => 'Domain verified — all required records passed.',
        'domain_failed' => 'Verification failed — one or more required records are missing.',
        'verify_all_queued' => 'Verification of all domains has been queued.',
        'dkim_synced' => 'DKIM keys synced to :count linked server(s).',
    ],

    'mailbox' => [
        'protocol' => [
            'pop3' => 'POP3',
            'imap' => 'IMAP',
        ],
        'encryption' => [
            'none' => 'None',
            'ssl' => 'SSL/TLS',
            'tls' => 'STARTTLS',
        ],
    ],

    'server_status' => [
        'active' => 'Active',
        'inactive' => 'Inactive',
    ],

    'bounce_type' => [
        'hard' => 'Hard',
        'soft' => 'Soft',
        'internal' => 'Internal',
    ],

    'bounce_action' => [
        'mark_bounced' => 'Mark bounced only',
        'unsubscribe' => 'Unsubscribe',
        'blacklist' => 'Blacklist',
        'delete' => 'Delete subscriber',
    ],

    'domain_status' => [
        'pending' => 'Pending',
        'verified' => 'Verified',
        'failed' => 'Failed',
    ],

    'ssl_status' => [
        'none' => 'No SSL',
        'pending' => 'SSL pending',
        'active' => 'SSL active',
    ],

    'bounce' => [
        'label' => 'Bounce server',
        'plural_label' => 'Bounce servers',
        'navigation_label' => 'Bounce servers',
    ],

    'fbl' => [
        'label' => 'Feedback loop server',
        'plural_label' => 'Feedback loop servers',
        'navigation_label' => 'FBL servers',
    ],

    'rules' => [
        'label' => 'Bounce rule',
        'plural_label' => 'Bounce rules',
        'navigation_label' => 'Bounce rules',
        'section' => 'Classification rule',
        'type' => 'Bounce type',
        'pattern' => 'Pattern (regex)',
        'pattern_help' => 'Case-insensitive regular expression without delimiters, matched against the bounce status, diagnostic code, subject and body.',
        'pattern_invalid' => 'The pattern is not a valid regular expression.',
        'enabled' => 'Enabled',
        'sort_order' => 'Evaluation order',
        'sort_order_help' => 'Rules run lowest-first; the first match wins. Defaults: internal 10-19, hard 20-39, soft 40-59.',
    ],

    'domains' => [
        'sending_label' => 'Sending domain',
        'sending_plural' => 'Sending domains',
        'tracking_label' => 'Tracking domain',
        'tracking_plural' => 'Tracking domains',
        'domain' => 'Domain',
        'overview' => 'Verification status',
        'records' => 'Required DNS records',
        'records_description' => 'Publish these records at your DNS provider, then click "Verify DNS". Every value can be copied with one click.',
        'record_value' => 'Record value',
        'dkim_record' => 'DKIM record (:type)',
        'spf_record' => 'SPF record (:type)',
        'dmarc_record' => 'DMARC record (:type)',
        'mx_record' => 'MX record (optional)',
        'mx_optional' => 'Optional — only needed when the domain should also receive mail.',
        'dkim' => 'DKIM',
        'spf' => 'SPF',
        'dmarc' => 'DMARC',
        'dkim_verified' => 'DKIM record',
        'spf_verified' => 'SPF record',
        'dmarc_verified' => 'DMARC record',
        'mx_verified' => 'MX record (optional)',
        'dkim_selector' => 'DKIM selector',
        'last_checked' => 'Last checked',
        'cname_target' => 'CNAME target',
        'verified' => 'Verified',
        'ssl_status' => 'SSL',
    ],

    'usage' => [
        'title' => 'Usage logs',
        'heading' => 'Usage logs — :name',
        'hourly_bar' => 'Hour: :bar (resets in :seconds s)',
        'daily_bar' => 'Day: :bar',
        'monthly_bar' => 'Month: :bar',
        'delivery_log_totals' => 'Delivery log: :total sends, :failed bounced/failed',
        'columns' => [
            'date' => 'Date',
            'sent' => 'Sent',
            'failed' => 'Errors',
        ],
    ],

    'logs' => [
        'bounce_title' => 'Processing log',
        'bounce_heading' => 'Bounce log — :name',
        'fbl_title' => 'Complaint log',
        'fbl_heading' => 'Complaint log — :name',
        'last_run' => 'Last run: :time',
        'columns' => [
            'received' => 'Processed at',
            'email' => 'Email',
            'type' => 'Type',
            'feedback_type' => 'Feedback type',
            'campaign' => 'Campaign',
            'subscriber' => 'Subscriber',
            'message' => 'Message excerpt',
        ],
    ],

    'blacklist' => [
        'bounce_reason' => 'Hard bounce',
        'complaint_reason' => 'Spam complaint (FBL)',
    ],

    'cron' => [
        'disabled' => 'Job :job is disabled in the cron settings — skipping.',
        'processed' => 'Processed :count item(s).',
        'server_failed' => 'Server :name failed: :error',
    ],

    'errors' => [
        'cannot_create_domain' => 'You are not allowed to add more domains (permission or quota).',
    ],

    /*
    | Email box monitors (WP-33, spec §2.9.6).
    */
    'monitors' => [
        'label' => 'Email box monitor',
        'plural_label' => 'Email box monitors',
        'navigation_label' => 'Email box monitors',
        'sections' => [
            'conditions' => 'Conditions',
        ],
        'fields' => [
            'field' => 'Match on',
            'operator' => 'Operator',
            'value' => 'Value',
            'action' => 'Action',
            'target_list' => 'Move to list',
            'notify_email' => 'Notify address',
            'priority' => 'Priority',
            'enabled' => 'Enabled',
            'conditions_count' => 'Conditions',
        ],
        'help' => [
            'value' => 'Plain text for contains; a full PCRE pattern with delimiters for regex, e.g. /out of office/i.',
            'priority' => 'Lower numbers are evaluated first. The first matching condition applies its action and stops.',
            'target_list' => 'Only used by the move-to-list action; the list must belong to the same customer.',
            'notify_email' => 'Only used by the notify-admin action. Empty falls back to the admin notification addresses.',
        ],
        'field' => [
            'subject' => 'Subject',
            'body' => 'Body',
            'from' => 'From',
        ],
        'operator' => [
            'contains' => 'Contains',
            'not_contains' => 'Does not contain',
            'regex' => 'Matches regex',
        ],
        'action' => [
            'unsubscribe' => 'Unsubscribe subscriber',
            'blacklist' => 'Blacklist',
            'delete_subscriber' => 'Delete subscriber',
            'move_to_list' => 'Move to list',
            'notify_admin' => 'Notify admin',
        ],
        'results' => [
            'no_match' => 'No condition matched.',
            'no_subscriber' => 'No subscriber found for the sender address.',
            'no_target_list' => 'The target list no longer exists.',
            'cross_tenant_list' => 'The target list belongs to another customer — skipped.',
            'no_recipient' => 'No notification recipient configured.',
            'unsubscribed' => 'Unsubscribed :email.',
            'blacklisted' => 'Blacklisted :email.',
            'deleted' => 'Deleted subscriber :email.',
            'moved' => 'Moved :email to list :list.',
            'notified' => 'Notified :count recipient(s).',
        ],
        'blacklist_reason' => 'Email box monitor',
        'alert' => [
            'subject' => 'Email box monitor :monitor matched a message',
            'body' => 'Monitor ":monitor" matched a message from :from with the subject ":subject".',
        ],
        'logs' => [
            'title' => 'Processing log',
            'heading' => 'Processing log — :name',
            'columns' => [
                'received' => 'Received',
                'from' => 'From',
                'subject' => 'Subject',
                'matched' => 'Matched',
                'action' => 'Action',
                'result' => 'Result',
                'subscriber' => 'Subscriber',
            ],
        ],
        'errors' => [
            'invalid_regex' => 'This is not a valid regular expression.',
        ],
    ],

];
