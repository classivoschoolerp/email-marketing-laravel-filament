<?php

declare(strict_types=1);

return [

    'resource' => [
        'label' => 'Delivery server',
        'plural_label' => 'Delivery servers',
        'navigation_label' => 'Delivery servers',
        'navigation_group' => 'Sending',
    ],

    'sections' => [
        'general' => 'General',
        'driver_config' => 'Server configuration',
        'driver_config_description' => 'Settings specific to the selected server type.',
        'sender' => 'Sender identity',
        'quotas' => 'Rotation & quotas',
        'connection' => 'Connection',
        'headers' => 'Custom headers',
        'associations' => 'Associations',
        'assignment' => 'Restrict to lists / campaigns',
        'dkim' => 'DKIM signing',
    ],

    'fields' => [
        'name' => 'Name',
        'type' => 'Type',
        'hostname' => 'Hostname',
        'port' => 'Port',
        'protocol' => 'Protocol',
        'username' => 'Username',
        'password' => 'Password',
        'api_key' => 'API key',
        'region' => 'Region',
        'from_email' => 'From email',
        'from_name' => 'From name',
        'reply_to_email' => 'Reply-to email',
        'force_from' => 'Force FROM address',
        'force_reply_to' => 'Force REPLY-TO address',
        'probability' => 'Probability (weight)',
        'hourly_quota' => 'Hourly quota',
        'daily_quota' => 'Daily quota',
        'monthly_quota' => 'Monthly quota',
        'quota_exceeded_action' => 'When quota is exceeded',
        'max_connection_messages' => 'Max messages per connection',
        'connection_timeout' => 'Connection timeout (s)',
        'keep_alive' => 'Keep connection alive',
        'custom_headers' => 'Additional headers',
        'header_name' => 'Header',
        'header_value' => 'Value',
        'bounce_server' => 'Bounce server',
        'tracking_domain' => 'Tracking domain',
        'sending_domain' => 'Sending domain',
        'warmup_plan' => 'Warmup plan',
        'assigned_lists' => 'Only these lists',
        'assigned_campaigns' => 'Only these campaigns',
        'dkim_signing_enabled' => 'Sign with DKIM',
        'dkim_selector' => 'DKIM selector',
        'dkim_private_key' => 'DKIM private key',
        'status' => 'Status',
        'locked' => 'Locked',
        'last_used' => 'Last used',
        'hourly_usage' => 'Hourly usage',
        'daily_usage' => 'Daily usage',
        'access_key_id' => 'Access key ID',
        'secret_access_key' => 'Secret access key',
        'server_token' => 'Server token',
        'server_api_key' => 'Server API key',
        'mailgun_domain' => 'Sending domain',
        'mailjet_api_key' => 'API key',
        'mailjet_api_secret' => 'API secret',
        'postal_host' => 'Postal server URL',
        'blastengine_user' => 'Blastengine login ID',
        'gmail_address' => 'Gmail / Workspace address',
        'client_secret' => 'OAuth client secret',
        'refresh_token' => 'OAuth refresh token',
        'config_domain' => 'Sending domain',
        'config_client_id' => 'OAuth client ID',
        'config_binary_path' => 'Binary path',
        'config_mode' => 'Pipe mode',
        'config_pickup_path' => 'Pickup directory',
        'config_virtual_mta' => 'Virtual MTA (X-VirtualMTA)',
        'config_job_id' => 'Job ID (X-Job)',
        'config_params' => 'Additional mail() parameters',
    ],

    'protocol' => [
        'none' => 'None',
        'tcp' => 'TCP (plain)',
        'ssl' => 'SSL (implicit TLS)',
        'tls' => 'TLS (STARTTLS)',
    ],

    'quota_action' => [
        'pause' => 'Pause sending until the window resets',
        'next' => 'Move on to the next server',
    ],

    'mode' => [
        'bs' => 'SMTP over stdio (-bs)',
        't' => 'Pipe message (-t)',
    ],

    'regions' => [
        'us' => 'US',
        'eu' => 'EU',
    ],

    'status' => [
        'active' => 'Active',
        'inactive' => 'Inactive',
        'in_progress' => 'In progress',
        'disabled' => 'Disabled',
        'hourly_quota_reached' => 'Hourly quota reached',
    ],

    'types' => [
        'smtp' => 'SMTP (generic)',
        'smtp-amazon-ses' => 'SMTP — Amazon SES',
        'amazon-ses-api' => 'Amazon SES Web API',
        'sendgrid-api' => 'SendGrid Web API',
        'mailgun-api' => 'Mailgun Web API',
        'sparkpost-api' => 'SparkPost Web API',
        'postmark-api' => 'Postmark Web API',
        'elastic-email-api' => 'Elastic Email Web API',
        'mailjet-api' => 'Mailjet Web API',
        'brevo-api' => 'Brevo / Sendinblue Web API',
        'postal' => 'Postal',
        'powermta' => 'PowerMTA (SMTP + VMTA)',
        'php-mail' => 'PHP mail()',
        'pickup-directory' => 'Pickup directory',
        'sendmail' => 'Local MTA (sendmail)',
        'gmail-oauth' => 'Gmail / Google Workspace (OAuth2)',
        'blastengine' => 'Blastengine',
        'postfix' => 'Postfix',
        'exim' => 'Exim',
        'qmail' => 'Qmail',
        'resend-api' => 'Resend Web API',
        'loops-api' => 'Loops Web API',
        'mailtrap-api' => 'Mailtrap Web API',
    ],

    'actions' => [
        'validate' => 'Validate',
        'validate_description' => 'Sends a confirmation email through this server. Click the link in that email to activate the server.',
        'enable' => 'Enable',
        'disable' => 'Disable',
        'copy' => 'Copy',
    ],

    'notifications' => [
        'validation_sent' => 'Confirmation email sent — click the link it contains to activate the server.',
        'validation_failed' => 'The confirmation email could not be sent.',
        'enabled' => 'Server enabled.',
        'disabled' => 'Server disabled.',
        'must_validate' => 'This server must be validated before it can be enabled.',
        'copied' => 'Server copied.',
    ],

    'labels' => [
        'system_server' => 'Provided by the platform',
        'unlimited' => 'unlimited',
        'never' => 'never',
        'copy_suffix' => '(copy)',
    ],

    'validation_email' => [
        'subject' => 'Confirm your delivery server ":name"',
        'intro' => 'This message confirms that the delivery server ":name" can send email. Click the link below to activate it.',
        'action' => 'Activate this delivery server',
    ],

    'activation' => [
        'title' => 'Delivery server activated',
        'success' => 'The delivery server ":name" is now active.',
        'invalid_token' => 'This activation link is invalid or has already been used.',
    ],

    'validation' => [
        'hostname_required' => 'A hostname is required for this server type.',
        'port_required' => 'A valid port (1-65535) is required.',
        'username_required' => 'A username is required.',
        'password_required' => 'A password is required.',
        'region_required' => 'A region is required.',
        'api_key_required' => 'An API key is required.',
        'access_key_required' => 'An AWS access key ID is required.',
        'secret_key_required' => 'An AWS secret access key is required.',
        'domain_required' => 'A sending domain is required.',
        'mailjet_key_required' => 'The Mailjet API key is required.',
        'mailjet_secret_required' => 'The Mailjet API secret is required.',
        'blastengine_user_required' => 'The Blastengine login ID is required.',
        'gmail_address_required' => 'The Gmail / Workspace address is required.',
        'client_id_required' => 'The OAuth client ID is required.',
        'client_secret_required' => 'The OAuth client secret is required.',
        'loops_transactional_id_required' => 'The Loops transactional template ID is required.',
        'refresh_token_required' => 'The OAuth refresh token is required.',
        'binary_path_required' => 'The MTA binary path is required.',
        'pickup_path_required' => 'The pickup directory path is required.',
    ],

    'errors' => [
        'cannot_create' => 'You are not allowed to add more delivery servers.',
        'type_not_allowed' => 'This server type is not enabled for your account.',
        'assignment_foreign' => 'A selected list or campaign does not belong to this account.',
        'validation_recipient_missing' => 'Set a From email (or provide a recipient) before validating this server.',
        'php_mail_failed' => 'PHP mail() reported a failure handing the message to the local mailer.',
        'pickup_dir_unavailable' => 'The pickup directory :dir does not exist and could not be created.',
        'pickup_write_failed' => 'The message could not be written to the pickup directory :dir.',
    ],

    // WP-13 sending engine + §0.6 cron jobs
    // WP-46 §2.3.4 F-2.3.4-011 — Send Time Optimization.
    'send_time' => [
        'queued' => 'Queued send-time profile rebuilds for :count customer(s).',
        'list_needs_customer' => '--list requires --customer: a list id is only unique inside a tenant.',
    ],

    'cron' => [
        'disabled' => 'Cron job :job is disabled in the scheduler settings — skipped.',
        'finished' => 'Cron job :job finished (:processed processed).',
        'failed' => 'Cron job :job failed: :error',
        'automations_pending' => 'Automations tables are not installed yet (WP-21) — nothing to tick.',
        'license_status' => 'License status: :status',
    ],

    'log' => [
        'subscriber_ineligible' => 'Skipped: subscriber is no longer eligible for this campaign.',
        'no_customer' => 'Failed: the campaign has no owning customer.',
        'no_server' => 'Failed: no eligible delivery server was available.',
        'spam_blocked' => 'Blocked by the spam pre-check (score :score, threshold :required).',
        // WP-36 §6.1 F-6.1-012: a `campaign.pre_send` hook listener cancelled
        // this recipient without supplying its own reason.
        'plugin_cancelled' => 'Skipped: cancelled by a plugin on the pre-send hook.',
    ],

    'auto_pause' => [
        'bounce_threshold' => 'Auto-paused: bounce rate reached the :limit% threshold.',
        'complaint_threshold' => 'Auto-paused: complaint rate reached the :limit% threshold.',
    ],

    'finish_mail' => [
        'subject' => 'Campaign ":name" has finished sending',
        'body' => 'Your campaign ":name" finished sending: :sent delivered, :failed failed, :total total recipients.',
    ],

    'heartbeat' => [
        'subject' => 'Scheduler heartbeat is stale',
        'body' => 'The cron scheduler heartbeat has not been recorded recently (last seen: :last). Campaign sending and background jobs may be stopped — check the cron runner on the server.',
        'never' => 'never',
    ],

    'backups' => [
        'unsupported_driver' => 'Database driver ":driver" is not supported by backups:run.',
        'sqlite_unavailable' => 'SQLite database file ":path" cannot be backed up.',
        'write_failed' => 'Could not write the backup file :path.',
        'dump_failed' => 'mysqldump failed: :error',
    ],

    'recurring' => [
        'spawn_failed' => 'Could not spawn the recurring run for ":campaign": :error',
    ],

    /*
    | Delivery server groups / pools + IP pools (WP-33, spec §3.13.1).
    */
    'groups' => [
        'label' => 'Server pool',
        'plural_label' => 'Server pools',
        'navigation_label' => 'Server pools',
        'sections' => [
            'pool' => 'Pool',
            'members' => 'Member servers',
            'ips' => 'Dedicated IPs',
        ],
        'type' => [
            'pool' => 'Sending pool',
            'ip_pool' => 'IP pool',
        ],
        'fields' => [
            'name' => 'Name',
            'description' => 'Description',
            'customer' => 'Customer',
            'type' => 'Pool type',
            'enabled' => 'Enabled',
            'is_default' => 'Default pool',
            'servers' => 'Delivery servers',
            'servers_count' => 'Servers',
            'ips_count' => 'IPs',
            'ip_address' => 'IP address',
            'ptr_record' => 'PTR / reverse DNS',
            'dedicated' => 'Dedicated',
            'server' => 'Bound delivery server',
            'notes' => 'Notes',
        ],
        'help' => [
            'customer' => 'Assign to a specific customer, or leave empty for a system pool.',
            'is_default' => "When set, this customer's sends are confined to this pool unless a pool is named explicitly. Only one default per customer.",
            'type' => 'An IP pool additionally tracks the dedicated addresses its member servers send from.',
            'ip_address' => 'The dedicated address this server sends from — used for reverse-DNS and blacklist monitoring.',
        ],
        'actions' => [
            'ips' => 'Manage IPs',
        ],
        'errors' => [
            'invalid_ip' => 'Enter a valid IPv4 or IPv6 address.',
        ],
    ],

    'help' => [
        'assignment' => 'Limit this server to specific lists and/or campaigns. Leave both fields empty to let it serve everything you send.',
        'assignment_empty' => 'Empty = no restriction.',
        'force_from' => 'Rewrite the FROM of every message sent through this server.',
        'probability' => 'Relative weight for load balancing across your active servers.',
        'zero_unlimited' => '0 = unlimited.',
        'associations_pending' => 'Bounce servers, tracking domains and sending domains get management screens in a later milestone; IDs can already be linked here.',
        'warmup_pending' => 'While a warmup plan is active on this server, its per-day allowance caps sending — see Warmup plans.',
        'loops_transactional_id' => 'Loops sends a template you authored in Loops, not the HTML built here. Your campaign subject, HTML and text are passed as the data variables "subject", "html" and "text" — the Loops template must output them.',
        'mailtrap_sandbox_inbox' => 'Leave empty to deliver for real. Setting an inbox ID switches this server to the Mailtrap sandbox, which captures mail instead of delivering it.',
        'ses_smtp_username' => 'SES SMTP credentials (not your IAM keys).',
        'ses_smtp_password' => 'SES SMTP password derived from the secret key.',
        'virtual_mta' => 'Stamped as X-VirtualMTA so PowerMTA picks the right virtual MTA.',
        'job_id' => 'Optional X-Job header for PowerMTA accounting.',
        'binary_path' => 'Absolute path of the MTA submission binary on the host.',
        'sendmail_mode' => '-bs speaks SMTP to the binary; -t pipes the message and lets the MTA read recipients from headers.',
        'pickup_path' => 'Directory where .eml files are spooled for the local MTA.',
        'php_mail_params' => 'Optional additional_params for mail(), e.g. "-fbounce@example.com".',
        'refresh_token' => 'Obtained from the Google OAuth consent flow for this mailbox.',
    ],
];
