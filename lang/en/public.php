<?php

declare(strict_types=1);

// WP-09 — public frontend (spec §1.5 / §1.6 / §1.7 / §1.10) + the §3.16 /
// §3.19 admin surfaces.
return [

    'lists' => [
        'not_found_title' => 'List unavailable',
        'subscribe_success' => 'Thank you for subscribing!',
        'profile_updated' => 'Your profile has been updated.',
        'save_profile' => 'Update profile',
        'unsubscribing_as' => 'You are unsubscribing :email.',
        'reason_label' => 'Why are you unsubscribing? (optional)',
        'reason_text_label' => 'Anything you would like to add? (optional)',
        'unsubscribe_button' => 'Unsubscribe',
        'unsubscribe_confirm_button' => 'Yes, unsubscribe me',
        'one_click_done' => 'You have been unsubscribed.',

        'reasons' => [
            'no_longer_interested' => 'I am no longer interested',
            'too_many_emails' => 'I receive too many emails',
            'never_subscribed' => 'I never subscribed to this list',
            'content_not_relevant' => 'The content is not relevant to me',
            'other' => 'Other',
        ],

        // WP-42 §1.5 F-1.5-012 — preference center.
        'preferences_link' => 'Update your preferences instead of leaving',
        'preferences_as' => 'These preferences apply to :email.',
        'preferences_saved' => 'Your preferences have been saved.',
        'save_preferences' => 'Save preferences',
        'topics_label' => 'Topics',
        'topics_help' => 'Tick only the topics you want to hear about. Unticking one stops those emails without unsubscribing you from everything.',
        'frequency_label' => 'How often should we email you?',
        'pause_days_label' => 'Pause for how many days?',
        'pause_days_required' => 'Tell us how many days to pause your emails for.',
        'unsubscribe_all_link' => 'Unsubscribe from everything instead',

        'frequencies' => [
            'every_send' => 'Every email',
            'weekly' => 'At most one email a week',
            'monthly' => 'At most one email a month',
            'paused' => 'Pause my emails for a while',
        ],

        // WP-42 §1.5 F-1.5-013 — resubscribe page.
        'resubscribing_as' => 'You are resubscribing :email.',
        'resubscribe_button' => 'Resubscribe',
        'resubscribe_blocked' => 'This address cannot be resubscribed. Please contact us if you believe this is a mistake.',
    ],

    'campaigns' => [
        'open_web_view' => 'Read the full email',
    ],

    'block_requests' => [
        'title' => 'Never email me again',
        'intro' => 'Enter your email address and we will stop all emails from this platform to it, from any sender. We will send you one confirmation email first.',
        'submit' => 'Request email block',
        'sent' => 'Check your inbox — we sent you a link to confirm this request.',
        'confirmed_title' => 'Request confirmed',
        'confirmed_body' => 'Your request to block all emails to :email has been confirmed and will be processed.',
        'blacklist_reason' => 'Block requested by recipient',

        'status' => [
            'pending' => 'Pending confirmation',
            'confirmed' => 'Confirmed',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
        ],

        'mail' => [
            'subject' => 'Confirm your email block request',
            'intro' => 'Someone (hopefully you) asked us to never email this address again. Click the button below to confirm the request.',
            'button' => 'Confirm block request',
            'ignore' => 'If you did not request this, simply ignore this email — nothing will change.',
        ],

        'admin' => [
            'navigation_label' => 'Email block requests',
            'model_label' => 'Email block request',
            'plural_label' => 'Email block requests',
            'email' => 'Email',
            'status' => 'Status',
            'ip' => 'IP address',
            'confirmed_at' => 'Confirmed at',
            'requested_at' => 'Requested at',
            'approve' => 'Approve',
            'approved' => 'Request approved — address added to the global blacklist.',
            'reject' => 'Reject',
            'rejected' => 'Request rejected.',
            'bulk_approve' => 'Approve selected',
            'auto_approve_on' => 'Auto-approve is ON — confirmed requests are blacklisted automatically (EMP_BLOCK_REQUESTS_AUTO_APPROVE).',
            'auto_approve_off' => 'Auto-approve is OFF — confirmed requests wait here for review (EMP_BLOCK_REQUESTS_AUTO_APPROVE).',
        ],
    ],

    'page_defaults' => [
        'title' => 'List page defaults',
        'navigation_label' => 'List page defaults',
        'state_customized' => 'Customized for this installation',
        'state_translatable' => 'Using the built-in template',
        'saved' => 'Default saved — lists without their own override inherit it.',
        'reset' => 'Reset to built-in',
        'reset_confirm' => 'Discard the installation default and return to the built-in template?',
        'reset_done' => 'Default reset to the built-in template.',
    ],

    // Site root of a single-company install (no public website).
    'home' => [
        'intro' => 'Sign in to manage your lists, campaigns and templates.',
        'workspace_login' => 'Sign in to the workspace',
        'admin_login' => 'Administrator sign-in',
    ],

];
