# Testing Guide — page by page

This document is for *your* manual pass. It lists every screen, what it should
do, and — where it matters — the traps that make a working feature look broken.

---

## 0. Before you start (5 minutes)

```bash
cd email-marketing-laravel-filament
php artisan serve                 # http://127.0.0.1:8000
```

**Open a second and third terminal** — most of this app is asynchronous, and
without these two commands large parts will look dead when they are fine:

```bash
php artisan queue:work            # imports, exports, sends, bulk actions
php artisan schedule:work         # autoresponders, recurring campaigns, bounces, rollups
```

| Login | URL | Credentials |
|---|---|---|
| Admin | `/admin/login` | `test@example.com` / `password` |
| Customer | `/customer/login` | `demo@example.test` / `demo-password-1234` |

**Three things that are expected behaviour, not bugs:**
1. **No email leaves the machine.** `MAIL_MAILER=log` and no delivery server is
   configured — sends land in `storage/logs/laravel.log`. Every "did it send?"
   check means reading that file.
2. **Queued work needs the worker.** Without `queue:work`, imports/exports/sends
   sit in the `jobs` table forever. This is deliberate (nothing bulk runs in a
   web request).
3. **Geo/verification features degrade honestly.** With no GeoIP
   database or provider key they show "unknown"/disabled
   rather than inventing data.

If the UI ever loses its styling after a `composer update`, run
`php artisan filament:assets`.

---

## Demo data (optional)

An empty install is hard to judge, so a seeder fills the demo customer with realistic **core email** data. It is not part of `db:seed`; run it on demand:

```bash
php artisan migrate:fresh --seed
php artisan db:seed --class=DemoDataSeeder     # < 1 s on SQLite
```

It uses `demo@example.test` (created if missing) and adds: 4 lists with custom fields, ~600 subscribers (confirmed / unconfirmed / unsubscribed / bounced) with tags, 3 segments, 4 templates, 8 campaigns (4 sent with delivery/open/click/bounce/unsubscribe rows and daily stats, 2 scheduled, 2 drafts), 2 signup forms, blacklist + suppression entries, a delivery server on the `log` mailer and a sending domain. Automations, CRM, SMS and the other modules are deliberately left empty.

The data is deterministic (fixed Faker seed, dates relative to today), uses only `example.com/.net/.org` addresses, and is idempotent: running it again prints "already present" and changes nothing. To start over, run `migrate:fresh --seed` again.

---

## 1. Public pages (no login)

| Page | URL | What to check |
|---|---|---|
| Login / 2FA | `/customer/login` | Wrong password 5× → lockout; login history recorded |
| Forgot password | `/forgot-password` | Link email in the log, reset enforces the password policy, change notice sent |
| Subscribe form | `/lists/{list_uid}/subscribe` | Fields respect "visible on form"; honeypot silently drops bots |
| Double opt-in | after subscribing | Pending page → confirm link in the log → confirm page → welcome email |
| Update profile | `/lists/{uid}/{subscriber_uid}/update-profile` | Only "visible on profile" fields; email change validated |
| Unsubscribe | link in any email | Reason dropdown → confirm → success; subscriber flips to Unsubscribed |
| One-click unsubscribe | RFC 8058 | `POST /lists/{uid}/{sub_uid}/unsubscribe-one-click` (no CSRF) unsubscribes immediately |
| Block request | `/email-block-request` | Creates a pending request; confirm link emails; appears in the admin queue |
| Legal | `/legal/dpa`, `/legal/sub-processors` | Render; cookie banner appears if enabled |
| Installer | `/install` | **Should refuse** — an admin already exists |
| Health | `/health` | JSON with DB/cache/queue status |

**Worth probing:** open a list's public pages while the list is hidden — you
should get the branded "list not found" page, not a stack trace.

---

## 2. Customer panel (`/customer`)

### Dashboard & account
| Page | Check |
|---|---|
| Dashboard | Counters match reality; quota bars; growth + engagement charts; recent campaigns; activity feed; announcements |
| Usage & quotas | `/customer/usage` — used vs limit per resource, reset countdowns, 12-month history |
| Profile | Name/avatar/timezone/language; password change needs the current password; email change re-verifies |
| Security | 2FA enrol via QR + 10 recovery codes; active sessions revoke; login history |

### Lists
| Page | Check |
|---|---|
| Lists index | Per-status subscriber counts, filters, all 11 row actions open |
| Create/edit list | 5 tabs: General, Defaults, Notifications, Company, Subscriber actions. **Subscriber actions cascade** — subscribing to A can auto-subscribe to B; verify it fires |
| List overview | Status counters, growth chart, source split, **country tile cartogram** (see note below), recent campaigns with real stats |
| Custom fields | All 18 types; merge tag must be unique + uppercase; `EMAIL` is reserved; delete warns about data loss |
| Segments | Field/date/status conditions **and campaign conditions** (opened / didn't open / clicked / clicked a specific link, per campaign or any in last N days). Live count preview updates before saving |
| Import | 5 sources (CSV, paste, URL, another list, database). Column mapping, duplicate strategy, live progress, error CSV. **Try a CJK + emoji row — it must round-trip** |
| Export | Column picker, CSV **and XLSX**, emailed signed link, owner-only download |
| Embed forms | Generated HTML/iframe/JS; posting to the endpoint creates a subscriber |
| List pages | All 11 public page types editable, merge-tag panel, reset to default |
| List tools | Split by count/percent, sync two lists, mass delete with typed confirmation |

> **Country map note:** it's a *tile cartogram* — one equal-area square per
> country placed at its centroid, not a geographic outline. That's stated in the
> caption. A true boundary map needs a vendored Natural Earth file (D-91).

### Subscribers
Profile page, inline-editable fields, consent record, deliverability notes,
activity timeline, GDPR export/erase, engagement search, saved views,
resend-confirmation, send-single-email.
**Bulk actions are queued** — the table returns immediately and the change
appears once the worker processes it. That is correct, not a failure.

### Campaigns
| Step | Check |
|---|---|
| Index | Tabs by type/status, live progress bar while sending, row + bulk actions (queued) |
| Wizard 1 — Details | Type switches the form (autoresponder triggers / recurring cron with next-run preview); blacklisted subject words warn or block per settings |
| Wizard 2 — Content | GrapesJS builder, **basic builder**, raw HTML, rich text; merge-tag inserter; **spintax** `{Hi\|Hello}` with a permutation preview; autosave + 20 revisions |
| Wizard 3 — Options | Tracking, UTM, delivery servers, throttle, send windows, attachments, custom headers, suppression lists |
| Wizard 4 — Confirmation | Live recipient count, send test, **pre-flight checklist** (unsubscribe tag, subject, from, from-domain authentication, alt text, broken merge tags) |
| Report | Header stats, opens/clicks/bounces/unsubscribes/complaints/delivery-log tabs, over-time charts, geo, device/OS/client, **click map**, CSV/XLSX export, public share link |

**The most valuable end-to-end test:** create a campaign → send it → watch
`queue:work` → open `storage/logs/laravel.log` and confirm each recipient got
*different* rendered content (merge tags + spintax), a tracking pixel, rewritten
links, and `List-Unsubscribe` headers.

### Templates
- Grid/list, categories, tags, gallery copy, ZIP/URL import, hide-on-mobile,
  send test, export.

---

## 3. Admin panel (`/admin`)

| Area | Check |
|---|---|
| Dashboard | System counters, queue + cron health, pending items |
| Users / groups / roles | Permission matrix enforced at route level; locked system roles cannot be edited **even by Super Admin** |
| Settings | Common, import/export, campaign, customer, blacklist, 2FA, skins, CDN, **DKIM (generates a real 2048-bit key)**, cron, license, location, ops, security, privacy |
| Delivery servers | 20 driver types; "Validate" sends a confirmation email through the driver itself |
| Bounce / FBL servers | POP3/IMAP config, classification rules, processing logs |
| Domains | Sending domains show DKIM/SPF/DMARC records + Verify; tracking domains show the CNAME |
| Blacklist / suppression / verification | Global + per-customer, monitors, 8 verification providers |
| System emails | Every system email editable with override + reset-to-default |
| Languages | English + Italian + Japanese ship; in-app translation editor; RTL |
| Logs & monitoring | Delivery/bounce/complaint/login logs, error log viewer, failed jobs (retry works), queue monitor, system info |
| Ops | Health widgets, performance panel, backups, worker registry |

---

## 4. Security checks worth doing yourself

These are covered by automated tests, but they're the ones where a mistake is
most expensive — verify by hand:

1. **Locked roles.** As Super Admin, try to edit a system role — it must refuse.
2. **Signed URLs.** Tamper with a tracking or export URL — it must reject.
3. **Panel separation.** The workspace login must not open `/admin`, and the
   admin login must not open `/customer`.

---

## 5. What cannot be tested on this machine

Real ESP credentials, `spamd`, POP3/IMAP mailboxes, public DNS
for DKIM/tracking domains, a MaxMind database, MySQL for partitioning, Redis +
Horizon, a Stripo account, headless Chrome for thumbnails, and multi-node
infrastructure for the 1M-emails/hour benchmark.

Those features are built and tested against fakes.

---

## 6. Where to report what you find

- Decisions you disagree with → `docs/DECISIONS.md`. Every default is reversible.
- Open items → GitHub issues.
