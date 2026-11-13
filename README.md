# Email Marketing Laravel Platform

A self-hosted **email marketing platform** for a single company, built on **Laravel 12 +
Filament 3**. It ships with an admin panel for the operator and a customer panel (the
workspace) where your team builds lists, campaigns and templates.

- **Live demo:** https://marketing-platform.classivoerp.com
- **Licence:** MIT, free for commercial use
- **Maintained by:** [Classivo ERP](https://classivoerp.com)

---

## Features

### Email campaigns
- Regular, A/B (multi-variant), recurring and autoresponder campaigns, plus campaign groups
- Drag-and-drop email builder (GrapesJS), a basic block builder, raw HTML and rich text
- Template gallery, template categories, ZIP/URL import, AMP email and spintax
- Merge tags, conditional content, revisions and a pre-flight checklist before sending
- Send-time optimisation, throttling and send windows
- Spam check before sending (SpamAssassin) and inbox previews
- Reports: opens, clicks, bounces, complaints, unsubscribes, geography, devices, click map,
  campaign comparison, CSV/XLSX export, printable and shareable reports

### Lists and subscribers
- Unlimited lists, custom fields (18 types), segments with live counts, tags
- Import from CSV, pasted text, URL, another list or a database; CSV/XLSX export
- Double opt-in, subscription forms, embeddable forms and editable public list pages
- Subscriber profiles, activity timeline, GDPR export and erasure, preference centre
- Built-in email verification (syntax, MX, disposable, role accounts) plus external
  verifier adapters

### Sending infrastructure
- Multiple delivery servers (SMTP, API drivers and more) with groups and quotas
- Sending domains with DKIM, SPF and DMARC checks; tracking domains
- Bounce and feedback-loop processing, mailbox monitors
- Global and per-workspace blacklists, suppression lists, unsubscribe and block requests

### Admin panel
- Single company: one operator team and one customer workspace
- Users, user groups and roles with a permission matrix; locked system roles
- Settings, system email templates, languages and an in-app translation editor
- Logs, failed jobs, queue monitor, system info, backups, health and performance pages
- One-click upgrade manager

### Security
- Two-factor authentication, password policy and history, idle timeout, IP allowlists
- Upload guard with optional virus scanning
- Tenant fencing on every query at the model layer

---

## Requirements

- PHP 8.2+ (8.3 recommended) with the usual Laravel extensions
- MySQL 8 / MariaDB 10.6+ (SQLite works for local development)
- Node.js 20+ to build assets
- Redis is recommended for queues and cache in production

See [docs/requirements.md](docs/requirements.md).

## Quick start (Docker)

```bash
git clone https://github.com/classivoschoolerp/email-marketing-laravel-filament.git
cd email-marketing-laravel-filament
cp .env.docker .env
docker compose up -d
./docker/bootstrap.sh
```

## Quick start (local)

```bash
git clone https://github.com/classivoschoolerp/email-marketing-laravel-filament.git
cd email-marketing-laravel-filament
composer install
npm install && npm run build
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

In two more terminals, run the queue worker and the scheduler. Sending and imports
depend on them:

```bash
php artisan queue:work
php artisan schedule:work
```

- Admin panel: `/admin`
- Customer panel: `/customer`

The local seed creates an admin (`test@example.com` / `password`) and one workspace
(`demo@example.test` / `demo-password-1234`). For sample lists, campaigns and reports, run
`php artisan db:seed --class=DemoDataSeeder`. On a server, the web installer at `/install`
creates the admin and the workspace with the details you enter.

By default `MAIL_MAILER=log`, so no email leaves the machine until you add a delivery server.

## Installation guides

- [VPS / Linux server](docs/install-vps.md)
- [cPanel](docs/install-cpanel.md)
- [Plesk](docs/install-plesk.md)
- [MySQL deployment notes](docs/deploy-mysql.md)
- [Upgrades](docs/upgrades.md)

## Documentation

| Topic | Doc |
|---|---|
| Architecture rules | [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) |
| Design decisions | [docs/DECISIONS.md](docs/DECISIONS.md) |
| Manual test guide, screen by screen | [docs/TESTING-GUIDE.md](docs/TESTING-GUIDE.md) |
| Requirements and tech stack | [docs/requirements.md](docs/requirements.md), [docs/tech-stack.md](docs/tech-stack.md) |
| Search | [docs/search.md](docs/search.md) |
| Upload guard | [docs/upload-guard.md](docs/upload-guard.md) |
| Performance indexes | [docs/performance-indexes.md](docs/performance-indexes.md) |

## Running the tests

```bash
php artisan test
```

## Contributing

Bug reports and pull requests are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md)
first.

## Professional services

The software is free. If you'd like help with it, Classivo ERP offers:

- **Installation and setup** on your own server
- **A hosted version**, run for you as a managed service
- **Custom features** built on top of the platform

Contact: **info@classivoerp.com**

## Security

Please report vulnerabilities privately. See [SECURITY.md](SECURITY.md).

## Licence

Released under the [MIT License](LICENSE). Copyright © 2026 Classivo ERP.
