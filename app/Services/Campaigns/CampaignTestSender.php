<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Mail\CampaignTestMail;
use App\Models\Campaign;
use App\Services\Content\MergeTags;
use App\Services\Content\PlainTextGenerator;
use App\Services\Content\Spintax;
use Illuminate\Support\Facades\Mail;

/**
 * Send-test emails for a campaign (spec §2.3.5 F-2.3.5-003): one queued
 * mailable per address, rendered by the WP-13 canonical merge-tag engine
 * (sample subscriber data + real campaign/list values, modifier + default
 * forms included) and spintax resolved deterministically per address — so
 * two testers see two permutations, mirroring the per-subscriber sends.
 * Merge tags render before spintax (same order as the send path).
 */
class CampaignTestSender
{
    public function __construct(
        private readonly MergeTags $mergeTags,
        private readonly Spintax $spintax,
        private readonly PlainTextGenerator $plainText,
    ) {}

    /**
     * @param  list<string>  $addresses
     */
    public function send(Campaign $campaign, array $addresses): void
    {
        $list = $campaign->list()->withoutGlobalScopes()->first();
        $data = $this->mergeTags->sampleValues($campaign, $list);

        foreach ($addresses as $address) {
            $seed = 'test:'.$address.':'.$campaign->uid;
            $values = array_merge($data, [
                'SUBSCRIBER_EMAIL' => $address,
                'EMAIL' => $address,
            ]);

            $html = $this->spintax->render(
                $this->mergeTags->render((string) $campaign->content_html, $values, $seed),
                $seed,
            );

            $subject = $this->spintax->render(
                $this->mergeTags->render((string) $campaign->subject, $values, $seed),
                $seed,
            );

            $text = $campaign->auto_plain_text
                ? $this->plainText->generate($html)
                : (string) $campaign->plain_text;

            Mail::to($address)->queue(new CampaignTestMail(
                __('campaigns.test_email.subject', ['subject' => $subject]),
                $html,
                $text !== '' ? $text : null,
            ));
        }
    }
}
