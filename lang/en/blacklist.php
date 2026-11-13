<?php

declare(strict_types=1);

// WP-16 — blacklist + suppression module strings (spec §2.9.10, §2.9.11, §3.17, §3.18).

return [

    'nav_group' => 'Email hygiene',

    'admin_nav_group' => 'Deliverability',

    'resource' => [
        'label' => 'Blacklisted email',
        'plural_label' => 'Blacklist',
        'navigation_label' => 'Blacklist',
        'admin_navigation_label' => 'Email blacklist',
    ],

    'index' => [
        'email' => 'Email / domain',
        'type' => 'Type',
        'reason' => 'Reason',
        'source' => 'Source',
        'date' => 'Added',
        'customer' => 'Customer',
    ],

    'type' => [
        'email' => 'Email',
        'domain' => 'Domain',
    ],

    'sources' => [
        'manual' => 'Manual',
        'block-request' => 'Block request',
        'import' => 'Import',
        'bounce' => 'Bounce',
        'fbl' => 'Complaint (FBL)',
        'verification' => 'Verification',
    ],

    'form' => [
        'email' => 'Email address',
        'domain' => 'Domain',
        'is_domain' => 'Blacklist the whole domain',
        'is_domain_help' => 'Domain entries match every address at that domain (e.g. example.com).',
        'reason' => 'Reason',
        'emails' => 'Email addresses',
        'emails_help' => 'Paste addresses separated by new lines, commas, semicolons or spaces.',
        'file' => 'CSV file',
        'file_help' => 'First column: email address. Optional second column: reason.',
    ],

    'actions' => [
        'add' => 'Add to blacklist',
        'bulk_paste' => 'Bulk paste',
        'import' => 'Import CSV',
        'export' => 'Export CSV',
        'download_export' => 'Download export',
        'remove' => 'Remove',
        'remove_selected' => 'Remove selected',
        'test_rules' => 'Test an address',
    ],

    'notifications' => [
        'added' => ':count address(es) added to the blacklist.',
        'removed' => 'Entry removed from the blacklist.',
        'import_queued' => 'Blacklist import queued — entries will appear shortly.',
        'export_queued' => 'Blacklist export queued — use "Download export" once it is ready.',
        'export_missing' => 'No export file yet — queue an export first.',
    ],

    'tester' => [
        'heading' => 'Blacklist rule tester',
        'email' => 'Address to test',
        'help' => 'Checks exact rows, domain rows and the regex rules configured under Settings → Blacklist.',
        'match_exact' => 'Blocked — matches an exact blacklist entry.',
        'match_domain' => 'Blocked — matches a domain-level entry.',
        'match_regex' => 'Blocked — matches a regex rule.',
        'no_match' => 'Not blocked — no rule matches this address.',
    ],

    'log' => [
        'send_skipped' => 'Recipient is globally blacklisted',
    ],

    'csv' => [
        'email' => 'email',
        'type' => 'type',
        'reason' => 'reason',
        'source' => 'source',
        'created_at' => 'created_at',
        'customer' => 'customer',
    ],

    'suppression' => [
        'label' => 'Suppression list',
        'plural_label' => 'Suppression lists',
        'navigation_label' => 'Suppression lists',
        'name' => 'Name',
        'description' => 'Description',
        'emails_count' => 'Addresses',
        'updated_at' => 'Updated',
        'add_emails' => 'Add emails',
        'import_csv' => 'Import CSV',
        'import_from_list' => 'Import from list',
        'source_list' => 'Source list',
        'export' => 'Export',
        'download_export' => 'Download export',
        'emails' => 'Email addresses',
        'emails_help' => 'Paste addresses separated by new lines, commas, semicolons or spaces.',
        'email' => 'Email address',
        'added' => ':count address(es) added.',
        'import_queued' => 'Import queued — addresses will appear shortly.',
        'from_list_queued' => 'Import from list queued.',
        'export_queued' => 'Export queued — use "Download export" once it is ready.',
        'export_missing' => 'No export file yet — queue an export first.',
    ],

    'monitors' => [
        'label' => 'Blacklist monitor',
        'plural_label' => 'Blacklist monitors',
        'navigation_label' => 'Blacklist monitors',
        'name' => 'Name',
        'email_pattern' => 'Email pattern (regex)',
        'reason_pattern' => 'Reason pattern (regex)',
        'pattern_help' => 'Full PCRE pattern including delimiters, e.g. /@example\.com$/i. Matching entries are REMOVED from the global blacklist.',
        'enabled' => 'Enabled',
        'last_run_at' => 'Last run',
        'removals_count' => 'Removals',
        'run_now' => 'Run now',
        'ran' => ':count entr(y|ies) removed from the blacklist.',
        'need_pattern' => 'Give the monitor at least one pattern (email or reason).',
        'invalid_regex' => 'Not a valid regular expression (include delimiters, e.g. /…/).',
    ],

    'removals' => [
        'label' => 'Blacklist removal',
        'plural_label' => 'Blacklist removal log',
        'navigation_label' => 'Removal log',
        'email' => 'Email',
        'reason' => 'Original reason',
        'source' => 'Original source',
        'monitor' => 'Monitor',
        'removed_at' => 'Removed',
    ],

];
