<?php

declare(strict_types=1);

/*
 * WP-40 — §4.2 aggregate reporting & analytics, §4.2-012 Google Analytics 4,
 * §4.4 search and §4.8 mobile / PWA.
 */
return [

    'nav_group' => 'Analytics',

    /* ================================================================ *
     *  Account analytics dashboard (F-4.2-001)
     * ================================================================ */

    'page' => [
        'navigation_label' => 'Account analytics',
        'title' => 'Account analytics',
        'subheading' => 'Every figure below is the same number the campaign reports show, summed across the campaigns that finished sending inside the selected window.',
        'empty' => 'No campaign finished sending in this window yet.',
    ],

    'ranges' => [
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        '90d' => 'Last 90 days',
        '365d' => 'Last 12 months',
        'all' => 'All time',
        'custom' => 'Custom range',
        'custom_label' => ':from → :to',
        'field' => 'Date range',
        'from' => 'From',
        'until' => 'Until',
    ],

    'tabs' => [
        'overview' => 'Overview',
        'lists' => 'List health',
        'leaderboard' => 'Leaderboard',
        'heatmap' => 'Send times',
        'growth' => 'Growth & churn',
        'cohorts' => 'Engagement cohorts',
        'links' => 'Link performance',
        'geo' => 'Geo & devices',
    ],

    'metrics' => [
        'campaigns' => 'Campaigns sent',
        'recipients' => 'Recipients',
        'sends' => 'Sends',
        'delivered' => 'Delivered',
        'failed' => 'Failed',
        'delivery_rate' => 'Delivery rate',
        'opens' => 'Opens',
        'unique_opens' => 'Unique opens',
        'open_rate' => 'Open rate',
        'clicks' => 'Clicks',
        'unique_clicks' => 'Unique clicks',
        'click_rate' => 'Click-through rate',
        'ctor' => 'Click-to-open rate',
        'bounced' => 'Bounced',
        'bounce_rate' => 'Bounce rate',
        'unsubscribes' => 'Unsubscribes',
        'unsubscribe_rate' => 'Unsubscribe rate',
        'complaints' => 'Complaints',
        'complaint_rate' => 'Complaint rate',
    ],

    'hints' => [
        'delivery_rate' => 'Delivered ÷ attempted. Skipped recipients were never attempted and count in neither side.',
        'open_rate' => 'Unique opens ÷ delivered.',
        'click_rate' => 'Unique clicks ÷ delivered.',
        'ctor' => 'Unique clicks ÷ unique opens.',
        'rates_from_totals' => 'Rates are recomputed from the summed totals, never averaged from per-campaign rates.',
    ],

    'series' => [
        'heading' => 'Sends, opens & clicks',
        'hint' => 'Read from the hourly stats rollup. Unique counts are not shown here because per-day uniques cannot be summed across days.',
    ],

    /* ================================================================ *
     *  List health (F-4.2-002)
     * ================================================================ */

    'lists' => [
        'heading' => 'List health comparison',
        'hint' => 'Engagement is the share of active members who opened or clicked in the last :days days.',
        'name' => 'List',
        'subscribers' => 'Subscribers',
        'active' => 'Active',
        'unconfirmed' => 'Unconfirmed',
        'unsubscribed' => 'Unsubscribed',
        'bounced' => 'Bounced',
        'complained' => 'Complained',
        'added' => 'Added',
        'churned' => 'Churned',
        'net' => 'Net',
        'engaged' => 'Engaged',
        'engagement_rate' => 'Engagement',
        'unsubscribe_rate' => 'Unsub %',
        'bounce_rate' => 'Bounce %',
        'empty' => 'This account has no lists yet.',
    ],

    /* ================================================================ *
     *  Leaderboard (F-4.2-003)
     * ================================================================ */

    'leaderboard' => [
        'heading' => 'Best performing campaigns',
        'metric' => 'Rank by',
        'hint' => 'Campaigns that delivered nothing are excluded from rate rankings — a 0 % open rate off zero delivered is no data, not a bad campaign.',
        'rank' => '#',
        'campaign' => 'Campaign',
        'subject' => 'Subject',
        'sent_at' => 'Sent',
        'value' => 'Value',
        'empty' => 'No sent campaign in this window qualifies.',
    ],

    /* ================================================================ *
     *  Send-day / send-hour heatmap (F-4.2-004)
     * ================================================================ */

    'heatmap' => [
        'heading' => 'Send day & hour heatmap',
        'hint' => 'Open rate of the campaigns that STARTED sending in each slot. Empty slots are blank, not zero — never having sent on a Sunday at 03:00 is not evidence that it performs badly.',
        'day' => 'Day',
        'hour' => 'Hour',
        'best' => 'Best slot: :day at :hour:00 — :rate% open rate',
        'no_best' => 'Not enough sent campaigns to pick a best slot.',
        'cell' => ':day :hour:00 — :campaigns campaign(s), :rate% open rate',
        'cell_empty' => ':day :hour:00 — nothing sent',
        'days' => [
            0 => 'Sun',
            1 => 'Mon',
            2 => 'Tue',
            3 => 'Wed',
            4 => 'Thu',
            5 => 'Fri',
            6 => 'Sat',
        ],
    ],

    /* ================================================================ *
     *  Growth & churn (F-4.2-005)
     * ================================================================ */

    'growth' => [
        'heading' => 'Subscriber growth & churn',
        'hint' => 'Churn is measured against the subscribers that already existed when the bucket opened.',
        'granularity' => 'Bucket',
        'granularities' => [
            'daily' => 'Daily',
            'weekly' => 'Weekly',
            'monthly' => 'Monthly',
        ],
        'bucket' => 'Period',
        'opening' => 'Opening',
        'added' => 'Added',
        'unsubscribed' => 'Unsubscribed',
        'bounced' => 'Bounced',
        'net' => 'Net change',
        'churn_rate' => 'Churn',
        'growth_rate' => 'Growth',
        'totals' => 'Window total',
        'empty' => 'No subscriber activity in this window.',
    ],

    /* ================================================================ *
     *  Engagement cohorts (F-4.2-006)
     * ================================================================ */

    'cohorts' => [
        'heading' => 'Engagement cohorts',
        'hint' => 'Active = opened or clicked in the last :active days. Lapsed = engaged in the last :lapsed days but not the last :active. Dormant = no engagement in :lapsed days.',
        'total' => 'Active memberships',
        'active' => 'Active',
        'lapsed' => 'Lapsed',
        'dormant' => 'Dormant',
        'never_engaged' => 'Never engaged',
        'never_engaged_hint' => 'A subset of dormant: these addresses have never opened or clicked anything.',
        'share' => ':percent% of active memberships',
    ],

    /* ================================================================ *
     *  Link performance (F-4.2-007)
     * ================================================================ */

    'links' => [
        'heading' => 'Link performance across all campaigns',
        'hint' => 'Grouped by destination URL, so the same link used in six campaigns reports as one line.',
        'url' => 'URL',
        'clicks' => 'Clicks',
        'unique_clickers' => 'Unique clickers',
        'campaigns' => 'Campaigns',
        'share' => 'Share',
        'empty' => 'No tracked link has been clicked in this window.',
    ],

    /* ================================================================ *
     *  Geo & device roll-ups (F-4.2-008)
     * ================================================================ */

    'geo' => [
        'heading' => 'Locations',
        'country' => 'Country',
        'opens' => 'Opens',
        'share' => 'Share',
        'unknown' => 'Unknown',
        'hint' => 'Countries come from a GeoIP lookup on open events. Hits without GeoIP data are reported honestly as "Unknown" — configure a GeoIP provider to populate them.',
        'empty' => 'No open events recorded in this window.',
    ],

    'devices' => [
        'heading' => 'Devices, clients & platforms',
        'dimension' => 'Split by',
        'dimensions' => [
            'device' => 'Device',
            'os' => 'Operating system',
            'browser' => 'Browser',
            'client' => 'Email client',
        ],
        'label' => 'Value',
        'opens' => 'Opens',
        'share' => 'Share',
        'empty' => 'No open events recorded in this window.',
    ],

    /* ================================================================ *
     *  Scheduled report emails (F-4.2-011)
     * ================================================================ */

    'scheduled' => [
        'navigation_label' => 'Scheduled reports',
        'model_label' => 'Scheduled report',
        'model_label_plural' => 'Scheduled reports',
        'title' => 'Scheduled reports',
        'subheading' => 'Email a PDF of this account\'s analytics on a weekly or monthly cadence.',
        'fields' => [
            'name' => 'Report name',
            'frequency' => 'Frequency',
            'range_key' => 'Window covered',
            'recipients' => 'Recipients',
            'recipients_hint' => 'One email address per entry. Leave empty to send to the account owner.',
            'is_active' => 'Active',
            'timezone' => 'Timezone',
            'send_hour' => 'Send at (hour)',
            'last_sent_at' => 'Last sent',
            'next_run_at' => 'Next run',
            // §4.2 F-4.2-010 (WP-46) — mail a saved custom report instead.
            'report_definition' => 'Report',
            'report_definition_none' => 'Account analytics PDF',
            'report_definition_hint' => 'Pick one of your saved custom reports to mail it on this schedule. Leave it empty for the account-wide analytics document. A saved report carries its own date range.',
        ],
        'frequencies' => [
            'weekly' => 'Weekly (Mondays)',
            'monthly' => 'Monthly (1st of the month)',
        ],
        'actions' => [
            'send_now' => 'Send now',
            'download' => 'Download PDF',
        ],
        'notifications' => [
            'queued' => 'Report queued — it will be emailed shortly.',
        ],
        'queued_count' => ':count scheduled report(s) queued.',
        'mail' => [
            'subject' => ':name — :range',
            'greeting' => 'Here is your scheduled analytics report.',
            'line' => ':name covering :range, generated :date.',
            'attachment' => 'The full report is attached as a PDF.',
            'filename' => 'analytics-report',
        ],
        'pdf' => [
            'title' => 'Analytics report',
            'account' => 'Account',
            'window' => 'Window',
            'generated' => 'Generated',
            'headline' => 'Headline figures',
            'top_campaigns' => 'Top campaigns',
            'lists' => 'List health',
            'cohorts' => 'Engagement cohorts',
            'footer' => 'Generated by :app. Figures are computed from the same source tables as the in-app campaign reports.',
        ],
        'empty' => 'No scheduled report yet.',
    ],

    /* ================================================================ *
     *  Google Analytics 4 (F-4.2-012)
     * ================================================================ */

    'ga4' => [
        'navigation_label' => 'Analytics integrations',
        'title' => 'Analytics integrations',
        'subheading' => 'Forward campaign opens and clicks to Google Analytics 4 using the Measurement Protocol.',
        'heading' => 'Google Analytics 4',
        'hint' => 'Events are sent server-side from a queued job, so a slow or unreachable Google endpoint can never delay a tracking pixel or a click redirect. Nothing is sent until both fields below are filled in and the integration is enabled.',
        'client_id_hint' => 'The subscriber UID is used as the GA4 client_id — a stable pseudonymous identifier that never carries an email address.',
        'fields' => [
            'enabled' => 'Send events to GA4',
            'measurement_id' => 'Measurement ID',
            'measurement_id_hint' => 'Looks like G-XXXXXXXXXX.',
            'api_secret' => 'API secret',
            'api_secret_hint' => 'Created under Admin → Data Streams → Measurement Protocol API secrets. Stored encrypted.',
            'track_opens' => 'Send an event on campaign opens',
            'track_clicks' => 'Send an event on campaign clicks',
        ],
        'events' => [
            'open' => 'email_open',
            'click' => 'email_click',
        ],
        'actions' => [
            'save' => 'Save',
        ],
        'notifications' => [
            'saved' => 'Analytics integration settings saved.',
        ],
        'validation' => [
            'measurement_id' => 'A GA4 measurement ID looks like G-XXXXXXXXXX.',
        ],
    ],

    /* ================================================================ *
     *  §2.1 F-2.1-007 getting-started checklist
     * ================================================================ */

    'onboarding' => [
        'heading' => 'Getting started',
        'subheading' => ':completed of :total steps done. This checklist disappears once every step is complete.',
        'steps' => [
            'create_list' => [
                'title' => 'Create your first list',
                'hint' => 'Everything else hangs off a list — subscribers, forms and campaigns.',
                'action' => 'Create a list',
            ],
            'add_subscribers' => [
                'title' => 'Add subscribers',
                'hint' => 'Import a CSV, paste addresses, or publish a signup form.',
                'action' => 'Go to lists',
            ],
            'verify_domain' => [
                'title' => 'Verify a sending domain',
                'hint' => 'SPF and DKIM are what keep your mail out of the spam folder.',
                'action' => 'Add a domain',
            ],
            'configure_server' => [
                'title' => 'Connect a delivery server',
                'hint' => 'SMTP or an API provider — whatever actually sends your mail.',
                'action' => 'Configure sending',
            ],
            'create_campaign' => [
                'title' => 'Create a campaign',
                'hint' => 'Pick a template, write a subject line and choose your audience.',
                'action' => 'Create a campaign',
            ],
            'send_campaign' => [
                'title' => 'Send your first campaign',
                'hint' => 'Reports and analytics fill up the moment mail goes out.',
                'action' => 'Go to campaigns',
            ],
        ],
    ],

    /* ================================================================ *
     *  §2.1 F-2.1-008 deliverability health score card
     * ================================================================ */

    'health' => [
        'heading' => 'Deliverability health',
        'hint' => 'Starts at 100 and loses points for each problem below. Every deduction is shown, so the number is explainable — open the deliverability centre for the detail and the fix.',
        'no_data' => 'Nothing has been sent in this window yet, so there are no rates to score. Bounce, complaint and unsubscribe penalties start applying with your first send.',
        'window' => 'Last :days days',
        'open_centre' => 'Deliverability centre',
        'penalty' => '−:points points',
        'no_penalty' => 'No penalty',
        'grades' => [
            'excellent' => 'Excellent',
            'good' => 'Good',
            'fair' => 'Fair',
            'poor' => 'Poor',
        ],
        'metrics' => [
            'bounce' => 'Bounce rate',
            'complaint' => 'Complaint rate',
            'unsubscribe' => 'Unsubscribe rate',
            'authentication' => 'Authenticated domains',
            'dormant' => 'Dormant subscribers',
        ],
    ],

    /* ================================================================ *
     *  §3.1 F-3.1-006 server resources widget
     * ================================================================ */

    'resources' => [
        'heading' => 'Server resources',
        'disk' => 'Disk free / total',
        'disk_used' => ':percent% used',
        'memory' => 'PHP memory in use',
        'memory_detail' => 'peak :peak · limit :limit',
        'database' => 'Database size',
        'storage' => 'Storage directory',
        'unavailable' => 'Not reported by this host',
        'open_system_info' => 'Full system info',
    ],

    /* ================================================================ *
     *  §4.4 search
     * ================================================================ */

    'search' => [
        'lists' => 'List',
        'campaigns' => 'Campaign',
        'subscribers' => 'Subscriber',
        'templates' => 'Template',
        'details' => [
            'customer' => 'Account',
            'list' => 'List',
            'status' => 'Status',
            'subject' => 'Subject',
            'category' => 'Category',
            'subscribers' => 'Subscribers',
        ],
    ],

    /* ================================================================ *
     *  §4.8 mobile / PWA
     * ================================================================ */

    'mobile' => [
        'builder_heading' => 'The drag-and-drop builder needs a bigger screen',
        'builder_body' => 'This editor mounts a three-column canvas that cannot be used on a phone. Open this page on a tablet or desktop, or switch to the basic block builder, which is fully responsive.',
    ],

    'pwa' => [
        'name' => 'Email marketing',
        'short_name' => 'Email',
        'description' => 'Manage lists, campaigns and reports from your phone.',
        'offline_title' => 'You are offline',
        'offline_body' => 'This page needs a connection. Reconnect and try again — nothing you were viewing has been cached, because account pages are never stored on the device.',
        'offline_retry' => 'Retry',
    ],
];
