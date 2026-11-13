<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Enums\CustomerStatus;
use App\Enums\SubscriberSource;
use App\Enums\SubscriberStatus;
use App\Enums\TemplateBuilder;
use App\Models\Campaign;
use App\Models\CampaignDeliveryLog;
use App\Models\CampaignTrackClick;
use App\Models\CampaignTrackOpen;
use App\Models\CampaignTrackUrl;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\EmailTemplate;
use App\Models\Subscriber;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * `emp:demo-seed` (spec §6.9 F-6.9-006, WP-24): a seeded demo dataset for
 * evaluations and screenshots — one customer, one list with subscribers, a
 * template, and a *sent* campaign carrying plausible delivery/open/click
 * numbers so the reports and dashboards are not empty on a fresh install.
 *
 * Deliberately does NOT use model factories: `fakerphp/faker` is a
 * require-dev package, so a production install (`composer install --no-dev`)
 * would fatal. Every value here is literal and deterministic.
 *
 * Idempotent: re-running finds the demo customer by email and skips unless
 * `--fresh` is passed, which deletes the demo customer's data first.
 */
class DemoSeedCommand extends Command
{
    public const DEMO_EMAIL = 'demo@example.test';

    protected $signature = 'emp:demo-seed
        {--subscribers=25 : how many demo subscribers to create}
        {--fresh : delete the existing demo dataset first}';

    protected $description = 'Create the §6.9 demo dataset (customer, list, subscribers, template, campaign).';

    public function handle(CurrentCustomer $currentCustomer): int
    {
        $existing = Customer::query()->where('email', self::DEMO_EMAIL)->first();

        if ($existing !== null && ! $this->option('fresh')) {
            $this->warn(__('ops.demo.exists', ['email' => self::DEMO_EMAIL]));

            return self::SUCCESS;
        }

        if ($existing !== null) {
            $this->purge($existing);
        }

        $count = max(1, min(1000, (int) $this->option('subscribers')));

        $customer = $this->createCustomer();
        $currentCustomer->set($customer);

        $list = $this->createList($customer);
        $subscribers = $this->createSubscribers($customer, $list, $count);
        $template = $this->createTemplate($customer);
        $campaign = $this->createCampaign($customer, $list, $template, $subscribers);

        $this->info(__('ops.demo.created', [
            'email' => self::DEMO_EMAIL,
            'subscribers' => count($subscribers),
            'campaign' => (string) $campaign->name,
        ]));

        $this->line(__('ops.demo.password_note'));

        return self::SUCCESS;
    }

    private function purge(Customer $customer): void
    {
        $campaignIds = Campaign::withoutTenancy()->where('customer_id', $customer->id)->pluck('id');

        CampaignDeliveryLog::query()->whereIn('campaign_id', $campaignIds)->delete();
        DB::table('campaign_track_opens')->whereIn('campaign_uid', Campaign::withoutTenancy()->where('customer_id', $customer->id)->pluck('uid'))->delete();
        DB::table('campaign_track_clicks')->whereIn('campaign_uid', Campaign::withoutTenancy()->where('customer_id', $customer->id)->pluck('uid'))->delete();
        DB::table('campaign_track_urls')->whereIn('campaign_uid', Campaign::withoutTenancy()->where('customer_id', $customer->id)->pluck('uid'))->delete();

        Campaign::withoutTenancy()->where('customer_id', $customer->id)->forceDelete();
        Subscriber::withoutTenancy()->where('customer_id', $customer->id)->delete();
        EmailTemplate::withoutTenancy()->where('customer_id', $customer->id)->delete();
        EmailList::withoutTenancy()->where('customer_id', $customer->id)->delete();

        $customer->forceDelete();
    }

    private function createCustomer(): Customer
    {
        /** @var Customer $customer */
        $customer = Customer::query()->create([
            'first_name' => 'Demo',
            'last_name' => 'Account',
            'email' => self::DEMO_EMAIL,
            'password' => 'demo-password-1234',
            'company' => 'Demo Company',
            'country' => 'US',
            'timezone' => 'UTC',
            'locale' => 'en',
            'status' => CustomerStatus::Active,
            'email_verified_at' => Carbon::now(),
        ]);

        return $customer;
    }

    private function createList(Customer $customer): EmailList
    {
        /** @var EmailList $list */
        $list = EmailList::withoutTenancy()->create([
            'customer_id' => $customer->id,
            'name' => 'Demo Newsletter',
            'description' => 'Sample list created by emp:demo-seed.',
            'opt_in' => 'single',
            'opt_out' => 'single',
            'from_name' => 'Demo Company',
            'from_email' => 'news@example.test',
        ]);

        return $list;
    }

    /** @return list<Subscriber> */
    private function createSubscribers(Customer $customer, EmailList $list, int $count): array
    {
        $subscribers = [];

        for ($i = 1; $i <= $count; $i++) {
            $subscribers[] = Subscriber::withoutTenancy()->create([
                'customer_id' => $customer->id,
                'list_id' => $list->id,
                'email' => sprintf('demo.subscriber%02d@example.test', $i),
                'status' => SubscriberStatus::Confirmed,
                'source' => SubscriberSource::Import,
                'subscribed_at' => Carbon::now()->subDays($count - $i + 1),
            ]);
        }

        return $subscribers;
    }

    private function createTemplate(Customer $customer): EmailTemplate
    {
        /** @var EmailTemplate $template */
        $template = EmailTemplate::withoutTenancy()->create([
            'customer_id' => $customer->id,
            'name' => 'Demo Newsletter Template',
            // GrapesJS, not Html: the row action opens whichever builder the
            // template was saved with, so a demo seeded as raw HTML lands the
            // first-time user in a code editor and reads as "the drag-and-drop
            // builder is missing". The demo should show the default builder.
            'builder' => TemplateBuilder::GrapesJs,
            'content_html' => '<html><body><h1>Hello {SUBSCRIBER_NAME}</h1>'
                .'<p>This is a demo campaign. <a href="https://example.test/offer">See the offer</a>.</p>'
                .'<p><a href="{UNSUBSCRIBE_URL}">Unsubscribe</a></p></body></html>',
        ]);

        return $template;
    }

    /** @param list<Subscriber> $subscribers */
    private function createCampaign(Customer $customer, EmailList $list, EmailTemplate $template, array $subscribers): Campaign
    {
        $total = count($subscribers);
        $failed = (int) floor($total * 0.04);
        $sent = $total - $failed;

        /** @var Campaign $campaign */
        $campaign = Campaign::withoutTenancy()->create([
            'customer_id' => $customer->id,
            'email_list_id' => $list->id,
            'name' => 'Demo Spring Newsletter',
            'type' => CampaignType::Regular,
            'status' => CampaignStatus::Sent,
            'from_name' => 'Demo Company',
            'from_email' => 'news@example.test',
            'subject' => 'Your spring update is here',
            'builder' => TemplateBuilder::Html,
            'content_html' => (string) $template->content_html,
            'track_opens' => true,
            'track_clicks' => true,
            'delivery_total' => $total,
            'delivery_sent' => $sent,
            'delivery_failed' => $failed,
            'send_started_at' => Carbon::now()->subDays(2),
            'send_finished_at' => Carbon::now()->subDays(2)->addMinutes(11),
        ]);

        $url = CampaignTrackUrl::query()->create([
            'campaign_uid' => $campaign->uid,
            'url' => 'https://example.test/offer',
            'hash' => substr(hash('sha256', 'demo-offer-'.$campaign->uid), 0, 40),
        ]);

        foreach ($subscribers as $index => $subscriber) {
            $isFailed = $index >= $sent;

            CampaignDeliveryLog::query()->create([
                'campaign_id' => $campaign->id,
                'subscriber_id' => $subscriber->id,
                'status' => $isFailed ? 'failed' : 'sent',
                'message_id' => $isFailed ? null : 'demo-'.$campaign->uid.'-'.$subscriber->id,
                'response' => $isFailed ? 'Demo bounce: mailbox unavailable' : 'Demo delivery accepted',
                'created_at' => Carbon::now()->subDays(2),
                'updated_at' => Carbon::now()->subDays(2),
            ]);

            if ($isFailed) {
                continue;
            }

            // ~48% open rate, ~15% click rate — plausible newsletter numbers.
            if ($index % 2 === 0) {
                CampaignTrackOpen::query()->create([
                    'campaign_uid' => $campaign->uid,
                    'subscriber_uid' => $subscriber->uid,
                    'ip' => '198.51.100.'.(($index % 250) + 1),
                    'user_agent' => 'Mozilla/5.0 (demo)',
                    'device' => 'desktop',
                    'os' => 'Linux',
                    'browser' => 'Firefox',
                    'created_at' => Carbon::now()->subDays(2)->addMinutes($index),
                ]);
            }

            if ($index % 7 === 0) {
                CampaignTrackClick::query()->create([
                    'campaign_uid' => $campaign->uid,
                    'subscriber_uid' => $subscriber->uid,
                    'url_id' => $url->id,
                    'ip' => '198.51.100.'.(($index % 250) + 1),
                    'user_agent' => 'Mozilla/5.0 (demo)',
                    'device' => 'desktop',
                    'os' => 'Linux',
                    'browser' => 'Firefox',
                    'created_at' => Carbon::now()->subDays(2)->addMinutes($index + 3),
                ]);
            }
        }

        return $campaign;
    }
}
