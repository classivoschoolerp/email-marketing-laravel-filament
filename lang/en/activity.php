<?php

declare(strict_types=1);

/*
 * WP-39 — §2.15.7 account activity log.
 */
return [

    'label' => 'Activity entry',
    'plural_label' => 'Activity',
    'title' => 'Activity log',

    'actor_owner' => 'Account owner',
    'actor_system' => 'System',

    'fields' => [
        'when' => 'When',
        'actor' => 'User',
        'action' => 'Action',
        'subject' => 'Item',
        'description' => 'Details',
        'ip' => 'IP address',
        'from' => 'From',
        'until' => 'Until',
    ],

    'actions' => [
        'export' => 'Export',

        'list_created' => 'Created a list',
        'list_updated' => 'Updated a list',
        'list_deleted' => 'Deleted a list',
        'list_restored' => 'Restored a list',

        'subscriber_created' => 'Added a subscriber',
        'subscriber_updated' => 'Updated a subscriber',
        'subscriber_deleted' => 'Deleted a subscriber',
        'subscriber_restored' => 'Restored a subscriber',

        'campaign_created' => 'Created a campaign',
        'campaign_updated' => 'Updated a campaign',
        'campaign_deleted' => 'Deleted a campaign',
        'campaign_restored' => 'Restored a campaign',
        'campaign_sent' => 'Sent a campaign',

        'template_created' => 'Created a template',
        'template_updated' => 'Updated a template',
        'template_deleted' => 'Deleted a template',
        'template_restored' => 'Restored a template',

        'automation_created' => 'Created an automation',
        'automation_updated' => 'Updated an automation',
        'automation_deleted' => 'Deleted an automation',
        'automation_restored' => 'Restored an automation',

        'server_created' => 'Added a delivery server',
        'server_updated' => 'Updated a delivery server',
        'server_deleted' => 'Removed a delivery server',
        'server_restored' => 'Restored a delivery server',

        'domain_created' => 'Added a sending domain',
        'domain_updated' => 'Updated a sending domain',
        'domain_deleted' => 'Removed a sending domain',
        'domain_restored' => 'Restored a sending domain',

        'team_member_created' => 'Added a team member',
        'team_member_updated' => 'Updated a team member',
        'team_member_deleted' => 'Removed a team member',
        'team_member_restored' => 'Restored a team member',
        'team_member_invited' => 'Invited a team member',
        'team_member_invitation_resent' => 'Re-sent a team invitation',
        'team_member_accepted' => 'Team member accepted their invitation',
        'team_member_revoked' => 'Revoked a team member',
        'team_member_suspended' => 'Suspended a team member',
        'team_member_reinstated' => 'Reinstated a team member',

        'settings_updated' => 'Changed a setting',

        'subscription_cancelled' => 'Cancelled the subscription',
        'subscription_resumed' => 'Resumed the subscription',
        'subscription_plan_changed' => 'Changed plan',
        'subscription_auto_renew_changed' => 'Changed auto-renew',
        'subscription_payment_method_changed' => 'Changed the renewal payment method',

        'payment_method_added' => 'Saved a payment method',
        'payment_method_removed' => 'Removed a payment method',
        'payment_method_default_changed' => 'Changed the default payment method',

        'import_completed' => 'Finished an import',
        'import_rolled_back' => 'Rolled back an import',
        'export_completed' => 'Finished an export',
    ],

    'empty' => [
        'heading' => 'Nothing here yet',
        'description' => 'Actions taken on this account — creating lists, sending campaigns, changing settings — appear here.',
    ],
];
