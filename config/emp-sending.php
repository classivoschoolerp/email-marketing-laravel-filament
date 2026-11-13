<?php

declare(strict_types=1);

use App\Sending\Drivers\AmazonSesApiDriver;
use App\Sending\Drivers\BlastengineDriver;
use App\Sending\Drivers\BrevoDriver;
use App\Sending\Drivers\ElasticEmailDriver;
use App\Sending\Drivers\EximDriver;
use App\Sending\Drivers\GmailOauthDriver;
use App\Sending\Drivers\LoopsDriver;
use App\Sending\Drivers\MailtrapDriver;
use App\Sending\Drivers\MailgunDriver;
use App\Sending\Drivers\MailjetDriver;
use App\Sending\Drivers\PhpMailDriver;
use App\Sending\Drivers\PickupDirectoryDriver;
use App\Sending\Drivers\PostalDriver;
use App\Sending\Drivers\PostfixDriver;
use App\Sending\Drivers\PostmarkDriver;
use App\Sending\Drivers\PowerMtaDriver;
use App\Sending\Drivers\QmailDriver;
use App\Sending\Drivers\ResendDriver;
use App\Sending\Drivers\SendGridDriver;
use App\Sending\Drivers\SendmailDriver;
use App\Sending\Drivers\SmtpAmazonSesDriver;
use App\Sending\Drivers\SmtpDriver;
use App\Sending\Drivers\SparkPostDriver;

/*
|--------------------------------------------------------------------------
| Sending module (WP-14, spec §2.9.2)
|--------------------------------------------------------------------------
| Driver map: key => SendingDriver class. Keys are the D-21 canonical driver
| identifiers (CustomerSettings::SENDING_SERVER_TYPES). Plugins (spec §6.1)
| add their own drivers either by merging into this array from their service
| provider or by calling DriverRegistry::register() at boot.
*/

return [

    'drivers' => [
        'smtp' => SmtpDriver::class,
        'smtp-amazon-ses' => SmtpAmazonSesDriver::class,
        'amazon-ses-api' => AmazonSesApiDriver::class,
        'sendgrid-api' => SendGridDriver::class,
        'mailgun-api' => MailgunDriver::class,
        'sparkpost-api' => SparkPostDriver::class,
        'postmark-api' => PostmarkDriver::class,
        'elastic-email-api' => ElasticEmailDriver::class,
        'mailjet-api' => MailjetDriver::class,
        'brevo-api' => BrevoDriver::class,
        'postal' => PostalDriver::class,
        'powermta' => PowerMtaDriver::class,
        'php-mail' => PhpMailDriver::class,
        'pickup-directory' => PickupDirectoryDriver::class,
        'sendmail' => SendmailDriver::class,
        'gmail-oauth' => GmailOauthDriver::class,
        'blastengine' => BlastengineDriver::class,
        'postfix' => PostfixDriver::class,
        'exim' => EximDriver::class,
        'qmail' => QmailDriver::class,
        // F-2.9.2-013 (WP-43) — modern transactional ESPs.
        'resend-api' => ResendDriver::class,
        'loops-api' => LoopsDriver::class,
        'mailtrap-api' => MailtrapDriver::class,
    ],

    /*
    | SpamAssassin pre-send check (spec §2.9.2 / §2.3.5, docs/DECISIONS.md D-08).
    | CampaignSettings carries no spam threshold, so the gate reads its
    | bound here. enabled=false swaps in the NullChecker.
    */
    'spamassassin' => [
        'enabled' => (bool) env('SPAMASSASSIN_ENABLED', false),
        'host' => env('SPAMASSASSIN_HOST', '127.0.0.1'),
        'port' => (int) env('SPAMASSASSIN_PORT', 783),
        'timeout' => (int) env('SPAMASSASSIN_TIMEOUT', 10),
        // Block the send when the spamd score reaches this value. spamd's own
        // required_score still applies to the Spam: True/False verdict.
        'threshold' => (float) env('SPAMASSASSIN_THRESHOLD', 5.0),
    ],

    /*
    | Per-recipient retry fallbacks (spec §2.9.2). The live values come from
    | CampaignSettings (retry_max_attempts / retry_backoff_seconds); these are
    | only used pre-migration.
    */
    'retry' => [
        'max_attempts' => 3,
        'backoff_seconds' => 300,
    ],

    /*
    | Server validation (F-2.9.2-041): lifetime of the signed activation link.
    */
    'validation' => [
        'link_expires_hours' => 48,
    ],

    /*
    | WP-13 merge tags (spec §4.1): {FETCH_URL:...} remote includes.
    */
    'merge_tags' => [
        'fetch_timeout_seconds' => (int) env('MERGE_TAGS_FETCH_TIMEOUT', 5),
        'fetch_cache_seconds' => (int) env('MERGE_TAGS_FETCH_CACHE', 300),
    ],

    /*
    | WP-13 log retention (spec §0.6 logs:prune). The GDPR/privacy settings
    | group (§3.25.15, WP-22) will supersede these as the operator-facing
    | source; until then retention is env/config driven. 0 = keep forever.
    */
    'retention' => [
        'campaign_delivery_log_days' => (int) env('RETENTION_DELIVERY_LOG_DAYS', 90),
        'campaign_track_days' => (int) env('RETENTION_TRACK_DAYS', 180),
        'cron_run_days' => (int) env('RETENTION_CRON_RUN_DAYS', 30),
        'idempotency_key_days' => (int) env('RETENTION_IDEMPOTENCY_KEY_DAYS', 30),
    ],

    /*
    | WP-13 backups:run (spec §0.6): dump target + retention.
    */
    'backups' => [
        'directory' => env('BACKUPS_DIRECTORY'), // null = storage/backups
        'retention_days' => (int) env('BACKUPS_RETENTION_DAYS', 7),
    ],

    /*
    | WP-13 cron heartbeat monitoring (spec §0.6 F-0.6-018): alert admins when
    | the scheduler heartbeat is older than `stale_minutes`; re-alert at most
    | once per `alert_cooldown_minutes`.
    */
    'heartbeat' => [
        'stale_minutes' => (int) env('CRON_HEARTBEAT_STALE_MINUTES', 15),
        'alert_cooldown_minutes' => (int) env('CRON_HEARTBEAT_ALERT_COOLDOWN', 240),
    ],
];
