<?php

declare(strict_types=1);

/*
 * WP-39 — §2.15.3 notification preferences + §4.3 in-app notifications.
 */
return [

    'channels' => [
        'in_app' => 'In-app',
        'email' => 'Email',
    ],

    'preferences' => [
        'title' => 'Notification preferences',
        'intro' => 'Choose how you hear about each event. Switching a channel off stops that notification being sent — it is not just hidden.',
        'save' => 'Save preferences',
        'saved' => 'Notification preferences saved.',
    ],

    'events' => [
        'campaign_finished' => [
            'label' => 'Campaign finished',
            'description' => 'A campaign has finished sending to its recipients.',
        ],
        'quota_nearing_limit' => [
            'label' => 'Quota nearing its limit',
            'description' => 'Your monthly sending quota is running low, or has been reached.',
        ],
        'bounce_spike' => [
            'label' => 'Bounce spike',
            'description' => 'The bounce rate of a campaign or sending server crossed its alert threshold.',
        ],
        'import_complete' => [
            'label' => 'Import complete',
            'description' => 'A subscriber import has finished processing.',
        ],
        'export_ready' => [
            'label' => 'Export ready',
            'description' => 'A file you asked to export is ready to download.',
        ],
        'new_subscriber' => [
            'label' => 'New subscriber',
            'description' => 'Somebody joined a list that has subscribe notifications switched on.',
        ],
        'payment_due' => [
            'label' => 'Payment due',
            'description' => 'A renewal payment failed, is due, or your plan is about to expire.',
        ],
    ],

    'in_app' => [
        'campaign_finished' => [
            'title' => 'Campaign sent',
            'body' => '":campaign" finished sending to :count recipients.',
            'action' => 'View report',
        ],
        'import_complete' => [
            'title' => 'Import finished',
            'body' => ':imported imported, :updated updated, :failed failed.',
            'action' => 'View import',
        ],
        'export_ready' => [
            'title' => 'Export ready',
            'body' => 'Your :type export is ready to download.',
            'action' => 'Download',
        ],
        'quota_warning' => [
            'title' => 'Sending quota running low',
            'body' => 'You have used :used of :limit sends this month.',
        ],
        'quota_reached' => [
            'title' => 'Sending quota reached',
            'body' => 'You have used your whole :limit-send monthly quota.',
        ],
        'bounce_spike' => [
            'title' => 'Bounce rate alert',
            'body' => ':subject is bouncing at :rate%, above the :threshold% threshold.',
        ],
        'new_subscriber' => [
            'title' => 'New subscriber',
            'body' => ':email joined ":list".',
        ],
        'payment_failed' => [
            'title' => 'Payment failed',
            'body' => 'We could not charge your renewal for ":plan" (attempt :attempt of :max).',
            'action' => 'Fix payment',
        ],
        'plan_expiring' => [
            'title' => 'Your plan expires soon',
            'body' => '":plan" expires on :date.',
            'action' => 'Renew',
        ],
        'grace_started' => [
            'title' => 'Your plan has expired',
            'body' => 'Your account keeps working until :date. Renew before then to avoid losing plan limits.',
            'action' => 'Renew',
        ],
    ],

    'announcements' => [
        'broadcast' => 'Broadcast to all customers',
        'broadcast_confirm' => 'Push this announcement to every customer\'s notification bell. This can only be done once.',
        'broadcast_done' => 'Broadcast to :count customer(s).',
        'broadcast_already' => 'This announcement has already been broadcast.',
        'broadcast_inactive' => 'Only an active announcement can be broadcast.',
        'broadcast_column' => 'Broadcast',
    ],
];
