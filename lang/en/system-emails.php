<?php

declare(strict_types=1);

// WP-20 — §3.15 system email templates: translatable code defaults (the
// bottom layer under the system_email_templates admin overrides) + the
// admin editor page strings. Bodies are HTML; [TAGS] are substituted by
// SystemEmailRenderer with HTML-escaped values.

return [

    'defaults' => [

        'registration-confirmation' => [
            'subject' => 'Confirm your [APP_NAME] account',
            'body' => '<p>Click the button below to confirm your email address and activate your account. This link expires in [EXPIRES_HOURS] hours.</p><p><a href="[CONFIRM_URL]">Confirm email address</a></p><p>If you did not create an account, no further action is required.</p>',
        ],

        'customer-welcome' => [
            'subject' => 'Welcome to [APP_NAME]',
            'body' => '<p>Hello [FIRST_NAME],</p><p>Your [APP_NAME] account is now active — welcome aboard!</p><p><a href="[LOGIN_URL]">Sign in to your account</a></p>',
        ],

        'password-reset' => [
            'subject' => 'Reset your [APP_NAME] password',
            'body' => '<p>You are receiving this email because we received a password reset request for your account. This link expires in [EXPIRES_MINUTES] minutes.</p><p><a href="[RESET_URL]">Reset password</a></p><p>If you did not request a password reset, no further action is required.</p>',
        ],

        'password-changed' => [
            'subject' => 'Your [APP_NAME] password was changed',
            'body' => '<p>The password for your account was just changed. If this was you, no action is needed.</p><p>If you did not change your password, please reset it immediately and contact support.</p>',
        ],

        'email-change-verification' => [
            'subject' => 'Confirm your new email address',
            'body' => '<p>Click the button below to confirm this as the new email address for your [APP_NAME] account. This link expires in [EXPIRES_HOURS] hours.</p><p><a href="[CONFIRM_URL]">Confirm new email address</a></p><p>If you did not request this change, you can ignore this email.</p>',
        ],

        'twofa-recovery-codes' => [
            'subject' => 'Your [APP_NAME] two-factor recovery codes were regenerated',
            'body' => '<p>New two-factor authentication recovery codes were just generated for your account. Your previous recovery codes no longer work.</p><p>If you did not do this, please secure your account immediately and contact support.</p>',
        ],

        'campaign-finished' => [
            'subject' => 'Campaign "[CAMPAIGN_NAME]" has finished sending',
            'body' => '<p>Your campaign "[CAMPAIGN_NAME]" finished sending: [SENT_COUNT] delivered, [FAILED_COUNT] failed, [TOTAL_COUNT] total recipients.</p>',
        ],

        // WP-18 — §3.15 F-3.15-007 campaign approval flow.
        'campaign-approval-request' => [
            'subject' => 'Campaign "[CAMPAIGN_NAME]" is awaiting approval',
            'body' => '<p>The campaign "[CAMPAIGN_NAME]" from [CUSTOMER_NAME] was submitted for sending and is awaiting your approval.</p><p><a href="[REVIEW_URL]">Review pending campaigns</a></p>',
        ],

        'campaign-approved' => [
            'subject' => 'Campaign "[CAMPAIGN_NAME]" was approved',
            'body' => '<p>Good news — your campaign "[CAMPAIGN_NAME]" was approved and is now scheduled for sending.</p>',
        ],

        'campaign-blocked' => [
            'subject' => 'Campaign "[CAMPAIGN_NAME]" was blocked',
            'body' => '<p>Your campaign "[CAMPAIGN_NAME]" was blocked by the administrators and will not be sent.</p><p>[REASON]</p><p>Please review the content and contact support if you believe this is a mistake.</p>',
        ],

        'campaign-changes-requested' => [
            'subject' => 'Campaign "[CAMPAIGN_NAME]" needs changes before it can be sent',
            'body' => '<p>Your campaign "[CAMPAIGN_NAME]" was reviewed and sent back to draft. It has not been blocked — edit it and submit it for approval again.</p><p>[REASON]</p>',
        ],

        'quota-warning' => [
            'subject' => 'You have used [PERCENT]% of your sending quota',
            'body' => '<p>Your [APP_NAME] account has used [USED] of its [LIMIT] monthly sending quota ([PERCENT]%).</p><p>Once the quota is reached, further campaign sending will pause until the quota resets or is raised.</p>',
        ],

        'quota-reached' => [
            'subject' => 'Your sending quota has been reached',
            'body' => '<p>Your [APP_NAME] account has reached its monthly sending quota of [LIMIT] emails ([USED] sent).</p><p>Campaign sending is paused until the quota resets or is raised. Contact support to increase your quota.</p>',
        ],

        'server-validation' => [
            'subject' => 'Confirm your delivery server "[SERVER_NAME]"',
            'body' => '<p>This message confirms that the delivery server "[SERVER_NAME]" can send email. Click the link below to activate it.</p><p><a href="[ACTIVATION_URL]">Activate this delivery server</a></p>',
        ],

        'sending-domain-verification' => [
            'subject' => 'Sending domain [DOMAIN_NAME]: [RESULT]',
            'body' => '<p>DNS verification for your sending domain <strong>[DOMAIN_NAME]</strong> finished with the result: [RESULT].</p><p>[DETAILS]</p>',
        ],

        'export-ready' => [
            'subject' => 'Your export "[EXPORT_NAME]" is ready',
            'body' => '<p>Your export "[EXPORT_NAME]" has finished and is ready to download.</p><p><a href="[DOWNLOAD_URL]">Download export</a></p><p>[EXPIRES_LINE]</p>',
        ],

        'import-finished' => [
            'subject' => 'Your import into "[LIST_NAME]" has finished',
            'body' => '<p>Your subscriber import into "[LIST_NAME]" has finished: [IMPORTED] imported, [UPDATED] updated, [SKIPPED] skipped, [FAILED] failed.</p>',
        ],

        'block-request-confirmation' => [
            'subject' => 'Confirm your email block request',
            'body' => '<p>Someone (hopefully you) asked us to never email this address again. Click the button below to confirm the request.</p><p><a href="[CONFIRM_URL]">Confirm block request</a></p><p>If you did not request this, simply ignore this email — nothing will change.</p>',
        ],

        'admin-heartbeat-stale' => [
            'subject' => 'Scheduler heartbeat is stale',
            'body' => '<p>The cron scheduler heartbeat has not been recorded recently (last seen: [LAST_SEEN]). Campaign sending and background jobs may be stopped — check the cron runner on the server.</p>',
        ],

    ],

    'results' => [
        'verified' => 'verified',
        'failed' => 'verification failed',
    ],

    'labels' => [
        'registration-confirmation' => 'Customer registration confirmation',
        'customer-welcome' => 'Customer welcome',
        'password-reset' => 'Password reset',
        'password-changed' => 'Password changed notice',
        'email-change-verification' => 'Email change verification',
        'twofa-recovery-codes' => '2FA recovery codes regenerated',
        'campaign-finished' => 'Campaign finished notification',
        'campaign-approval-request' => 'Campaign approval request (to admins)',
        'campaign-approved' => 'Campaign approved',
        'campaign-blocked' => 'Campaign blocked',
        'campaign-changes-requested' => 'Campaign sent back for changes',
        'quota-warning' => 'Sending quota warning',
        'quota-reached' => 'Sending quota reached',
        'server-validation' => 'Delivery server validation',
        'sending-domain-verification' => 'Sending domain verification result',
        'export-ready' => 'Export complete',
        'import-finished' => 'Import complete',
        'block-request-confirmation' => 'Email block request confirmation',
        'admin-heartbeat-stale' => 'Admin alert: scheduler heartbeat stale',
    ],

    'page' => [
        'title' => 'System email templates',
        'navigation_label' => 'System emails',
        'template' => 'Template',
        'subject' => 'Subject',
        'body' => 'HTML body',
        'enabled' => 'Use customized content',
        'enabled_help' => 'When off, the built-in default content is used — the email is still sent.',
        'state' => 'Content',
        'state_customized' => 'Customized for this installation',
        'state_default' => 'Using the built-in template',
        'merge_tags' => 'Available merge tags',
        'saved' => 'Template saved.',
        'reset' => 'Reset to default',
        'reset_confirm' => 'Discard the customized subject and body and return to the built-in template?',
        'reset_done' => 'Template reset to the built-in default.',
        'overview' => 'All templates',
        'overview_key' => 'Template',
        'overview_state' => 'State',
    ],

];
