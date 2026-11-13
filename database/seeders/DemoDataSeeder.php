<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Console\Commands\DemoSeedCommand;
use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Enums\CustomerStatus;
use App\Enums\DeliveryServerStatus;
use App\Enums\DomainStatus;
use App\Enums\FormStatus;
use App\Enums\FormType;
use App\Enums\ListFieldType;
use App\Enums\QuotaExceededAction;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentMatchType;
use App\Enums\SegmentOperator;
use App\Enums\ServerProtocol;
use App\Enums\TemplateBuilder;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\DeliveryServer;
use App\Models\EmailList;
use App\Models\EmailTemplate;
use App\Models\Form;
use App\Models\FormField;
use App\Models\ListField;
use App\Models\ListFieldOption;
use App\Models\ListSegment;
use App\Models\ListSegmentCondition;
use App\Models\SendingDomain;
use App\Models\SuppressionList;
use App\Support\Tenancy\CurrentCustomer;
use Faker\Factory as FakerFactory;
use Faker\Generator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Realistic demo data for the CORE email features only: lists, custom fields,
 * subscribers, tags, segments, campaigns + tracking/daily stats, templates,
 * signup forms, blacklist/suppression, a delivery server (log mailer) and a
 * sending domain. Fills the demo customer (demo@example.test), creating it
 * if missing.
 *
 * Run:  php artisan db:seed --class=DemoDataSeeder
 *
 * Deterministic (fixed Faker seed; dates are relative to "now"), idempotent
 * (skips when the demo customer already has the "Weekly Newsletter" list) and
 * tenant-correct (customer_id set explicitly + CurrentCustomer bound). Bulk
 * rows go through the query builder, so no model events (autoresponders,
 * welcome mails) fire. Not called from DatabaseSeeder. Fictional names and
 * example.com/.net/.org addresses only.
 */
class DemoDataSeeder extends Seeder
{
    private const SEED = 20261114;

    private const SENTINEL_LIST = 'Weekly Newsletter';

    private Generator $faker;

    private Carbon $now;

    private int $cid;

    public function run(): void
    {
        $this->faker = FakerFactory::create('en_US');
        $this->faker->seed(self::SEED);
        mt_srand(self::SEED);
        $this->now = Carbon::now()->startOfMinute();

        $customer = Customer::query()->where('email', DemoSeedCommand::DEMO_EMAIL)->first()
            ?? Customer::query()->create([
                'first_name' => 'Demo',
                'last_name' => 'Account',
                'email' => DemoSeedCommand::DEMO_EMAIL,
                'password' => 'demo-password-1234',
                'company' => 'Demo Company',
                'country' => 'US',
                'timezone' => 'UTC',
                'locale' => 'en',
                'status' => CustomerStatus::Active,
                'email_verified_at' => $this->now,
            ]);
        $this->cid = $customer->id;

        if (EmailList::withoutTenancy()->where('customer_id', $this->cid)->where('name', self::SENTINEL_LIST)->exists()) {
            $this->command?->warn('Demo data already present for '.DemoSeedCommand::DEMO_EMAIL.' - skipping.');

            return;
        }

        app(CurrentCustomer::class)->runAs($customer, function (): void {
            DB::transaction(function (): void {
                $domain = $this->sendingDomain();
                $server = $this->deliveryServer($domain);
                $lists = $this->lists();
                $fields = $this->fields($lists);
                $plan = $this->campaignPlan();
                $subs = $this->subscribers($lists, $fields, $plan);
                $segments = $this->segments($lists, $fields);
                $templates = $this->templates();
                $this->campaigns($lists, $subs, $plan, $templates, $segments, $server);
                $this->forms($lists, $fields);
                $this->blacklist();
            });
        });

        $this->command?->info('Demo data seeded for '.DemoSeedCommand::DEMO_EMAIL.'.');
    }

    private function sendingDomain(): SendingDomain
    {
        return SendingDomain::withoutTenancy()->create([
            'customer_id' => $this->cid,
            'name' => 'news.example.com',
            'dkim_selector' => 'mail',
            'dkim_private_key' => "-----BEGIN PRIVATE KEY-----\nMIIdemoplaceholderkey\n-----END PRIVATE KEY-----",
            'dkim_public_key' => "-----BEGIN PUBLIC KEY-----\nMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAdemoplaceholderpublickey\n-----END PUBLIC KEY-----",
            'status' => DomainStatus::Verified,
            'dkim_verified' => true,
            'spf_verified' => true,
            'dmarc_verified' => true,
            'mx_verified' => true,
            'last_checked_at' => $this->now->copy()->subHours(3),
        ]);
    }

    private function deliveryServer(SendingDomain $domain): DeliveryServer
    {
        // Type "log" = Laravel's log mailer: nothing leaves the machine.
        return DeliveryServer::withoutTenancy()->create([
            'uid' => $this->faker->uuid(),
            'customer_id' => $this->cid,
            'type' => 'log',
            'name' => 'Demo log mailer',
            'protocol' => ServerProtocol::None,
            'from_email' => 'news@news.example.com',
            'from_name' => 'Demo Company',
            'reply_to_email' => 'hello@news.example.com',
            'probability' => 100,
            'hourly_quota' => 5000,
            'daily_quota' => 50000,
            'quota_exceeded_action' => QuotaExceededAction::Pause,
            'sending_domain_id' => $domain->id,
            'status' => DeliveryServerStatus::Active,
            'config' => json_encode(['mailer' => 'log']),
            'validated_at' => $this->now->copy()->subDay(),
        ]);
    }

    /** @return array<string, EmailList> */
    private function lists(): array
    {
        $defs = [
            'news' => [self::SENTINEL_LIST, 'Company news, tips and the weekly digest.', 'single'],
            'product' => ['Product Updates', 'Release notes and feature announcements.', 'double'],
            'webinar' => ['Webinar Attendees', 'People who registered for our live sessions.', 'single'],
            'beta' => ['Beta Testers', 'Early-access programme members.', 'double'],
        ];
        $lists = [];

        foreach ($defs as $key => [$name, $desc, $optIn]) {
            $lists[$key] = EmailList::withoutTenancy()->create([
                'uid' => $this->faker->uuid(),
                'customer_id' => $this->cid,
                'name' => $name,
                'description' => $desc,
                'opt_in' => $optIn,
                'opt_out' => 'single',
                'from_name' => 'Demo Company',
                'from_email' => 'news@news.example.com',
                'reply_to' => 'hello@news.example.com',
                'default_subject' => $name,
                'company_name' => 'Demo Company',
                'company_address_1' => '100 Example Street',
                'company_city' => 'Springfield',
                'company_country' => 'US',
                'company_zip' => '00000',
                'list_tags' => ['demo'],
            ]);
        }

        return $lists;
    }

    /** @return array<string, array<string, ListField>> list key => tag => field */
    private function fields(array $lists): array
    {
        $defs = [
            ['FNAME', 'First name', ListFieldType::Text, true],
            ['LNAME', 'Last name', ListFieldType::Text, false],
            ['COMPANY', 'Company', ListFieldType::Text, false],
            ['PLAN', 'Plan', ListFieldType::Dropdown, false],
            ['SIGNUP_DATE', 'Signup date', ListFieldType::Date, false],
        ];
        $out = [];

        foreach ($lists as $key => $list) {
            foreach ($defs as $i => [$tag, $label, $type, $required]) {
                $field = ListField::withoutTenancy()->create([
                    'customer_id' => $this->cid,
                    'list_id' => $list->id,
                    'type' => $type,
                    'label' => $label,
                    'tag' => $tag,
                    'required' => $required,
                    'visible_on_form' => $tag !== 'SIGNUP_DATE',
                    'visible_on_profile' => true,
                    'show_in_table' => $i < 4,
                    'sort_order' => $i,
                ]);

                if ($tag === 'PLAN') {
                    foreach (['free' => 'Free', 'pro' => 'Pro', 'business' => 'Business'] as $n => $optLabel) {
                        ListFieldOption::withoutTenancy()->create([
                            'customer_id' => $this->cid,
                            'list_field_id' => $field->id,
                            'label' => $optLabel,
                            'value' => $n,
                            'sort_order' => array_search($n, ['free', 'pro', 'business'], true),
                        ]);
                    }
                }

                $out[$key][$tag] = $field;
            }
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> */
    private function campaignPlan(): array
    {
        return [
            'n1' => ['list' => 'news', 'status' => 'sent', 'days' => -45, 'name' => 'October digest', 'subject' => 'Your October digest is here', 'tpl' => 0, 'open' => 0.41],
            'p1' => ['list' => 'product', 'status' => 'sent', 'days' => -30, 'name' => 'Dashboards 2.0 launch', 'subject' => 'Introducing Dashboards 2.0', 'tpl' => 1, 'open' => 0.47],
            'n2' => ['list' => 'news', 'status' => 'sent', 'days' => -17, 'name' => 'Subject line tips', 'subject' => 'Five tips for better subject lines', 'tpl' => 0, 'open' => 0.36],
            'w1' => ['list' => 'webinar', 'status' => 'sent', 'days' => -8, 'name' => 'Webinar recording', 'subject' => 'Recording: Email deliverability basics', 'tpl' => 2, 'open' => 0.52],
            'n3' => ['list' => 'news', 'status' => 'scheduled', 'days' => 3, 'name' => 'Weekly digest (scheduled)', 'subject' => 'This week at Demo Company', 'tpl' => 0, 'segment' => 'pro'],
            'p2' => ['list' => 'product', 'status' => 'scheduled', 'days' => 6, 'name' => 'Release notes 2.1', 'subject' => 'What is new in 2.1', 'tpl' => 1],
            'n4' => ['list' => 'news', 'status' => 'draft', 'days' => 0, 'name' => 'Holiday offer (draft)', 'subject' => 'A little thank-you for the holidays', 'tpl' => 3],
            'w2' => ['list' => 'webinar', 'status' => 'draft', 'days' => 0, 'name' => 'Next webinar invite (draft)', 'subject' => 'Join our next live session', 'tpl' => 2],
        ];
    }

    /**
     * @return array<string, list<array<string, mixed>>> list key => subscriber rows (with ids)
     */
    private function subscribers(array $lists, array $fields, array $plan): array
    {
        $counts = ['news' => 250, 'product' => 170, 'webinar' => 110, 'beta' => 70];
        $tagPools = [
            'news' => ['newsletter', 'customer', 'lead', 'vip'],
            'product' => ['customer', 'release-notes', 'power-user', 'trial'],
            'webinar' => ['webinar-live', 'webinar-replay', 'lead', 'partner'],
            'beta' => ['beta', 'feedback-given', 'power-user'],
        ];
        $domains = ['example.com', 'example.com', 'example.net', 'example.org'];
        $countries = ['US', 'GB', 'DE', 'CA', 'AU', 'IN'];
        $now = $this->now;
        $all = [];

        foreach ($counts as $key => $total) {
            $sendTimes = [];
            foreach ($plan as $ck => $c) {
                if ($c['list'] === $key && $c['status'] === 'sent') {
                    $sendTimes[$ck] = $now->copy()->addDays($c['days'])->setTime(9, 30);
                }
            }
            $double = $lists[$key]->opt_in === 'double';
            $used = [];
            $rows = [];

            for ($i = 0; $i < $total; $i++) {
                $first = $this->faker->firstName();
                $last = $this->faker->lastName();
                $base = strtolower(preg_replace('/[^a-z]/i', '', $first).'.'.preg_replace('/[^a-z]/i', '', $last));
                $email = $base.'@'.$domains[mt_rand(0, 3)];
                while (isset($used[$email])) {
                    $email = $base.mt_rand(2, 99).'@'.$domains[mt_rand(0, 3)];
                }
                $used[$email] = true;

                $r = mt_rand(0, 999) / 1000;
                $unconf = $double ? 0.16 : 0.07;
                $status = match (true) {
                    $r < 0.08 => 'unsubscribed',
                    $r < 0.14 => 'bounced',
                    $r < 0.14 + $unconf => 'unconfirmed',
                    default => 'confirmed',
                };

                $age = (int) floor(pow(mt_rand(0, 1000) / 1000, 0.8) * 220) + 1;
                $subscribedAt = $now->copy()->subDays($age)->subMinutes(mt_rand(0, 1439));
                $row = [
                    'uid' => $this->faker->uuid(),
                    'customer_id' => $this->cid,
                    'list_id' => $lists[$key]->id,
                    'email' => $email,
                    'status' => $status,
                    'source' => ['form', 'form', 'import', 'api', 'manual'][mt_rand(0, 4)],
                    'ip' => '198.51.100.'.mt_rand(1, 254),
                    'subscribed_at' => $subscribedAt,
                    'subscribe_ip' => '198.51.100.'.mt_rand(1, 254),
                    'confirmed_at' => $status === 'unconfirmed' ? null : $subscribedAt->copy()->addMinutes($double ? mt_rand(1, 600) : 0),
                    'unsubscribed_at' => null,
                    'unsubscribe_reason' => null,
                    'bounce_type' => null,
                    'last_bounce_at' => null,
                    'last_bounce_reason' => null,
                    'bounces_count' => 0,
                    'complaints_count' => 0,
                    'timezone' => ['UTC', 'America/New_York', 'Europe/London', 'Asia/Kolkata'][mt_rand(0, 3)],
                    'created_at' => $subscribedAt,
                    'updated_at' => $subscribedAt,
                    // not columns - stripped before insert
                    '_first' => $first, '_last' => $last, '_event' => null, '_event_at' => null,
                ];

                if ($status === 'unsubscribed' || $status === 'bounced') {
                    $cands = array_filter($sendTimes, fn (Carbon $t) => $t->gt($subscribedAt));
                    $evKey = $cands !== [] && mt_rand(0, 9) < 8 ? array_keys($cands)[mt_rand(0, count($cands) - 1)] : null;
                    $row['_event'] = $evKey;

                    if ($status === 'unsubscribed') {
                        $row['unsubscribed_at'] = $evKey ? $sendTimes[$evKey]->copy()->addMinutes(mt_rand(20, 2800)) : $subscribedAt->copy()->addDays(mt_rand(1, max(1, $age - 1)));
                        $row['unsubscribe_reason'] = ['No longer interested', 'Too many emails', 'Not relevant', 'Never signed up'][mt_rand(0, 3)];
                        $row['_event_at'] = $row['unsubscribed_at'];
                    } else {
                        $hard = mt_rand(0, 9) < 7;
                        $row['bounce_type'] = $hard ? 'hard' : 'soft';
                        $row['last_bounce_at'] = $evKey ? $sendTimes[$evKey]->copy()->addMinutes(mt_rand(1, 30)) : $subscribedAt->copy()->addMinutes(5);
                        $row['last_bounce_reason'] = $hard ? '550 5.1.1 mailbox unavailable' : '452 4.2.2 mailbox full';
                        $row['bounces_count'] = $hard ? 1 : 3;
                        $row['_event_at'] = $row['last_bounce_at'];
                    }
                }

                $rows[] = $row;
            }

            $insert = array_map(fn (array $r) => array_filter($r, fn ($k) => $k[0] !== '_', ARRAY_FILTER_USE_KEY), $rows);
            foreach (array_chunk($insert, 100) as $chunk) {
                DB::table('subscribers')->insert($chunk);
            }

            $ids = DB::table('subscribers')->where('list_id', $lists[$key]->id)->pluck('id', 'uid');
            $values = [];
            $tags = [];
            $planWeights = ['free', 'free', 'free', 'pro', 'pro', 'business'];

            foreach ($rows as $j => &$r) {
                $r['id'] = $ids[$r['uid']];
                $stamp = $r['created_at'];
                $vals = [
                    'FNAME' => $r['_first'],
                    'LNAME' => $r['_last'],
                    'PLAN' => $planWeights[mt_rand(0, 5)],
                    'SIGNUP_DATE' => $r['subscribed_at']->toDateString(),
                ];
                if (mt_rand(0, 9) < 6) {
                    $vals['COMPANY'] = $this->faker->company();
                }
                $r['_plan'] = $vals['PLAN'];
                foreach ($vals as $tag => $v) {
                    $values[] = ['customer_id' => $this->cid, 'subscriber_id' => $r['id'], 'list_field_id' => $fields[$key][$tag]->id, 'value' => $v, 'created_at' => $stamp, 'updated_at' => $stamp];
                }
                $pool = $tagPools[$key];
                shuffle($pool);
                foreach (array_slice($pool, 0, mt_rand(0, 3)) as $t) {
                    $tags[] = ['customer_id' => $this->cid, 'subscriber_id' => $r['id'], 'name' => $t, 'created_at' => $stamp, 'updated_at' => $stamp];
                }
            }
            unset($r);

            foreach (array_chunk($values, 200) as $chunk) {
                DB::table('subscriber_field_values')->insert($chunk);
            }
            foreach (array_chunk($tags, 200) as $chunk) {
                DB::table('subscriber_tags')->insert($chunk);
            }

            $all[$key] = $rows;
        }

        return $all;
    }

    /** @return array<string, ListSegment> */
    private function segments(array $lists, array $fields): array
    {
        $make = function (string $list, string $name, array $cond) use ($lists): ListSegment {
            $seg = ListSegment::withoutTenancy()->create([
                'uid' => $this->faker->uuid(),
                'customer_id' => $this->cid,
                'list_id' => $lists[$list]->id,
                'name' => $name,
                'match_type' => SegmentMatchType::All,
            ]);
            ListSegmentCondition::withoutTenancy()->create(['customer_id' => $this->cid, 'list_segment_id' => $seg->id, 'sort_order' => 0] + $cond);

            return $seg;
        };

        return [
            'pro' => $make('news', 'Pro plan readers', [
                'type' => SegmentConditionType::Field, 'list_field_id' => $fields['news']['PLAN']->id,
                'operator' => SegmentOperator::Is, 'value' => 'pro',
            ]),
            'engaged' => $make('news', 'Opened a campaign (90 days)', [
                'type' => SegmentConditionType::Campaign, 'operator' => SegmentOperator::Opened,
                'settings' => ['within_days' => 90],
            ]),
            'recent' => $make('product', 'Recent signups (60 days)', [
                'type' => SegmentConditionType::Date, 'field_key' => ListSegmentCondition::KEY_SUBSCRIBED,
                'operator' => SegmentOperator::After, 'value' => $this->now->copy()->subDays(60)->toDateString(),
            ]),
        ];
    }

    private function wrap(string $heading, string $body, string $cta): string
    {
        return '<!doctype html><html><body style="margin:0;background:#f4f5f7;font-family:Arial,sans-serif">'
            .'<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px">'
            .'<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:6px">'
            .'<tr><td style="padding:32px"><h1 style="margin:0 0 16px;font-size:24px;color:#1f2937">'.$heading.'</h1>'
            .'<p style="font-size:16px;line-height:24px;color:#374151">Hi {SUBSCRIBER_NAME},</p>'
            .'<p style="font-size:16px;line-height:24px;color:#374151">'.$body.'</p>'
            .'<p><a href="https://example.com/'.$cta.'" style="display:inline-block;background:#2563eb;color:#fff;padding:12px 20px;border-radius:4px;text-decoration:none">Read more</a></p>'
            .'</td></tr><tr><td style="padding:16px 32px;font-size:12px;color:#6b7280">Demo Company, 100 Example Street, Springfield. '
            .'<a href="{UNSUBSCRIBE_URL}">Unsubscribe</a></td></tr></table></td></tr></table></body></html>';
    }

    /** @return list<EmailTemplate> */
    private function templates(): array
    {
        $defs = [
            ['Newsletter - clean', 'The latest from our team', 'Here is what we have been working on this week, plus a few tips to get more from your account.', 'blog', ['newsletter']],
            ['Product announcement', 'Something new is live', 'We have shipped an update that makes your reports faster and easier to share with your team.', 'product', ['product', 'announcement']],
            ['Webinar invitation', 'Join us live', 'Reserve your seat for our next session. We will cover practical steps and leave time for questions.', 'webinars', ['event']],
            ['Seasonal offer', 'A thank-you from us', 'To say thanks for being with us this year, enjoy a small gift on your next renewal.', 'offers', ['promo']],
        ];
        $out = [];

        foreach ($defs as $i => [$name, $h, $body, $cta, $tags]) {
            $out[] = EmailTemplate::withoutTenancy()->create([
                'uid' => $this->faker->uuid(),
                'customer_id' => $this->cid,
                'name' => $name,
                'builder' => TemplateBuilder::Html,
                'content_html' => $this->wrap($h, $body, $cta),
                'sort_order' => $i,
                'tags' => $tags,
            ]);
        }

        return $out;
    }

    private function campaigns(array $lists, array $subs, array $plan, array $templates, array $segments, DeliveryServer $server): void
    {
        $urls = [
            ['https://example.com/blog/getting-started', 'https://example.com/pricing', 'https://example.com/docs/reports', 'https://example.org/community'],
        ][0];
        $devices = [['desktop', 'macOS', 'Safari', 'Apple Mail'], ['mobile', 'iOS', 'Safari', 'Apple Mail'], ['mobile', 'Android', 'Chrome', 'Gmail'], ['desktop', 'Windows', 'Chrome', 'Outlook'], ['desktop', 'Windows', 'Edge', 'Outlook']];
        $geo = [['US', 'California', 'San Jose'], ['GB', 'England', 'London'], ['DE', 'Berlin', 'Berlin'], ['CA', 'Ontario', 'Toronto'], ['IN', 'Karnataka', 'Bengaluru'], ['AU', 'New South Wales', 'Sydney']];

        $event = function (array $base, Carbon $at) use ($devices, $geo): array {
            $d = $devices[mt_rand(0, 4)];
            $g = $geo[mt_rand(0, 5)];

            return $base + [
                'ip' => '203.0.113.'.mt_rand(1, 254),
                'user_agent' => $d[3].' ('.$d[1].')',
                'device' => $d[0], 'os' => $d[1], 'browser' => $d[2], 'client' => $d[3],
                'country' => $g[0], 'region' => $g[1], 'city' => $g[2],
                'created_at' => $at,
            ];
        };

        $lastOpen = [];
        $lastClick = [];

        foreach ($plan as $ck => $c) {
            $list = $lists[$c['list']];
            $tpl = $templates[$c['tpl']];
            $sent = $c['status'] === 'sent';
            $sendAt = $this->now->copy()->addDays($c['days'])->setTime(9, 30);

            $campaign = Campaign::withoutTenancy()->create([
                'uid' => $this->faker->uuid(),
                'customer_id' => $this->cid,
                'email_list_id' => $list->id,
                'list_segment_id' => isset($c['segment']) ? $segments[$c['segment']]->id : null,
                'template_id' => $tpl->id,
                'name' => $c['name'],
                'type' => CampaignType::Regular,
                'status' => match ($c['status']) {
                    'sent' => CampaignStatus::Sent,
                    'scheduled' => CampaignStatus::Scheduled,
                    default => CampaignStatus::Draft,
                },
                'from_name' => 'Demo Company',
                'from_email' => 'news@news.example.com',
                'reply_to' => 'hello@news.example.com',
                'subject' => $c['subject'],
                'preheader' => 'A quick read from the Demo Company team',
                'send_at' => $c['status'] === 'draft' ? null : $sendAt,
                'timezone' => 'UTC',
                'builder' => TemplateBuilder::Html,
                'content_html' => $tpl->content_html,
                'auto_plain_text' => true,
                'track_opens' => true,
                'track_clicks' => true,
                'delivery_server_ids' => [$server->id],
                'created_at' => $sent ? $sendAt->copy()->subDays(2) : $this->now->copy()->subDays(mt_rand(1, 4)),
                'updated_at' => $sent ? $sendAt : $this->now,
            ]);
            DB::table('campaign_target_lists')->insert(['campaign_id' => $campaign->id, 'email_list_id' => $list->id]);

            if (! $sent) {
                continue;
            }

            // Targets: subscribed before the send and still reachable at that moment.
            $targets = array_values(array_filter($subs[$c['list']], function (array $r) use ($sendAt, $ck): bool {
                if ($r['subscribed_at']->gte($sendAt) || $r['status'] === 'unconfirmed') {
                    return false;
                }
                if ($r['status'] === 'confirmed') {
                    return true;
                }

                return $r['_event'] !== null && ($r['_event'] === $ck || $r['_event_at']->gt($sendAt));
            }));

            $urlRows = [];
            foreach (array_slice($urls, 0, 3) as $u) {
                $urlRows[] = ['campaign_id' => $campaign->id, 'campaign_uid' => $campaign->uid, 'url' => $u, 'hash' => substr(hash('sha256', $u.$campaign->uid), 0, 40), 'created_at' => $sendAt, 'updated_at' => $sendAt];
            }
            DB::table('campaign_track_urls')->insert($urlRows);
            $urlIds = DB::table('campaign_track_urls')->where('campaign_id', $campaign->id)->pluck('id')->all();

            $deliveries = $opens = $clicks = $unsubs = $bounces = [];
            $daily = [];
            $bump = function (string $day, string $col, int $n = 1) use (&$daily): void {
                $daily[$day][$col] = ($daily[$day][$col] ?? 0) + $n;
            };
            $failed = 0;

            foreach ($targets as $r) {
                $sentAt = $sendAt->copy()->addSeconds(mt_rand(0, 1500));
                $isBounce = $r['status'] === 'bounced' && $r['_event'] === $ck;
                $fail = ! $isBounce && mt_rand(0, 99) < 2;
                $deliveries[] = [
                    'campaign_id' => $campaign->id, 'subscriber_id' => $r['id'], 'delivery_server_id' => $server->id,
                    'status' => $fail ? 'failed' : 'sent',
                    'message_id' => $fail ? null : substr(md5($campaign->uid.$r['uid']), 0, 24).'@news.example.com',
                    'response' => $fail ? '421 4.7.0 temporary rate limit' : '250 2.0.0 OK (log)',
                    'retries' => $fail ? 3 : 0, 'created_at' => $sentAt, 'updated_at' => $sentAt,
                ];
                $day = $sentAt->toDateString();

                if ($fail) {
                    $failed++;
                    $bump($day, 'failed');

                    continue;
                }
                $bump($day, 'sent');

                if ($isBounce) {
                    $bounces[] = [
                        'customer_id' => $this->cid, 'campaign_id' => $campaign->id, 'subscriber_id' => $r['id'],
                        'bounce_type' => $r['bounce_type'], 'email' => $r['email'],
                        'message_id' => substr(md5($campaign->uid.$r['uid']), 0, 24).'@news.example.com',
                        'message' => $r['last_bounce_reason'], 'created_at' => $r['last_bounce_at'],
                    ];

                    continue;
                }

                $unsubHere = $r['status'] === 'unsubscribed' && $r['_event'] === $ck;
                $opened = $unsubHere ? mt_rand(0, 9) < 8 : mt_rand(0, 999) / 1000 < $c['open'];
                if (! $opened) {
                    continue;
                }

                $firstOpen = $sentAt->copy()->addMinutes((int) min(4000, -log(max(0.001, mt_rand(1, 1000) / 1000)) * 240) + 2);
                if ($unsubHere && $firstOpen->gte($r['unsubscribed_at'])) {
                    $firstOpen = $r['unsubscribed_at']->copy()->subMinutes(5);
                }
                $n = mt_rand(1, 10) > 7 ? mt_rand(2, 4) : 1;
                for ($k = 0; $k < $n; $k++) {
                    $at = $firstOpen->copy()->addMinutes($k === 0 ? 0 : mt_rand(30, 4000));
                    if ($at->gt($this->now)) {
                        continue;
                    }
                    $opens[] = $event(['campaign_id' => $campaign->id, 'campaign_uid' => $campaign->uid, 'subscriber_uid' => $r['uid']], $at);
                    $bump($at->toDateString(), 'opens');
                    if ($k === 0) {
                        $bump($at->toDateString(), 'unique_opens');
                    }
                    $lastOpen[$r['id']] = max($lastOpen[$r['id']] ?? '', $at->toDateTimeString());
                }

                if (mt_rand(1, 100) <= 22 && ! $unsubHere) {
                    $cn = mt_rand(1, 10) > 7 ? 2 : 1;
                    $picked = [];
                    for ($k = 0; $k < $cn; $k++) {
                        $uid = $urlIds[mt_rand(0, 2)];
                        $at = $firstOpen->copy()->addMinutes(mt_rand(1, 90));
                        if ($at->gt($this->now)) {
                            continue;
                        }
                        $clicks[] = $event(['campaign_id' => $campaign->id, 'campaign_uid' => $campaign->uid, 'subscriber_uid' => $r['uid'], 'url_id' => $uid], $at);
                        $bump($at->toDateString(), 'clicks');
                        if (! isset($picked[$uid])) {
                            $picked[$uid] = true;
                            $bump($at->toDateString(), 'unique_clicks');
                        }
                        $lastClick[$r['id']] = max($lastClick[$r['id']] ?? '', $at->toDateTimeString());
                    }
                }

                if ($unsubHere) {
                    $unsubs[] = $event(['campaign_id' => $campaign->id, 'campaign_uid' => $campaign->uid, 'subscriber_uid' => $r['uid']], $r['unsubscribed_at']);
                    $bump($r['unsubscribed_at']->toDateString(), 'unsubscribes');
                }
            }

            // Unsubscribers who were mailed but did not open still produce the unsubscribe row.
            $seen = array_column($unsubs, 'subscriber_uid');
            foreach ($targets as $r) {
                if ($r['status'] === 'unsubscribed' && $r['_event'] === $ck && ! in_array($r['uid'], $seen, true)) {
                    $unsubs[] = $event(['campaign_id' => $campaign->id, 'campaign_uid' => $campaign->uid, 'subscriber_uid' => $r['uid']], $r['unsubscribed_at']);
                    $bump($r['unsubscribed_at']->toDateString(), 'unsubscribes');
                }
            }

            foreach ([['campaign_delivery_logs', $deliveries], ['campaign_track_opens', $opens], ['campaign_track_clicks', $clicks], ['campaign_track_unsubscribes', $unsubs], ['campaign_bounce_logs', $bounces]] as [$table, $rows]) {
                foreach (array_chunk($rows, 100) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
            }

            ksort($daily);
            foreach ($daily as $day => $d) {
                DB::table('campaign_stats_daily')->insert([
                    'campaign_id' => $campaign->id, 'day' => $day,
                    'sent' => $d['sent'] ?? 0, 'failed' => $d['failed'] ?? 0, 'skipped' => 0,
                    'opens' => $d['opens'] ?? 0, 'unique_opens' => $d['unique_opens'] ?? 0,
                    'clicks' => $d['clicks'] ?? 0, 'unique_clicks' => $d['unique_clicks'] ?? 0,
                    'unsubscribes' => $d['unsubscribes'] ?? 0,
                    'created_at' => $this->now, 'updated_at' => $this->now,
                ]);
            }

            $campaign->forceFill([
                'delivery_total' => count($targets),
                'delivery_sent' => count($targets) - $failed,
                'delivery_failed' => $failed,
                'send_started_at' => $sendAt,
                'send_finished_at' => $sendAt->copy()->addMinutes(26),
            ])->saveQuietly();
        }

        foreach ($lastOpen as $id => $at) {
            DB::table('subscribers')->where('id', $id)->update(['last_open_at' => $at]);
        }
        foreach ($lastClick as $id => $at) {
            DB::table('subscribers')->where('id', $id)->update(['last_click_at' => $at]);
        }
    }

    private function forms(array $lists, array $fields): void
    {
        $defs = [
            ['news', 'Newsletter signup', FormType::Embedded, 'Get the weekly digest', 'Subscribe'],
            ['product', 'Release notes pop-up', FormType::Popup, 'Never miss a release', 'Keep me posted'],
        ];

        foreach ($defs as [$key, $name, $type, $heading, $submit]) {
            $form = Form::withoutTenancy()->create([
                'uid' => $this->faker->uuid(),
                'customer_id' => $this->cid,
                'list_id' => $lists[$key]->id,
                'name' => $name,
                'type' => $type,
                'status' => FormStatus::Active,
                'heading' => $heading,
                'description' => 'One short email a week. Unsubscribe any time.',
                'submit_label' => $submit,
                'email_label' => 'Email address',
                'success_mode' => 'message',
                'success_message' => 'Thanks! Please check your inbox.',
                'consent_enabled' => true,
                'consent_required' => true,
                'consent_text' => 'I agree to receive emails from Demo Company.',
                'captcha_enabled' => false,
            ]);

            $rows = [
                ['EMAIL', 'Email address', ListFieldType::Email, true, null],
                ['FNAME', 'First name', ListFieldType::Text, false, $fields[$key]['FNAME']->id],
            ];
            foreach ($rows as $i => [$tag, $label, $ftype, $req, $lfid]) {
                FormField::withoutTenancy()->create([
                    'customer_id' => $this->cid, 'form_id' => $form->id, 'list_field_id' => $lfid,
                    'type' => $ftype, 'tag' => $tag, 'label' => $label, 'required' => $req, 'sort_order' => $i,
                ]);
            }
        }
    }

    private function blacklist(): void
    {
        $rows = [
            ['bounce.trap@example.net', 0, 'Repeated hard bounces', 'bounce'],
            ['abuse.reporter@example.org', 0, 'Spam complaint (feedback loop)', 'fbl'],
            ['legal.hold@example.com', 0, 'Asked never to be contacted', 'manual'],
            ['test.mailbox@example.net', 0, 'Internal test address', 'manual'],
            ['no.reply.sink@example.org', 0, 'Role account that never reads mail', 'manual'],
            ['imported.blocked@example.com', 0, 'Blocked during list import', 'import'],
            ['spamtrap.example.org', 1, 'Known spam-trap domain', 'manual'],
            ['disposable.example.net', 1, 'Disposable mailbox provider', 'manual'],
        ];
        foreach ($rows as [$email, $isDomain, $reason, $source]) {
            DB::table('global_blacklist_emails')->insert([
                'email' => $email, 'is_domain' => $isDomain, 'reason' => $reason, 'source' => $source,
                'customer_id' => $this->cid, 'created_at' => $this->now->copy()->subDays(mt_rand(1, 60)), 'updated_at' => $this->now,
            ]);
        }

        $list = SuppressionList::withoutTenancy()->create([
            'uid' => $this->faker->uuid(),
            'customer_id' => $this->cid,
            'name' => 'Do not contact',
            'description' => 'Addresses excluded from every campaign.',
            'emails_count' => 30,
        ]);
        $emails = [];
        for ($i = 1; $i <= 30; $i++) {
            $emails[] = ['suppression_list_id' => $list->id, 'email' => sprintf('suppressed.%02d@example.%s', $i, ['com', 'net', 'org'][$i % 3]), 'created_at' => $this->now, 'updated_at' => $this->now];
        }
        DB::table('suppression_list_emails')->insert($emails);
    }
}
