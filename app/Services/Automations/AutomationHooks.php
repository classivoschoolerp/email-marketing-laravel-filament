<?php

declare(strict_types=1);

namespace App\Services\Automations;

use App\Enums\SubscriberSource;
use App\Enums\SubscriberStatus;
use App\Models\CampaignTrackClick;
use App\Models\CampaignTrackOpen;
use App\Models\Subscriber;
use App\Models\SubscriberFieldValue;
use App\Models\SubscriberTag;

/**
 * Registers the model-event listeners that feed the event-driven triggers
 * (spec §2.4.2 F-2.4.2-005..009, 011, 025 side effects). Called from
 * routes/modules/automations.php (D-41/D-51 precedent: providers file is
 * frozen mid-wave; revisit with route:cache at WP-24).
 *
 * Trigger sources:
 * - Subscriber created/updated → subscribed / unsubscribed (+ form_submitted
 *   when the row arrived through an embed/hosted form — WP-08 has no
 *   form_submissions table, so form submission ≡ form-sourced subscription;
 *   documented approximation).
 * - SubscriberFieldValue created/updated/deleted → field_changed.
 * - SubscriberTag created/deleted → tag_added / tag_removed.
 * - CampaignTrackOpen/Click created → campaign_opened / link_clicked
 *   (guarded by the cached campaignEventsEnabled switch — these hooks sit
 *   on the high-volume tracking ingest path).
 */
final class AutomationHooks
{
    /**
     * Guard keyed to the application instance (a static bool would survive
     * across test app rebuilds while the event dispatcher does not).
     */
    private const CONTAINER_FLAG = 'automations.hooks-registered';

    public static function register(): void
    {
        if (app()->bound(self::CONTAINER_FLAG)) {
            return;
        }

        app()->instance(self::CONTAINER_FLAG, true);

        Subscriber::created(function (Subscriber $subscriber): void {
            if (! $subscriber->status->isActive()) {
                return;
            }

            $dispatcher = app(AutomationEventDispatcher::class);
            $context = ['list_id' => (int) $subscriber->list_id];

            $dispatcher->record(AutomationEvents::SUBSCRIBED, $subscriber, $context);

            if ($subscriber->source === SubscriberSource::Form) {
                $dispatcher->record(AutomationEvents::FORM_SUBMITTED, $subscriber, $context);
            }
        });

        Subscriber::updated(function (Subscriber $subscriber): void {
            if (! $subscriber->wasChanged('status')) {
                return;
            }

            $original = $subscriber->getOriginal('status');
            $old = $original instanceof SubscriberStatus ? $original : SubscriberStatus::tryFrom((string) $original);
            $dispatcher = app(AutomationEventDispatcher::class);
            $context = ['list_id' => (int) $subscriber->list_id];

            if ($subscriber->status->isActive() && ($old === null || ! $old->isActive())) {
                $dispatcher->record(AutomationEvents::SUBSCRIBED, $subscriber, $context);
            }

            if ($subscriber->status === SubscriberStatus::Unsubscribed) {
                $dispatcher->record(AutomationEvents::UNSUBSCRIBED, $subscriber, $context);
            }
        });

        $fieldChanged = function (SubscriberFieldValue $value, bool $deleted = false): void {
            $subscriber = Subscriber::withoutTenancy()->find($value->subscriber_id);

            if ($subscriber === null) {
                return;
            }

            app(AutomationEventDispatcher::class)->record(AutomationEvents::FIELD_CHANGED, $subscriber, [
                'list_field_id' => (int) $value->list_field_id,
                'value' => $deleted ? null : $value->value,
            ]);
        };

        SubscriberFieldValue::created(fn (SubscriberFieldValue $value) => $fieldChanged($value));
        SubscriberFieldValue::updated(function (SubscriberFieldValue $value) use ($fieldChanged): void {
            if ($value->wasChanged('value')) {
                $fieldChanged($value);
            }
        });
        SubscriberFieldValue::deleted(fn (SubscriberFieldValue $value) => $fieldChanged($value, true));

        SubscriberTag::created(function (SubscriberTag $tag): void {
            $subscriber = Subscriber::withoutTenancy()->find($tag->subscriber_id);

            if ($subscriber !== null) {
                app(AutomationEventDispatcher::class)->record(AutomationEvents::TAG_ADDED, $subscriber, ['tag' => $tag->name]);
            }
        });

        SubscriberTag::deleted(function (SubscriberTag $tag): void {
            $subscriber = Subscriber::withoutTenancy()->find($tag->subscriber_id);

            if ($subscriber !== null) {
                app(AutomationEventDispatcher::class)->record(AutomationEvents::TAG_REMOVED, $subscriber, ['tag' => $tag->name]);
            }
        });

        CampaignTrackOpen::created(function (CampaignTrackOpen $open): void {
            $dispatcher = app(AutomationEventDispatcher::class);

            if (! $dispatcher->campaignEventsEnabled()) {
                return;
            }

            $subscriber = Subscriber::withoutTenancy()->where('uid', $open->subscriber_uid)->first();

            if ($subscriber !== null) {
                $dispatcher->record(AutomationEvents::CAMPAIGN_OPENED, $subscriber, [
                    'campaign_id' => (int) ($open->campaign_id ?? 0),
                    'campaign_uid' => (string) $open->campaign_uid,
                ]);
            }
        });

        CampaignTrackClick::created(function (CampaignTrackClick $click): void {
            $dispatcher = app(AutomationEventDispatcher::class);

            if (! $dispatcher->campaignEventsEnabled()) {
                return;
            }

            $subscriber = Subscriber::withoutTenancy()->where('uid', $click->subscriber_uid)->first();

            if ($subscriber === null) {
                return;
            }

            $url = (string) ($click->url()->value('url') ?? '');

            $dispatcher->record(AutomationEvents::LINK_CLICKED, $subscriber, [
                'campaign_id' => (int) ($click->campaign_id ?? 0),
                'campaign_uid' => (string) $click->campaign_uid,
                'url' => $url,
            ]);
        });
    }
}
