<?php

declare(strict_types=1);

/*
 * WP-17 — campaign reports (spec §2.3.8) + admin logs & monitoring (§3.27).
 */
return [

    'page' => [
        'title' => 'Report — :name',
        'sending_progress' => 'Sending… :percent% complete — figures refresh automatically.',
    ],

    'stats' => [
        'recipients' => 'Recipients',
        'delivered' => 'Delivered',
        'failed' => 'Failed',
        'skipped' => 'Skipped',
        'bounced' => 'Bounced',
        'bounced_hard' => 'Hard bounces',
        'bounced_soft' => 'Soft bounces',
        'bounced_internal' => 'Internal bounces',
        'bounce_split' => ':hard hard · :soft soft · :internal internal',
        'opens' => 'Opens',
        'unique_opens' => 'Unique opens',
        'open_rate' => 'Open rate',
        'clicks' => 'Clicks',
        'unique_clicks' => 'Unique clicks',
        'click_rate' => 'Click rate',
        'ctor' => 'Click-to-open rate',
        'unsubscribes' => 'Unsubscribes',
        'complaints' => 'Complaints',
        'forwards' => 'Forwards',
        'forwards_pending' => 'Forward tracking is not available yet.',
        'forwarders' => 'Forwarders',
        'forwarders_hint' => ':count forwarder(s)',
        'bounce_rate' => 'Bounce rate',
        'unsubscribe_rate' => 'Unsubscribe rate',
        'unique_total_hint' => 'unique / total',
    ],

    /*
     * WP-35 — §2.3.8 P2 additions (comparison, heat table, print/PDF) and
     * §2.3.11 cross-campaign analytics.
     */

    'comparison' => [
        'title' => 'Compared with',
        'account_average' => 'Account average',
        'previous_campaign' => 'Previous campaign',
        'metric' => 'Metric',
        'this_campaign' => 'This campaign',
        'delta' => 'Difference',
        'none' => 'Not enough sent campaigns yet to compare against.',
        'basis' => 'Average of :count sent campaign(s).',
    ],

    'heat' => [
        'title' => 'Subscriber engagement',
        'subscriber' => 'Subscriber',
        'opens' => 'Opens',
        'clicks' => 'Clicks',
        'score' => 'Engagement',
        'legend' => 'Darker cells mean more activity.',
        'empty' => 'No engagement recorded for this campaign yet.',
        'hour' => 'Hour',
        'day' => 'Day',
    ],

    'print' => [
        'action' => 'Print / PDF',
        'title' => 'Report — :name',
        'generated_at' => 'Generated :date',
        'hint' => 'Use your browser print dialog and choose "Save as PDF".',
    ],

    'cross' => [
        'navigation_label' => 'Campaign comparison',
        'title' => 'Campaign comparison',
        'subheading' => 'Compare sent campaigns side by side and find the patterns.',
        'pick' => 'Campaigns',
        'pick_help' => 'Choose between 2 and :max sent campaigns.',
        'empty' => 'Select at least two sent campaigns to compare.',
        'table' => 'Side-by-side metrics',
        'trend' => 'Trend across campaigns',
        'trend_metric' => 'Metric',
        'subjects' => 'Subject line performance',
        'subjects_best' => 'Best performing',
        'subjects_worst' => 'Worst performing',
        'send_time' => 'Best send day & hour',
        'by_day' => 'By day of week',
        'by_hour' => 'By hour of day',
        'campaign' => 'Campaign',
        'sent_at' => 'Sent',
        'subject' => 'Subject',
        'day' => 'Day',
        'hour' => 'Hour',
        'campaigns' => 'Campaigns',
        'best_day' => 'Best day: :day (:rate% open rate)',
        'best_hour' => 'Best hour: :hour:00 (:rate% open rate)',
        'insufficient' => 'Not enough sent campaigns to draw a conclusion.',
    ],

    'tabs' => [
        'opens' => 'Opens',
        'clicks' => 'Clicks',
        'top_links' => 'Top links',
        'bounces' => 'Bounces',
        'unsubscribes' => 'Unsubscribes',
        'complaints' => 'Complaints',
        'delivery' => 'Delivery logs',
        'geo' => 'Locations',
        'devices' => 'Devices & clients',
        // WP-35 §2.3.8-017 / §2.3.8-003.
        'heat' => 'Engagement',
        'comparison' => 'Comparison',
        // WP-46 §2.15.8 F-2.15.8-008 — the tab LABEL belongs to the report
        // tab list; every word of the panel itself lives in lang/en/ai.php.
        'ai_summary' => 'Plain English',
    ],

    'columns' => [
        'subscriber' => 'Subscriber',
        'campaign' => 'Campaign',
        'customer' => 'Customer',
        'times_opened' => 'Times opened',
        'first_open' => 'First open',
        'last_open' => 'Last open',
        'last_click' => 'Last click',
        'ip' => 'IP',
        'device' => 'Device',
        'url' => 'URL',
        'clicks' => 'Clicks',
        'unique_clickers' => 'Unique clickers',
        'bounce_type' => 'Bounce type',
        'message' => 'Message',
        'message_id' => 'Message ID',
        'feedback_type' => 'Feedback type',
        'status' => 'Status',
        'server' => 'Delivery server',
        'response' => 'Response',
        'retries' => 'Retries',
        'date' => 'Date',
        'reason' => 'Reason',
        'opens' => 'Opens',
        'share' => 'Share',
        'account' => 'Account',
        'user_agent' => 'User agent',
        'location' => 'Location',
        'result' => 'Result',
        'count' => 'Count',
        'country' => 'Country',
    ],

    'charts' => [
        'opens_over_time' => 'Opens over time',
        'clicks_over_time' => 'Clicks over time',
        'hourly' => 'Hourly (48h)',
        'daily' => 'Daily',
    ],

    'breakdowns' => [
        'device' => 'Devices',
        'os' => 'Operating systems',
        'browser' => 'Browsers',
        'client' => 'Email clients',
        'unknown' => 'Unknown',
    ],

    'geo' => [
        'country' => 'Country',
        'region' => 'Region',
        'city' => 'City',
        'hint' => 'Locations come from a GeoIP lookup on open events. Hits without GeoIP data are reported honestly as "Unknown" — configure a GeoIP provider to populate them.',
        'unknown_until_geoip' => 'unknown until GeoIP is configured',
        // F-2.3.8-013 world map (WP-27B).
        'map_heading' => 'World map',
        'map_hint' => 'One tile per country, shaded by its share of opens. This is an equal-area tile map, not a geographic outline — every country gets the same size square so small countries stay visible. Hover a tile for its exact figures.',
        'map_summary' => ':mapped of :total opens placed on the map · :unknown without a recognised country',
        'map_legend_none' => 'No opens',
        'map_tile' => ':country — :count opens (:percent%)',
        'map_tile_empty' => ':country — no opens',
    ],

    'top_links' => [
        'line' => ':clicks clicks · :unique unique · :percent%',
    ],

    // F-2.3.8-005 click map (WP-27B).
    'click_map' => [
        'heading' => 'Click map',
        'hint' => 'The campaign as it was sent, with every link badged with its exact click count, its rank and its share of all clicks. Stronger shading means more clicks. Links are disabled here.',
        'summary' => ':anchors links · :clicks clicks recorded',
        'legend_none' => 'No clicks',
        'legend_most' => 'Most clicked',
        'no_content' => 'This campaign has no saved content to map.',
    ],

    'delivery_statuses' => [
        'sent' => 'Sent',
        'failed' => 'Failed',
        'retrying' => 'Retrying',
        'skipped' => 'Skipped',
    ],

    'empty' => [
        'top_links' => 'No tracked link has been clicked yet.',
        'geo' => 'No open events recorded yet.',
        'breakdown' => 'No open events recorded yet.',
        'clickers' => 'No clicks recorded for this URL.',
    ],

    'actions' => [
        'overview' => 'Overview / Report',
        'export_report' => 'Export report',
        'export_full' => 'Export full report',
        'bulk_export' => 'Export reports',
        'export_csv' => 'Export CSV',
        'export_xlsx' => 'Export XLSX',
        'who_clicked' => 'Who clicked',
        'close' => 'Close',
    ],

    'filters' => [
        'from' => 'From',
        'until' => 'Until',
    ],

    'notifications' => [
        'export_queued' => 'Report export queued — you will receive an email with the download link.',
        'bulk_export_queued' => ':count report exports queued.',
        'admin_export_queued' => 'Log export queued — you will receive an email with the download link.',
        'job_retried' => 'Job pushed back onto its queue.',
        'jobs_retried' => ':count jobs pushed back onto their queues.',
    ],

    'export' => [
        'failed' => 'The report export failed. Please try again.',
        'mail_name' => 'Campaign report (:tab)',
        // F-2.3.8-018 format chooser (WP-26)
        'format' => 'File format',
        'formats' => [
            'csv' => 'CSV',
            'xlsx' => 'Excel (XLSX)',
        ],
        'headers' => [
            'metric' => 'Metric',
            'value' => 'Value',
            'subscriber' => 'Subscriber',
            'times_opened' => 'Times opened',
            'first_open' => 'First open',
            'last_open' => 'Last open',
            'ip' => 'IP',
            'device' => 'Device',
            'url' => 'URL',
            'clicks' => 'Clicks',
            'unique_clickers' => 'Unique clickers',
            'share' => 'Share',
            'bounce_type' => 'Bounce type',
            'message' => 'Message',
            'message_id' => 'Message ID',
            'feedback_type' => 'Feedback type',
            'status' => 'Status',
            'server' => 'Server',
            'response' => 'Response',
            'retries' => 'Retries',
            'date' => 'Date',
            'reason' => 'Reason',
            'campaign' => 'Campaign',
            'guard' => 'Guard',
            'email' => 'Email',
            'user_agent' => 'User agent',
            'result' => 'Result',
            // WP-40 — §3.27 P2 log viewers.
            'customer' => 'Customer',
            'api_key' => 'API key',
            'method' => 'Method',
            'path' => 'Path',
            'duration_ms' => 'Duration (ms)',
            'ip' => 'IP',
            'event' => 'Event',
            'url' => 'URL',
            'response_status' => 'Response status',
            'attempts' => 'Attempts',
            'error' => 'Error',
            'actor' => 'Actor',
            'action' => 'Action',
            'subject' => 'Subject',
            'old_values' => 'Before',
            'new_values' => 'After',
        ],
    ],

    /* ================================================================ *
     *  Admin panel — §3.27 logs & monitoring
     * ================================================================ */

    'admin' => [
        'nav_group' => 'System Health',
        'export_name' => 'Admin log export — :type',
        'types' => [
            'delivery' => 'Campaign delivery logs',
            'bounces' => 'Campaign bounce logs',
            'complaints' => 'Complaint / FBL logs',
            'logins' => 'Login logs',
            // WP-40 — §3.27 P2 log viewers.
            'api_requests' => 'API request logs',
            'webhook_deliveries' => 'Webhook delivery logs',
            'audit' => 'Audit log',
        ],

        'delivery' => [
            'label' => 'Delivery log',
            'plural_label' => 'Delivery logs',
            'navigation_label' => 'Delivery logs',
        ],

        'bounces' => [
            'label' => 'Bounce log',
            'plural_label' => 'Bounce logs',
            'navigation_label' => 'Bounce logs',
        ],

        'complaints' => [
            'label' => 'Complaint log',
            'plural_label' => 'Complaint logs',
            'navigation_label' => 'Complaint / FBL logs',
        ],

        'logins' => [
            'label' => 'Login log',
            'plural_label' => 'Login logs',
            'navigation_label' => 'Login logs',
            'guard' => 'Guard',
            'account_type' => 'Account type',
            'type_user' => 'Admin user',
            'type_customer' => 'Customer',
            'tabs' => [
                'all' => 'All',
                'failed' => 'Failed attempts',
                'success' => 'Successful',
            ],
        ],

        'lockouts' => [
            'heading' => 'Currently locked-out IPs',
            'hint' => 'Brute-force lockouts are enforced by the rate limiter and expire automatically — there is no permanent ban list. This table shows IPs with recent failures that are locked out right now.',
            'none' => 'No IP is currently locked out.',
            'attempts' => 'Failed attempts',
            'lifts_in' => 'Lifts in',
            'last_attempt' => 'Last attempt',
            'seconds' => ':seconds s',
        ],

        'server_usage' => [
            'navigation_label' => 'Server usage logs',
            'title' => 'Delivery server usage',
            'chart_heading' => 'Send volume per server (30 days)',
            'date' => 'Date',
            'sent' => 'Sent',
            'failed' => 'Failed',
        ],

        'error_log' => [
            'navigation_label' => 'Error log',
            'title' => 'Application error log',
            'severity' => 'Severity',
            'all_levels' => 'All levels',
            'entries' => 'Entries',
            'refresh' => 'Refresh',
            'file_line' => ':path (:size)',
            'empty' => 'No log entries match the current filter.',
        ],

        'failed_jobs' => [
            'label' => 'Failed job',
            'plural_label' => 'Failed jobs',
            'navigation_label' => 'Failed jobs',
            'job' => 'Job',
            'queue' => 'Queue',
            'connection' => 'Connection',
            'exception' => 'Exception',
            'failed_at' => 'Failed at',
            'inspect' => 'Inspect',
            'retry' => 'Retry',
            'delete' => 'Delete',
            'bulk_retry' => 'Retry selected',
            'payload' => 'Payload',
            'meta' => ':uuid — :connection / :queue — :date',
        ],

        'queue_monitor' => [
            'navigation_label' => 'Queue monitor',
            'title' => 'Queue monitor',
            'total_pending' => 'Pending jobs',
            'total_reserved' => 'Processing',
            'failed' => 'Failed jobs',
            'view_failed' => 'View failed jobs',
            'per_queue' => 'Per-queue depth',
            'queue' => 'Queue',
            'pending' => 'Pending',
            'reserved' => 'Processing',
            'oldest_pending' => 'Oldest pending',
            'age_seconds' => ':seconds s',
            'unsupported' => 'Per-queue depth requires the database queue driver (current: :driver). Failed-job counts remain accurate.',
            'horizon_note' => 'Horizon cannot run on this stack (no ext-pcntl, D-04) — this monitor reads the queue tables directly and is the documented alternative.',
        ],

        'system_info' => [
            'navigation_label' => 'System info',
            'title' => 'PHP / system info',
            'versions' => 'Versions & environment',
            'limits' => 'PHP limits',
            'storage' => 'Disk & database',
            'extensions' => 'Loaded PHP extensions',
            'disk_free' => 'Disk free / total',
            'db_size' => 'Database size',
            'retention' => 'Log retention',
            'retention_hint' => 'Enforced nightly by logs:prune (config/emp-sending.php). 0 days = keep forever.',
            'retention_keys' => [
                'campaign_delivery_log_days' => 'Campaign delivery logs',
                'campaign_track_days' => 'Open/click/unsubscribe tracking',
                'cron_run_days' => 'Cron run history',
                'idempotency_key_days' => 'Job idempotency keys',
            ],
            'days' => ':days days',
            'keep_forever' => 'Keep forever',
            'keys' => [
                'php' => 'PHP',
                'laravel' => 'Laravel',
                'filament' => 'Filament',
                'os' => 'Operating system',
                'sapi' => 'PHP SAPI',
            ],
            'drivers' => [
                'database' => 'Database driver',
                'queue' => 'Queue driver',
                'cache' => 'Cache driver',
                'session' => 'Session driver',
                'mail' => 'Mail driver',
            ],
        ],

        /*
         * WP-40 — the §3.27 P2 log viewers.
         */

        'api_requests' => [
            'label' => 'API request log',
            'plural_label' => 'API request logs',
            'navigation_label' => 'API request logs',
            'key' => 'API key',
            'no_key' => 'unauthenticated',
            'method' => 'Method',
            'path' => 'Path',
            'duration' => 'Duration',
            'duration_ms' => ':ms ms',
            'status_class' => 'Outcome',
            'classes' => [
                'success' => '2xx / 3xx',
                'client_error' => '4xx',
                'server_error' => '5xx',
            ],
            'tabs' => [
                'all' => 'All',
                'errors' => 'Errors',
                'slow' => 'Slow (≥ :ms ms)',
            ],
            'retention_hint' => 'Pruned on a request lottery against the §3.25.7 API retention setting, not by logs:prune.',
        ],

        'webhook_deliveries' => [
            'label' => 'Webhook delivery',
            'plural_label' => 'Webhook deliveries',
            'navigation_label' => 'Webhook deliveries',
            'webhook' => 'Endpoint',
            'event' => 'Event',
            'url' => 'URL',
            'attempts' => 'Attempts',
            'response_status' => 'Response',
            'duration' => 'Duration',
            'delivered_at' => 'Delivered',
            'next_attempt_at' => 'Next attempt',
            'error' => 'Error',
            'replay_of' => 'Replay of',
            'is_replay' => 'Replay',
            'view' => 'Request / response',
            'tabs' => [
                'all' => 'All',
                'failed' => 'Failed',
                'pending' => 'Pending',
            ],
            'admin_replay_denied' => 'Replaying a customer webhook is a customer action — it is deliberately not available from the admin panel.',
        ],

        'audit' => [
            'label' => 'Audit entry',
            'plural_label' => 'Audit log',
            'navigation_label' => 'Audit log',
            'actor' => 'Who',
            'action' => 'What',
            'subject' => 'Subject',
            'description' => 'Description',
            'diff' => 'Before / after',
            'diff_heading' => 'Changes',
            'field' => 'Field',
            'old' => 'Before',
            'new' => 'After',
            'no_diff' => 'This entry records no field-level change.',
            'legacy_diff' => 'This entry predates before/after recording — only the list of changed fields was kept: :fields',
            'redacted' => '[redacted]',
            'empty_value' => '—',
            'view' => 'View diff',
        ],
    ],

    /* ================================================================ *
     *  WP-40 — §3.9 / §3.10 / §3.12 cross-customer module overviews
     * ================================================================ */

    'overviews' => [
        'nav_group' => 'Customer Activity',

        'types' => [
            'surveys' => 'Surveys',
            'landing_pages' => 'Landing pages',
            'transactional' => 'Transactional sends',
        ],

        'columns' => [
            'customer' => 'Customer',
            'created_at' => 'Created',
        ],

        'filters' => [
            'customer' => 'Customer',
            'status' => 'Status',
            'created_from' => 'Created from',
            'created_until' => 'Created until',
        ],

        'actions' => [
            'export' => 'Export CSV',
            'stats' => 'Stats',
            'disable' => 'Disable',
            'enable' => 'Enable',
            'delete' => 'Delete',
        ],

        'notifications' => [
            'export_queued' => 'Overview export queued — you will receive an email with the download link.',
            'disabled' => 'Disabled.',
            'enabled' => 'Enabled.',
            'deleted' => 'Deleted.',
        ],

        'surveys' => [
            'label' => 'Survey',
            'plural_label' => 'All surveys',
            'navigation_label' => 'All surveys',
            'name' => 'Survey',
            'list' => 'List',
            'status' => 'Status',
            'responders' => 'Responders',
            'completed' => 'Completed',
            'completion_rate' => 'Completion',
            'stats_heading' => 'Stats — :name',
            'stats_question' => 'Question',
            'stats_answered' => 'Answered',
            'stats_average' => 'Average',
            'stats_empty' => 'No completed response yet.',
            'delete_confirm' => 'Deleting a survey removes it and its responses from the customer account.',
        ],

        'landing_pages' => [
            'label' => 'Landing page',
            'plural_label' => 'All landing pages',
            'navigation_label' => 'All landing pages',
            'name' => 'Page',
            'slug' => 'Slug',
            'status' => 'Status',
            'visits' => 'Visits',
            'unique_visitors' => 'Unique visitors',
            'conversions' => 'Conversions',
            'conversion_rate' => 'Conversion',
            'published_at' => 'Published',
            'disable_confirm' => 'Disabling unpublishes the page — the public URL starts returning 404 immediately. The content is untouched.',
            'stats_heading' => 'Stats — :name',
            'stats_source' => 'Source',
        ],

        'transactional' => [
            'label' => 'Transactional send',
            'plural_label' => 'All transactional sends',
            'navigation_label' => 'All transactional sends',
            'to' => 'To',
            'subject' => 'Subject',
            'status' => 'Status',
            'sent_at' => 'Sent',
            'opens' => 'Opens',
            'clicks' => 'Clicks',
            'error' => 'Error',
            'volume_heading' => 'Per-customer volume & error rates — last :days days',
            'volume_hint' => 'Every figure comes from the same service the customer sees on their own transactional stats page. Error rate counts only messages that never left the platform; a bounce was accepted by a provider.',
            'total' => 'Messages',
            'sent' => 'Sent',
            'failed' => 'Failed',
            'bounced' => 'Bounced',
            'delivery_rate' => 'Delivery',
            'bounce_rate' => 'Bounce',
            'error_rate' => 'Error rate',
            'avg_delivery' => 'Avg delivery',
            'customers' => 'Customers sending',
            'volume_empty' => 'No transactional message in this window.',
        ],

        'export' => [
            'name' => 'Overview export — :type',
            'headers' => [
                'customer' => 'Customer',
                'name' => 'Name',
                'slug' => 'Slug',
                'status' => 'Status',
                'list' => 'List',
                'responders' => 'Responders',
                'completed' => 'Completed',
                'completion_rate' => 'Completion rate %',
                'visits' => 'Visits',
                'unique_visitors' => 'Unique visitors',
                'conversions' => 'Conversions',
                'conversion_rate' => 'Conversion rate %',
                'published_at' => 'Published at',
                'created_at' => 'Created at',
                'total' => 'Messages',
                'sent' => 'Sent',
                'failed' => 'Failed',
                'bounced' => 'Bounced',
                'complained' => 'Complained',
                'delivery_rate' => 'Delivery rate %',
                'bounce_rate' => 'Bounce rate %',
                'error_rate' => 'Error rate %',
                'avg_delivery_ms' => 'Avg delivery ms',
            ],
        ],
    ],

    /* ================================================================ *
     *  §4.2 F-4.2-010 — custom report builder & saved reports (WP-46)
     * ================================================================ */

    'builder' => [
        'title' => 'Report builder',
        'navigation_label' => 'Report builder',
        'subheading' => 'Pick a dataset, group it, measure it, filter it — then save the question so it can be re-run, exported or scheduled.',

        'datasets' => [
            'campaigns' => [
                'label' => 'Campaigns',
                'description' => 'One row per sent campaign. The date range covers the SEND moment, and every figure is the campaign’s whole life, exactly as its own report shows it.',
            ],
            'subscribers' => [
                'label' => 'Subscribers',
                'description' => 'One row per subscriber. The date range covers when they were ADDED, so this reports growth rather than the standing list size.',
            ],
            'links' => [
                'label' => 'Link clicks',
                'description' => 'One row per click on a tracked link in a sent campaign. The date range covers when the click happened.',
            ],
            'opens' => [
                'label' => 'Opens (geo & device)',
                'description' => 'One row per open of a sent campaign, carrying its country, region, city, device, OS, browser and mail client. The date range covers when the open happened.',
            ],
        ],

        'fields' => [
            // Shared dimensions
            'campaign' => 'Campaign',
            'list' => 'List',
            'campaign_type' => 'Campaign type',
            'send_date' => 'Send date',
            'send_month' => 'Send month',
            'send_weekday' => 'Send weekday',
            'send_hour' => 'Send hour',
            'status' => 'Status',
            'source' => 'Signup source',
            'signup_date' => 'Signup date',
            'signup_month' => 'Signup month',
            'url' => 'Link URL',
            'click_date' => 'Click date',
            'click_month' => 'Click month',
            'open_date' => 'Open date',
            'open_month' => 'Open month',
            'country' => 'Country',
            'region' => 'Region',
            'city' => 'City',
            'device' => 'Device',
            'os' => 'Operating system',
            'browser' => 'Browser',
            'client' => 'Mail client',

            // Campaign measures
            'campaigns' => 'Campaigns',
            'recipients' => 'Recipients',
            'delivered' => 'Delivered',
            'failed' => 'Failed',
            'skipped' => 'Skipped',
            'attempted' => 'Attempted',
            'bounced' => 'Bounced',
            'bounced_hard' => 'Hard bounces',
            'bounced_soft' => 'Soft bounces',
            'opens' => 'Opens',
            'clicks' => 'Clicks',
            'unsubscribes' => 'Unsubscribes',
            'complaints' => 'Complaints',
            'unique_opens' => 'Unique opens',
            'unique_openers' => 'People who opened',
            'unique_clicks' => 'Unique clicks',
            'unique_clickers' => 'People who clicked',
            'delivery_rate' => 'Delivery rate %',
            'open_rate' => 'Open rate %',
            'click_rate' => 'Click rate %',
            'ctor' => 'Click-to-open rate %',
            'bounce_rate' => 'Bounce rate %',
            'unsubscribe_rate' => 'Unsubscribe rate %',
            'complaint_rate' => 'Complaint rate %',

            // Subscriber measures
            'subscribers' => 'Subscribers',
            'confirmed' => 'Confirmed',
            'unsubscribed' => 'Unsubscribed',
            'complained' => 'Complained',
            'lists_reached' => 'Lists covered',
            'confirm_rate' => 'Confirmed %',

            // Click / open measures
            'campaigns_reached' => 'Campaigns covered',
        ],

        'fields_suffix' => [
            'non_summable' => ':label (recomputed, never summed)',
        ],

        'weekdays' => [
            0 => 'Sunday',
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
        ],

        /*
         * Refusals. Every one of these is shown to the customer verbatim and
         * stored on the report_runs row, because "we refused to compute this"
         * and "there is no data" are different answers.
         */
        'guards' => [
            'range_open_ended' => 'This report has no start date. An all-time report would scan the account’s entire history, so pick a window of :max days or fewer.',
            'range_too_large' => 'This report covers :days days. The limit is :max days — narrow the window and run it again.',
            'preview_range_too_large' => 'A preview covers at most :max days (this one asks for :days). Save the report and export it to cover the full window.',
            'no_dimensions' => 'Pick at least one column to group by.',
            'no_metrics' => 'Pick at least one measure to report.',
            'too_many_dimensions' => 'This report groups by :count columns. The limit is :max — each extra column multiplies the number of rows.',
            'too_many_metrics' => 'This report asks for :count measures. The limit is :max.',
            'too_many_rows' => 'This report produces more than :max rows. Narrow the window, drop a grouping column or add a filter — the rows will not be silently cut off.',
            'preview_too_many_rows' => 'The preview shows at most :max rows. Save the report and export it to see everything.',
            'too_many_campaigns' => 'This window contains :count campaigns and the limit is :max. Every campaign is measured from its own source rows, so a wider scan would be slow AND expensive — narrow the window.',
            'unknown_field' => 'The field ":field" does not exist on the :dataset dataset.',
            'filter_not_selected' => 'The filter on ":field" can only be applied to a column this report actually produces. Add it as a dimension or a measure first.',
            'mixed_any_filter' => 'An “any” group cannot mix a filter on a raw column with a filter on a calculated measure: the first runs before the figures are computed and the second after, so the OR could not be honoured. Split them into separate groups.',
            'missing_customer' => 'This report’s account no longer exists.',
        ],

        'result' => [
            'total' => 'Total',
            'unknown' => 'unknown',
            'no_list' => 'no list',
            'empty' => 'No rows for this window.',
            'recomputed_note' => 'Recomputed rather than summed: :metrics. Adding these across rows would count the same person more than once (or average away the size of each campaign), so the total line re-reads them from source.',
            'limited' => 'Showing the first :limit rows of this report’s own limit.',
            'scanned' => 'Scanned :count source rows.',
            'refused' => 'This report was refused',
        ],

        'charts' => [
            'table' => 'Table only',
            'bar' => 'Bar chart',
            'line' => 'Line chart',
            'pie' => 'Share breakdown',
        ],

        'form' => [
            'name' => 'Report name',
            'description' => 'Description',
            'source' => 'Dataset',
            'dimensions' => 'Group by',
            'dimensions_hint' => 'Up to :max columns. Each one splits the report into more rows.',
            'metrics' => 'Measures',
            'metrics_hint' => 'Measures marked “recomputed” are never added up: unique counts are re-read from source per row and rates are recalculated from that row’s own totals.',
            'range' => 'Date range',
            'range_from' => 'From',
            'range_until' => 'Until',
            'filters' => 'Filters',
            'filters_match' => 'Combine groups with',
            'condition_field' => 'Field',
            'condition_operator' => 'Operator',
            'condition_value' => 'Value',
            'condition_value2' => 'Second value',
            'condition_group' => 'Group',
            'condition_group_hint' => 'Conditions sharing a number are combined with that group’s operator.',
            'condition_group_match' => 'Combine inside the group with',
            'group_match_default' => 'Same as the report',
            'sort_field' => 'Sort by',
            'sort_direction' => 'Direction',
            'sort_desc' => 'Highest first',
            'sort_asc' => 'Lowest first',
            'sort_default' => 'First measure, highest first',
            'row_limit' => 'Row limit',
            'chart_type' => 'Chart',
            'is_favourite' => 'Favourite',
        ],

        'preview' => [
            'heading' => 'Preview',
            'hint' => 'A preview covers at most :days days and :rows rows so it can run in the page. The saved report exports the whole window on the queue.',
            'run' => 'Refresh preview',
        ],

        'actions' => [
            'save' => 'Save report',
            'saved' => 'Report saved.',
            'update' => 'Update report',
            'export' => 'Queue export',
            'export_queued' => 'The export is queued. You will get an email with a download link when it is ready.',
            'open' => 'Open in builder',
            'run' => 'Run & export',
            'duplicate' => 'Duplicate',
            'duplicated' => 'Report duplicated.',
            'favourite' => 'Favourite',
            'unfavourite' => 'Remove favourite',
            'new' => 'New report',
        ],

        'index' => [
            'model_label' => 'saved report',
            'model_label_plural' => 'saved reports',
            'navigation_label' => 'Saved reports',
            'empty' => 'No saved reports yet — build one in the report builder.',
            'columns' => [
                'name' => 'Name',
                'source' => 'Dataset',
                'grouping' => 'Grouped by',
                'measures' => 'Measures',
                'range' => 'Window',
                'favourite' => 'Favourite',
                'last_run' => 'Last run',
                'created' => 'Created',
            ],
            'never_run' => 'never',
        ],

        'export' => [
            'failed' => 'The report export could not be produced.',
            'mail_name' => 'custom report',
            'pdf_filename' => 'custom-report',
            'format' => 'Format',
        ],

        'pdf' => [
            'dataset' => 'Dataset',
            'window' => 'Window',
            'generated' => 'Generated',
            'results' => 'Results',
            'refused_heading' => 'This report could not be produced',
        ],

        'duplicate_name' => ':name (copy)',
    ],
];
