# Query optimisation & indexes (§4.6 F-4.6-006)

Added by WP-26 in `database/migrations/2026_08_08_260005_add_performance_indexes.php`.
Every index below exists because a specific query needs it. Each one was checked with
`EXPLAIN QUERY PLAN` on SQLite (the dev/test driver, D-03) and the plans are asserted in
`tests/Feature/Performance/QueryIndexesTest.php`, so a future schema change that loses an
index fails the suite rather than silently degrading production.

## Already present before WP-26

| Table | Index | Why |
|---|---|---|
| `subscribers` | `subscribers_tenant_list_status_email` on `(customer_id, list_id, status, email)` | The exact shape the spec names in F-4.6-006. Shipped with the WP-06 subscribers migration |
| `subscribers` | `unique(list_id, email)`, `(list_id, status)`, `email` | Import dedupe, list screens, address lookup |
| `campaign_delivery_logs` | `unique(campaign_id, subscriber_id)` | Send idempotency (docs/ARCHITECTURE.md rule 6) |
| `campaigns` | `(customer_id, status)`, `(customer_id, type)`, `send_at`, `next_run_at` | Tenant campaign lists and the recurring scheduler |
| `orders` | `(status, created_at)`, `(customer_id, status)` | Admin order queue |

## Added by WP-26

| Index | Query it serves | Where |
|---|---|---|
| `campaigns_wave_due_idx` `(type, status, send_at)` | `type = regular AND status = scheduled AND send_at <= now()` | `CampaignsSendCommand::process()` — runs **every minute** |
| `campaigns_wave_batch_idx` `(type, status, send_batch_id)` | `type = regular AND status = sending AND (send_batch_id IS NULL OR = 'pending')` | Same command's stalled-wave self-heal, also every minute |
| `delivery_logs_campaign_status_created_idx` `(campaign_id, status, created_at)` | `campaign_id IN (…) AND status = 'sent' AND created_at >= month start`; report delivery tab; `CampaignStatsRollup` ranged group-by | `QuotaNotifier::monthlyUsage()`, `CampaignReportService::deliveryLogsQuery()`, `CampaignStatsRollup` |
| `track_opens_campaign_created_idx` `(campaign_id, created_at)` | `created_at BETWEEN … AND campaign_id IN (…) GROUP BY campaign_id` | `CampaignStatsRollup`, opens time-series |
| `track_clicks_campaign_created_idx` `(campaign_id, created_at)` | as above for clicks | `CampaignStatsRollup`, clicks time-series |
| `track_unsubs_campaign_created_idx` `(campaign_id, created_at)` | as above for unsubscribes | `CampaignStatsRollup` |
| `track_clicks_url_subscriber_idx` `(url_id, subscriber_uid)` | `count(distinct subscriber_uid) WHERE url_id = ?` | `CampaignReportService::clicksQuery()` unique-clicker subselect, once per URL |
| `subscribers_customer_status_idx` `(customer_id, status)` | tenant-wide counts that span every list — the leading `list_id` indexes cannot be used | dashboards, quota screens |
| `subscribers_customer_email_idx` `(customer_id, email)` | tenant-scoped address lookup; the bare `email` index is not tenant-scoped | profile search, blacklist/suppression checks |
| `automation_runs_status_waiting_idx` `(status, waiting_for)` | runs parked on an event, woken by the tick | `automations:tick` |
| `automation_runs_customer_status_idx` `(customer_id, status)` | tenant run lists | customer automation UI |
| `orders_customer_created_idx` `(customer_id, created_at)` | tenant billing history, newest first | customer Orders resource |

## Removed by WP-26

| Index | Why |
|---|---|
| `campaign_delivery_logs (campaign_id, status)` | A strict left prefix of `delivery_logs_campaign_status_created_idx`, so reads are unaffected — and this is the highest-insert-rate table in the system, where every redundant index is a write tax |

## MySQL notes (D-03)

- `subscribers`, `campaign_delivery_logs`, `campaign_track_opens` and `campaign_track_clicks`
  are monthly-partitioned by `php artisan db:partition`, which rewrites the primary key to
  `(id, created_at)`. MySQL requires the partition key inside every **unique** key; everything
  WP-26 adds is a plain secondary index, so partitioning is unaffected.
- SQLite reports the chosen index in `EXPLAIN QUERY PLAN`'s `detail` column; MySQL reports it
  in `EXPLAIN`'s `key` column. The plans should match — verifying that on a real MySQL 8 host
  is an e2e-checklist item.
- Index *health* (unused/duplicate detection) is a separate feature: `php artisan ops:index-health`.
