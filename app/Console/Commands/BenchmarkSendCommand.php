<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Enums\SubscriberStatus;
use App\Models\Campaign;
use App\Models\CampaignDeliveryLog;
use App\Models\Customer;
use App\Models\DeliveryServer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Sending\DriverRegistry;
use App\Sending\Drivers\NullDriver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * §4.6 F-4.6-009 throughput benchmark (WP-26).
 *
 * Seeds N confirmed subscribers, then drives the REAL send pipeline —
 * StartCampaignSendJob's recipient snapshot and row-locking, the batching,
 * SendToSubscriberJob per recipient (merge tags, spintax, tracking rewrite,
 * MIME build, delivery log, live counters) — with a NullDriver standing in
 * for the SMTP/API vendor. That isolates what this platform can push from
 * what a mail vendor will accept.
 *
 * WHAT THE NUMBERS MEAN, HONESTLY:
 *   - Reported throughput is for ONE worker on THIS machine with the queue
 *     driver currently configured. Under `QUEUE_CONNECTION=sync` (the test
 *     default) the whole wave runs inline in this process — that is a
 *     single-threaded measurement, not a cluster number.
 *   - The §4.6 "1M+ emails/hour" target is a MULTI-NODE claim. Extrapolating
 *     one worker's rate by the worker count is arithmetic, not evidence, so
 *     the command prints the extrapolation clearly labelled as such and the
 *     ledger row stays unverified until a real cluster measurement exists
 *     (D-14, docs/e2e-checklist.md).
 *   - The bottleneck line reports where wall-clock actually went (seeding vs
 *     snapshot/batching vs per-message work), not a guess.
 *
 * Destructive: it creates real rows. `--cleanup` (default) deletes them
 * again. Never point it at a production database.
 */
class BenchmarkSendCommand extends Command
{
    /** Whitelisted server type the null sink stands in for. */
    private const SERVER_TYPE = 'smtp';

    protected $signature = 'emp:benchmark-send
        {--subscribers=1000 : Recipients to seed and send to}
        {--batch-size=500 : Campaign batch size (0 = system default)}
        {--workers=1 : Worker count to extrapolate the hourly rate for}
        {--keep : Keep the seeded campaign, list and subscribers}
        {--json : Machine-readable output}';

    protected $description = 'Benchmark the real campaign send pipeline against a null delivery driver (§4.6 F-4.6-009).';

    public function handle(DriverRegistry $registry): int
    {
        $count = max(1, (int) $this->option('subscribers'));
        $workers = max(1, (int) $this->option('workers'));

        // The null sink replaces the SMTP driver for THIS process only.
        // It has to impersonate a real driver key: ServerPicker enforces the
        // D-21/D-47 type whitelist, and weakening that check just to run a
        // benchmark would be the wrong trade.
        $driver = new NullDriver(self::SERVER_TYPE);
        $registry->register(self::SERVER_TYPE, $driver);

        $seedStartedAt = microtime(true);
        [$customer, $list, $campaign] = $this->seed($count);
        $seedSeconds = microtime(true) - $seedStartedAt;

        if (! (bool) $this->option('json')) {
            $this->line("Seeded {$count} subscribers in ".$this->seconds($seedSeconds));
        }

        // --- the measured window: the real send wave -----------------
        $sendStartedAt = microtime(true);
        $this->callSilent('campaigns:send');
        $sendSeconds = max(0.000001, microtime(true) - $sendStartedAt);

        $delivered = CampaignDeliveryLog::query()->where('campaign_id', $campaign->id)->count();
        $sent = $driver->count;

        $perSecond = $sent / $sendSeconds;
        $perMinute = $perSecond * 60;
        $perHour = $perSecond * 3600;

        $results = [
            'subscribers_seeded' => $count,
            'messages_sent' => $sent,
            'delivery_log_rows' => $delivered,
            'seed_seconds' => round($seedSeconds, 3),
            'send_seconds' => round($sendSeconds, 3),
            'messages_per_minute' => (int) round($perMinute),
            'messages_per_hour' => (int) round($perHour),
            'workers_assumed' => $workers,
            // Deliberately the reported hourly rate times the worker count —
            // plain arithmetic the reader can verify, not a hidden formula.
            'extrapolated_per_hour' => (int) round($perHour) * $workers,
            'queue_connection' => (string) config('queue.default'),
            'db_driver' => (string) DB::connection()->getDriverName(),
            'bottleneck' => $this->bottleneck($seedSeconds, $sendSeconds, $sent),
        ];

        if (! (bool) $this->option('keep')) {
            $this->cleanup($customer);
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($results, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(['Metric', 'Value'], collect($results)->map(
            fn ($value, $key): array => [$key, is_int($value) ? number_format($value) : (string) $value],
        )->values()->all());

        $this->newLine();
        $this->warn('Single-process measurement on this machine. The extrapolated hourly rate is arithmetic');
        $this->warn('(rate x workers), NOT a verified cluster figure — see docs/e2e-checklist.md (D-14).');

        return self::SUCCESS;
    }

    /** @return array{0: Customer, 1: EmailList, 2: Campaign} */
    private function seed(int $count): array
    {
        $customer = Customer::factory()->create([
            'email' => 'benchmark-'.Str::random(8).'@benchmark.invalid',
        ]);

        $list = EmailList::factory()->create(['customer_id' => $customer->id]);

        $server = DeliveryServer::factory()->create([
            'customer_id' => $customer->id,
            'type' => self::SERVER_TYPE,
        ]);

        // Bulk insert: seeding must not dominate the measurement.
        $now = now();
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'uid' => (string) Str::uuid(),
                'customer_id' => $customer->id,
                'list_id' => $list->id,
                'email' => 'bench'.$i.'@benchmark.invalid',
                'status' => SubscriberStatus::Confirmed->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) >= 1000) {
                Subscriber::withoutTenancy()->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            Subscriber::withoutTenancy()->insert($rows);
        }

        $batchSize = (int) $this->option('batch-size');

        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'email_list_id' => $list->id,
            'type' => CampaignType::Regular,
            'status' => CampaignStatus::Scheduled,
            'send_at' => now()->subMinute(),
            'batch_size' => $batchSize > 0 ? $batchSize : null,
            'batch_interval_seconds' => 0,
            'delivery_server_ids' => [$server->id],
            'subject' => 'Benchmark',
            'content_html' => '<html><body><p>Hello {FNAME|there}</p>'
                .'<p><a href="{UNSUBSCRIBE_URL}">Unsubscribe</a></p></body></html>',
        ]);

        return [$customer, $list, $campaign];
    }

    /** Where the wall clock actually went. */
    private function bottleneck(float $seedSeconds, float $sendSeconds, int $sent): string
    {
        if ($sent === 0) {
            return 'nothing was sent — check the campaign/list/server setup';
        }

        $perMessageMs = $sendSeconds / $sent * 1000;

        return $seedSeconds > $sendSeconds
            ? sprintf('database writes (seeding %s vs send %s); per message %.2f ms',
                $this->seconds($seedSeconds), $this->seconds($sendSeconds), $perMessageMs)
            : sprintf('per-message pipeline work: %.2f ms/message on the %s queue driver',
                $perMessageMs, config('queue.default'));
    }

    private function cleanup(Customer $customer): void
    {
        // FK cascades take the list, subscribers, campaign, logs and server.
        Customer::query()->whereKey($customer->id)->delete();
    }

    private function seconds(float $seconds): string
    {
        return number_format($seconds, 3).'s';
    }
}
