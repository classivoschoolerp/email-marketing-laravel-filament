<?php

declare(strict_types=1);

// WP-06/07 — subscribers module strings (spec §2.2.4–§2.2.6).

return [

    'nav_label' => 'Subscribers',
    'model_label' => 'Subscriber',
    'model_label_plural' => 'Subscribers',

    'status' => [
        'confirmed' => 'Confirmed',
        'unconfirmed' => 'Unconfirmed',
        'unsubscribed' => 'Unsubscribed',
        'blacklisted' => 'Blacklisted',
        'disabled' => 'Disabled',
        'moved' => 'Moved',
        'bounced' => 'Bounced',
    ],

    'source' => [
        'form' => 'Form',
        'import' => 'Import',
        'api' => 'API',
        'manual' => 'Manual',
        'automation' => 'Automation',
    ],

    'index' => [
        'email' => 'Email',
        'list' => 'List',
        'status' => 'Status',
        'source' => 'Source',
        'subscribed_at' => 'Subscribed',
        'confirmed_at' => 'Confirmed',
        'unsubscribed_at' => 'Unsubscribed',
        'ip' => 'IP address',
        'last_open_at' => 'Last open',
        'last_click_at' => 'Last click',
        'subscribed_from' => 'Subscribed from',
        'subscribed_until' => 'Subscribed until',
        'yes' => 'Yes',
        'no' => 'No',
    ],

    // Named saved table views (F-2.2.4-009).
    'views' => [
        'save' => 'Save view',
        'load' => 'Load view',
        'delete' => 'Delete view',
        'name' => 'View name',
        'saved' => 'View saved.',
        'loaded' => 'View ":name" loaded.',
        'deleted' => 'View deleted.',
        'missing' => 'That view no longer exists.',
    ],

    // Engagement search over the campaign tracking tables (F-2.2.4-003).
    'engagement' => [
        'label' => 'Engagement',
        'within_days' => 'In the last (days)',
        'within_days_help' => 'Leave empty for all time.',
        'indicator' => 'Engagement: :mode',
        'indicator_days' => 'Engagement: :mode (last :days days)',
        'modes' => [
            'opened' => 'Opened a campaign',
            'not_opened' => 'Did not open any campaign',
            'clicked' => 'Clicked a link',
            'not_clicked' => 'Did not click any link',
        ],
    ],

    // Subscriber tags (F-2.2.5-004). Storage is WP-21's subscriber_tags.
    'tags' => [
        'label' => 'Tags',
        'placeholder' => 'Add a tag',
        'help' => 'Free-form internal labels, stored lowercase. Automations can trigger when a tag is added or removed.',
        'filter' => 'Tags',
        'none' => 'No tags yet.',
    ],

    // Internal notes (F-2.2.5-011).
    'notes' => [
        'label' => 'Internal notes',
        'help' => 'Visible to your team only. Never shown to the subscriber, never sent in an email, and erased by a GDPR erasure request.',
        'saved' => 'Notes saved.',
    ],

    // Campaign activity timeline (F-2.2.5-005).
    'timeline' => [
        'when' => 'When',
        'event' => 'Event',
        'campaign' => 'Campaign',
        'detail' => 'Detail',
        'unknown_campaign' => 'Unknown campaign',
        'summary' => 'Delivered :delivered · Opens :opens · Clicks :clicks · Bounces :bounces · Complaints :complaints · Unsubscribes :unsubscribes',
        'types' => [
            'delivery' => 'Delivered',
            'open' => 'Opened',
            'click' => 'Clicked',
            'bounce' => 'Bounced',
            'complaint' => 'Spam complaint',
            'unsubscribe' => 'Unsubscribed',
        ],
        'delivery_status' => [
            'sent' => 'Sent',
            'failed' => 'Failed',
            'retrying' => 'Retrying',
            'skipped' => 'Skipped',
        ],
    ],

    // Engagement score / rating (F-2.2.5-006).
    'engagement_score' => [
        'heading' => 'Engagement score',
        'score' => 'Score',
        'rating_label' => 'Rating',
        'counts' => 'Opens :opens · Clicks :clicks · Bounces :bounces · Complaints :complaints · Unsubscribes :unsubscribes',
        'explanation' => 'Scored 0–100 over the last 365 days of tracked activity: opens and clicks add points and are weighted down as they age, while bounces, spam complaints and unsubscribes subtract them.',
        'rating' => [
            'inactive' => 'Inactive',
            'low' => 'Low',
            'medium' => 'Medium',
            'high' => 'High',
        ],
    ],

    // Cross-list view (F-2.2.5-012).
    'memberships' => [
        'heading' => 'Lists this address belongs to',
        'list' => 'List',
        'status' => 'Status',
        'subscribed_at' => 'Subscribed',
        'current' => 'This profile',
        'none' => 'This address is not on any other list.',
    ],

    'form' => [
        'section_subscriber' => 'Subscriber',
        'section_fields' => 'Custom fields',
        'list' => 'List',
        'email' => 'Email address',
        'status' => 'Status',
        'source' => 'Source',
        'skip_confirmation' => 'Skip confirmation',
        'skip_confirmation_help' => 'Store the subscriber as confirmed immediately, bypassing double opt-in.',
        'merge_duplicate' => 'Merge if the email already exists',
        'merge_duplicate_help' => 'Instead of failing on a duplicate, update the existing subscriber with these values.',
        'duplicate_error' => 'This email address is already subscribed to the selected list.',
        'merged' => 'Existing subscriber updated (merged).',
    ],

    'actions' => [
        'create' => 'Add subscriber',
        'quick_add' => 'Quick add',
        'added' => 'Subscriber added.',
        'view_profile' => 'Profile',
        'edit' => 'Edit',
        'unsubscribe' => 'Unsubscribe',
        'confirm' => 'Confirm',
        'blacklist' => 'Blacklist',
        'unblacklist' => 'Remove from blacklist',
        'disable' => 'Disable',
        'delete' => 'Delete',
        'resend_confirmation' => 'Resend confirmation',
        'resend_confirmation_help' => 'Sends the double opt-in confirmation email again, with a fresh signed link.',
        'resent' => 'Confirmation email queued.',
        'resend_throttled' => 'Too many confirmation resends for this subscriber. Try again in :minutes minute(s).',
        'resend_not_unconfirmed' => 'Only unconfirmed subscribers can be sent a confirmation email.',
        'resend_no_list' => 'This subscriber\'s list is no longer available.',
        'copy_to' => 'Copy to list',
        'move_to' => 'Move to list',
        'target_list' => 'Target list',
        'export_selected' => 'Export selected',
        // Profile actions (F-2.2.5-010).
        'send_email' => 'Send single email',
        'email_subject' => 'Subject',
        'email_body' => 'Message (HTML)',
        'email_body_help' => 'Merge tags such as {FNAME|there}, {EMAIL} and {UNSUBSCRIBE_URL} are rendered before sending. The list footer is appended automatically.',
        'email_queued' => 'Email queued for sending.',
        'add_to_automation' => 'Add to automation',
        'automation' => 'Automation',
        'automation_missing' => 'That automation is no longer available.',
        'automation_refused' => 'The subscriber was not enrolled — the automation is not running, or its re-entry rules already cover this subscriber.',
        'automation_entered' => 'Subscriber enrolled in the automation.',
        'add_to_segment' => 'Add to segment',
        'add_to_segment_heading' => 'Segments are condition-based',
        'add_to_segment_explanation' => 'A segment is not a list of subscribers: it is a set of conditions re-evaluated every time it is read, so a subscriber cannot be added to one directly. To include this subscriber, add or edit a condition that matches them — for example on a custom field value you can set on this profile. Static, frozen segments are a planned future feature.',
        'add_to_segment_open' => 'Open segments',
        'export_json' => 'Export as JSON',
        'gdpr_erase' => 'GDPR erase',
        'save_segment' => 'Save search as segment',
        'segment_saved' => 'Segment saved.',
        'queued' => 'Queued — running in the background.',
        'done' => 'Done.',
    ],

    'profile' => [
        'title' => 'Subscriber: :email',
        'fields_heading' => 'Profile fields',
        'save' => 'Save changes',
        'saved' => 'Profile saved.',
        'status_heading' => 'Status & dates',
        'subscribed' => 'Subscribed',
        'confirmed' => 'Confirmed',
        'unsubscribed' => 'Unsubscribed',
        'unsubscribe_reason' => 'Unsubscribe reason',
        'geo_heading' => 'Location',
        'geo_country' => 'Country',
        'geo_region' => 'Region',
        'geo_city' => 'City',
        'geo_timezone' => 'Timezone',
        'geo_resolved_from' => 'Resolved from IP',
        'geo_declared' => 'Declared country',
        'geo_unknown' => 'Unknown',
        'geo_no_provider' => 'No GeoIP provider is configured, so no location can be resolved. Ask your administrator to enable one under Location settings.',
        'geo' => 'Location',
        'consent_heading' => 'Consent record',
        'consent_text' => 'Consent text',
        'consent_granted' => 'Granted',
        'consent_not_granted' => 'Not granted',
        'consent_at' => 'Recorded at',
        'consent_ip' => 'Recorded from IP',
        'consent_logging_off' => 'Consent logging is disabled for this list.',
        'deliverability_heading' => 'Deliverability notes',
        'last_bounce' => 'Last bounce',
        'bounce_type' => 'Bounce type',
        'bounce_hard' => 'Hard',
        'bounce_soft' => 'Soft',
        'bounce_reason' => 'Last bounce reason',
        'bounces_count' => 'Total bounces',
        'complaints_count' => 'Spam complaints',
        'timeline_heading' => 'Campaign activity',
        'timeline_placeholder' => 'No campaign activity has been recorded for this subscriber yet.',
        'gdpr_heading' => 'GDPR erasure',
        'gdpr_description' => 'This anonymises the subscriber in place: the email is replaced with a placeholder, IPs are wiped and all custom field values are deleted. This cannot be undone.',
    ],

];
