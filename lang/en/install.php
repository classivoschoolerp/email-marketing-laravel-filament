<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Installer wizard (WP-24, spec §6.9 F-6.9-001)
|--------------------------------------------------------------------------
*/

return [

    'title' => 'Install',
    'continue' => 'Continue',
    'completed' => 'Installation finished. Sign in with the administrator account you just created.',

    'steps' => [
        'requirements' => 'Requirements',
        'database' => 'Database',
        'admin' => 'Administrator',
        'license' => 'License',
        'services' => 'Cron & queue',
    ],

    'requirements' => [
        'title' => 'Server requirements',
        'intro' => 'Everything below must pass before the installer can continue. Items marked * are recommended, not required.',
        'php' => 'PHP version',
        'memory' => 'memory_limit',
        'extensions' => 'PHP extensions',
        'optional_note' => '* recommended: intl (locale formatting), gd (image handling), redis (queues/cache), pcntl + posix (Horizon), imap (IMAP bounce polling).',
        'paths' => 'Writable directories',
        'required' => 'required: :value',
        'open_basedir' => 'open_basedir',
        'open_basedir_note' => 'This host restricts filesystem access. Keep the application, its storage directory and the backup directory inside the listed prefixes — see docs/install-cpanel.md.',
        'open_basedir_denied' => '(blocked by open_basedir)',
        'failed' => 'Fix the failing items above, then reload this page.',
    ],

    'database' => [
        'title' => 'Database connection',
        'intro' => 'The installer tests the connection, writes it to your .env, then runs migrations and seeders.',
        'driver' => 'Driver',
        'host' => 'Host',
        'port' => 'Port',
        'name' => 'Database name',
        'sqlite_hint' => 'For SQLite, enter the absolute path to the database file (it must already exist).',
        'username' => 'Username',
        'password' => 'Password',
        'app_url' => 'Application URL',
        'submit' => 'Test connection and install',
        'migrate_note' => 'This step creates every table and seeds the locked system roles, languages, currencies, gateways and the countries/zones datasets. It can take a minute.',
        'failed' => 'Could not connect: :error',
        'migrate_failed' => 'Migrations failed: :error',
        'sqlite_missing' => 'SQLite file not found at :path — create it first (touch the file), then retry.',
    ],

    'admin' => [
        'title' => 'Administrator account',
        'intro' => 'This account receives the locked Super Admin role.',
        'name' => 'Full name',
        'email' => 'Email address',
        'password' => 'Password',
        'password_hint' => 'At least 12 characters.',
        'password_confirmation' => 'Confirm password',
    ],

    'license' => [
        'title' => 'License key',
        'intro' => 'Enter the license key you were issued, or leave it empty.',
        'key' => 'License key',
        'self_hosted_hint' => 'Leave empty for a self-hosted installation with no license server configured — the platform treats that as valid.',
        'invalid' => 'The license server rejected this key.',
    ],

    'services' => [
        'title' => 'Cron & queue setup',
        'intro' => 'The platform is installed. Add these two services on the server — without them nothing sends.',
        'cron' => 'System cron (required)',
        'cron_note' => 'One entry, every minute. Laravel dispatches every §0.6 job from it.',
        'worker' => 'Queue worker (required)',
        'worker_note' => 'Run under Supervisor/systemd so it restarts automatically. Scale by adding processes, on this host or on dedicated sending nodes.',
        'heartbeat' => 'Worker heartbeat (recommended)',
        'heartbeat_note' => 'Keeps each node visible in the admin worker registry between jobs and honours the graceful drain flag.',
        'supervisor' => 'Supervisor program example',
        'finish' => 'Finish installation',
        'finish_note' => 'This writes the install marker; the wizard becomes unreachable afterwards.',
    ],

];
