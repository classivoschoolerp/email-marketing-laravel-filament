<?php

declare(strict_types=1);

// WP-16 — email verification module strings (spec §2.9.12, D-12).

return [

    'resource' => [
        'label' => 'Verification run',
        'plural_label' => 'Email verification',
        'navigation_label' => 'Email verification',
    ],

    'status' => [
        'deliverable' => 'Deliverable',
        'undeliverable' => 'Undeliverable',
        'risky' => 'Risky',
        'unknown' => 'Unknown',
    ],

    'job_status' => [
        'pending' => 'Pending',
        'running' => 'Running',
        'completed' => 'Completed',
        'failed' => 'Failed',
    ],

    'target' => [
        'list' => 'Whole list',
        'segment' => 'Segment',
        'subscribers' => 'Selected subscribers',
        'single' => 'Single address',
    ],

    'auto_action' => [
        'tag' => 'Tag only',
        'delete' => 'Delete subscriber',
        'unsubscribe' => 'Unsubscribe subscriber',
        'blacklist' => 'Blacklist subscriber',
    ],

    'providers' => [
        'built_in' => 'Built-in engine',
    ],

    'index' => [
        'target' => 'Target',
        'list' => 'List',
        'provider' => 'Provider',
        'status' => 'Status',
        'progress' => 'Progress',
        'deliverable' => 'Deliverable',
        'undeliverable' => 'Undeliverable',
        'risky' => 'Risky',
        'unknown' => 'Unknown',
        'credits_used' => 'Credits used',
        'created_at' => 'Started',
        'finished_at' => 'Finished',
        'single_email' => 'Address',
    ],

    'form' => [
        'target' => 'Verify',
        'list' => 'List',
        'segment' => 'Segment',
        'provider' => 'Provider',
        'provider_default' => 'Account default',
        'auto_action' => 'When an address is undeliverable',
        'email' => 'Email address',
    ],

    'actions' => [
        'new' => 'New verification',
        'verify_single' => 'Verify an address',
        'settings' => 'Provider settings',
        'download_results' => 'Download results',
        'queued' => 'Verification queued.',
        'saved' => 'Verification settings saved.',
        'verify_selected' => 'Verify selected',
    ],

    'settings' => [
        'provider' => 'Verification provider',
        'api_key' => 'API key for :provider',
        'api_key_help' => 'Leave empty to use the operator\'s shared credentials (if any).',
    ],

    'credits' => [
        'remaining' => 'Verification credits remaining: :count',
        'unlimited' => 'Verification credits: unlimited',
    ],

    'single_result' => [
        'title' => 'Verification result',
        'body' => ':email — :status (:reason)',
    ],

    'realtime' => [
        'undeliverable' => 'This email address appears to be undeliverable.',
        'risky' => 'This email address was rejected as risky.',
    ],

    'errors' => [
        'no_credits' => 'No verification credits left.',
        'credits_exhausted' => 'Verification stopped: credits exhausted.',
        'unexpected' => 'Verification failed unexpectedly. Check the logs.',
    ],

    'csv' => [
        'email' => 'email',
        'status' => 'status',
        'reason' => 'reason',
        'provider' => 'provider',
        'checked_at' => 'checked_at',
    ],

    'blacklist_reason' => 'Verified undeliverable (email verification)',

    'subscribers' => [
        'verification_status' => 'Verification',
        'verified_at' => 'Verified at',
    ],

];
