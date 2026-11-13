<x-filament-panels::page>
    @php
        $subscriber = $this->getSubscriber();
        $consent = $this->consentRecord();
        $geo = $this->geoLocation();
        $engagement = $this->engagement();
        $timeline = $this->timeline();
        $timelineSummary = $this->timelineSummary();
        $memberships = $this->listMemberships();
        $dash = '—';
    @endphp

    <div style="display: grid; gap: 1.5rem; grid-template-columns: repeat(auto-fit, minmax(22rem, 1fr)); align-items: start;">
        {{-- Inline-editable standard + custom field values (F-2.2.5-001). --}}
        <x-filament::section style="grid-column: 1 / -1;">
            <x-slot name="heading">{{ __('subscribers.profile.fields_heading') }}</x-slot>

            <form wire:submit="saveFields" style="display: grid; gap: 1rem;">
                {{ $this->form }}

                <div>
                    <x-filament::button type="submit">
                        {{ __('subscribers.profile.save') }}
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>

        {{-- Status / IP / date panels (F-2.2.5-002). --}}
        <x-filament::section>
            <x-slot name="heading">{{ __('subscribers.profile.status_heading') }}</x-slot>

            <dl style="display: grid; gap: 0.5rem; font-size: 0.875rem;">
                <div style="display: flex; justify-content: space-between; gap: 1rem;">
                    <dt>{{ __('subscribers.index.status') }}</dt>
                    <dd><x-filament::badge :color="$subscriber->status->color()">{{ $subscriber->status->label() }}</x-filament::badge></dd>
                </div>
                <div style="display: flex; justify-content: space-between; gap: 1rem;">
                    <dt>{{ __('subscribers.index.source') }}</dt>
                    <dd>{{ $subscriber->source?->label() ?? $dash }}</dd>
                </div>
                <div style="display: flex; justify-content: space-between; gap: 1rem;">
                    <dt>{{ __('subscribers.index.ip') }}</dt>
                    <dd>{{ $subscriber->ip ?? $dash }}</dd>
                </div>
                <div style="display: flex; justify-content: space-between; gap: 1rem;">
                    <dt>{{ __('subscribers.profile.subscribed') }}</dt>
                    <dd>{{ $subscriber->subscribed_at?->format('Y-m-d H:i') ?? $dash }} ({{ $subscriber->subscribe_ip ?? $dash }})</dd>
                </div>
                <div style="display: flex; justify-content: space-between; gap: 1rem;">
                    <dt>{{ __('subscribers.profile.confirmed') }}</dt>
                    <dd>{{ $subscriber->confirmed_at?->format('Y-m-d H:i') ?? $dash }} ({{ $subscriber->confirm_ip ?? $dash }})</dd>
                </div>
                <div style="display: flex; justify-content: space-between; gap: 1rem;">
                    <dt>{{ __('subscribers.profile.unsubscribed') }}</dt>
                    <dd>{{ $subscriber->unsubscribed_at?->format('Y-m-d H:i') ?? $dash }} ({{ $subscriber->unsubscribe_ip ?? $dash }})</dd>
                </div>
                @if ($subscriber->unsubscribe_reason)
                    <div style="display: flex; justify-content: space-between; gap: 1rem;">
                        <dt>{{ __('subscribers.profile.unsubscribe_reason') }}</dt>
                        <dd>{{ $subscriber->unsubscribe_reason }}</dd>
                    </div>
                @endif
            </dl>
        </x-filament::section>

        {{-- Geo location + timezone (F-2.2.5-003). Every value is either
             resolved or an explicit "unknown" — never guessed (D-10). --}}
        <x-filament::section>
            <x-slot name="heading">{{ __('subscribers.profile.geo_heading') }}</x-slot>

            <dl style="display: grid; gap: 0.5rem; font-size: 0.875rem;">
                <div style="display: flex; justify-content: space-between; gap: 1rem;">
                    <dt>{{ __('subscribers.profile.geo_country') }}</dt>
                    <dd>{{ $geo['country'] ?? __('subscribers.profile.geo_unknown') }}</dd>
                </div>
                <div style="display: flex; justify-content: space-between; gap: 1rem;">
                    <dt>{{ __('subscribers.profile.geo_region') }}</dt>
                    <dd>{{ $geo['region'] ?? __('subscribers.profile.geo_unknown') }}</dd>
                </div>
                <div style="display: flex; justify-content: space-between; gap: 1rem;">
                    <dt>{{ __('subscribers.profile.geo_city') }}</dt>
                    <dd>{{ $geo['city'] ?? __('subscribers.profile.geo_unknown') }}</dd>
                </div>
                <div style="display: flex; justify-content: space-between; gap: 1rem;">
                    <dt>{{ __('subscribers.profile.geo_timezone') }}</dt>
                    <dd>{{ $geo['timezone'] ?? __('subscribers.profile.geo_unknown') }}</dd>
                </div>
                <div style="display: flex; justify-content: space-between; gap: 1rem;">
                    <dt>{{ __('subscribers.profile.geo_resolved_from') }}</dt>
                    <dd>{{ $geo['ip'] ?? $dash }}</dd>
                </div>
                @if ($geo['declared'])
                    <div style="display: flex; justify-content: space-between; gap: 1rem;">
                        <dt>{{ __('subscribers.profile.geo_declared') }}</dt>
                        <dd>{{ $geo['declared'] }}</dd>
                    </div>
                @endif
            </dl>

            @unless ($geo['configured'])
                <p class="fi-color-gray" style="font-size: 0.75rem; margin-top: 0.75rem;">
                    {{ __('subscribers.profile.geo_no_provider') }}
                </p>
            @endunless
        </x-filament::section>

        {{-- Consent record (F-2.2.5-008, GDPR). --}}
        <x-filament::section>
            <x-slot name="heading">{{ __('subscribers.profile.consent_heading') }}</x-slot>

            <dl style="display: grid; gap: 0.5rem; font-size: 0.875rem;">
                @if ($consent['text'])
                    <div>
                        <dt style="font-weight: 600;">{{ __('subscribers.profile.consent_text') }}</dt>
                        <dd>{{ $consent['text'] }}</dd>
                    </div>
                @endif
                @foreach ($consent['granted'] as $row)
                    <div style="display: flex; justify-content: space-between; gap: 1rem;">
                        <dt>{{ $row['label'] }}</dt>
                        <dd>{{ $row['value'] }}</dd>
                    </div>
                @endforeach
                <div style="display: flex; justify-content: space-between; gap: 1rem;">
                    <dt>{{ __('subscribers.profile.consent_at') }}</dt>
                    <dd>{{ $consent['at']?->format('Y-m-d H:i') ?? $dash }}</dd>
                </div>
                <div style="display: flex; justify-content: space-between; gap: 1rem;">
                    <dt>{{ __('subscribers.profile.consent_ip') }}</dt>
                    <dd>{{ $consent['ip'] ?? $dash }}</dd>
                </div>
                @unless ($consent['logging'])
                    <p class="fi-color-gray" style="font-size: 0.8125rem;">{{ __('subscribers.profile.consent_logging_off') }}</p>
                @endunless
            </dl>
        </x-filament::section>

        {{-- Deliverability notes (F-2.2.5-009). --}}
        <x-filament::section>
            <x-slot name="heading">{{ __('subscribers.profile.deliverability_heading') }}</x-slot>

            <dl style="display: grid; gap: 0.5rem; font-size: 0.875rem;">
                <div style="display: flex; justify-content: space-between; gap: 1rem;">
                    <dt>{{ __('subscribers.profile.last_bounce') }}</dt>
                    <dd>{{ $subscriber->last_bounce_at?->format('Y-m-d H:i') ?? $dash }}</dd>
                </div>
                <div style="display: flex; justify-content: space-between; gap: 1rem;">
                    <dt>{{ __('subscribers.profile.bounce_type') }}</dt>
                    <dd>{{ $subscriber->bounce_type ? __('subscribers.profile.bounce_' . $subscriber->bounce_type) : $dash }}</dd>
                </div>
                <div style="display: flex; justify-content: space-between; gap: 1rem;">
                    <dt>{{ __('subscribers.profile.bounce_reason') }}</dt>
                    <dd>{{ $subscriber->last_bounce_reason ?? $dash }}</dd>
                </div>
                <div style="display: flex; justify-content: space-between; gap: 1rem;">
                    <dt>{{ __('subscribers.profile.bounces_count') }}</dt>
                    <dd>{{ number_format($subscriber->bounces_count) }}</dd>
                </div>
                <div style="display: flex; justify-content: space-between; gap: 1rem;">
                    <dt>{{ __('subscribers.profile.complaints_count') }}</dt>
                    <dd>{{ number_format($subscriber->complaints_count) }}</dd>
                </div>
            </dl>
        </x-filament::section>

        {{-- Engagement score / rating (F-2.2.5-006). Derived at read time from
             the tracking tables — see App\Services\Subscribers\EngagementScorer
             for the exact formula. --}}
        <x-filament::section>
            <x-slot name="heading">{{ __('subscribers.engagement_score.heading') }}</x-slot>

            <dl style="display: grid; gap: 0.5rem; font-size: 0.875rem;">
                <div style="display: flex; justify-content: space-between; gap: 1rem;">
                    <dt>{{ __('subscribers.engagement_score.score') }}</dt>
                    <dd style="font-weight: 600;">{{ $engagement['score'] }} / 100</dd>
                </div>
                <div style="display: flex; justify-content: space-between; gap: 1rem;">
                    <dt>{{ __('subscribers.engagement_score.rating_label') }}</dt>
                    <dd>
                        <x-filament::badge :color="\App\Services\Subscribers\EngagementScorer::ratingColor($engagement['rating'])">
                            {{ \App\Services\Subscribers\EngagementScorer::ratingLabel($engagement['rating']) }}
                        </x-filament::badge>
                    </dd>
                </div>
            </dl>

            <p class="fi-color-gray" style="font-size: 0.75rem; margin-top: 0.75rem;">
                {{ __('subscribers.engagement_score.counts', [
                    'opens' => $engagement['opens'],
                    'clicks' => $engagement['clicks'],
                    'bounces' => $engagement['bounces'],
                    'complaints' => $engagement['complaints'],
                    'unsubscribes' => $engagement['unsubscribes'],
                ]) }}
            </p>
            <p class="fi-color-gray" style="font-size: 0.75rem; margin-top: 0.25rem;">
                {{ __('subscribers.engagement_score.explanation') }}
            </p>
        </x-filament::section>

        {{-- Cross-list view (F-2.2.5-012): every list of THIS customer that
             holds this email address. Never another tenant's. --}}
        <x-filament::section>
            <x-slot name="heading">{{ __('subscribers.memberships.heading') }}</x-slot>

            @if (count($memberships) <= 1)
                <p class="fi-color-gray" style="font-size: 0.875rem;">{{ __('subscribers.memberships.none') }}</p>
            @endif

            <dl style="display: grid; gap: 0.5rem; font-size: 0.875rem;">
                @foreach ($memberships as $membership)
                    <div style="display: flex; justify-content: space-between; gap: 1rem;">
                        <dt>
                            {{ $membership['list_name'] }}
                            @if ($membership['current'])
                                <span class="fi-color-gray" style="font-size: 0.75rem;">({{ __('subscribers.memberships.current') }})</span>
                            @endif
                        </dt>
                        <dd style="display: flex; gap: 0.5rem; align-items: center;">
                            <x-filament::badge :color="$membership['status']->color()">{{ $membership['status']->label() }}</x-filament::badge>
                            <span class="fi-color-gray">{{ $membership['subscribed_at']?->format('Y-m-d') ?? $dash }}</span>
                        </dd>
                    </div>
                @endforeach
            </dl>
        </x-filament::section>

        {{-- Campaign activity timeline (F-2.2.5-005): deliveries, opens,
             clicks (with the URL hit), bounces, complaints and unsubscribes,
             newest first. Read-only aggregation, no storage of its own. --}}
        <x-filament::section style="grid-column: 1 / -1;">
            <x-slot name="heading">{{ __('subscribers.profile.timeline_heading') }}</x-slot>

            <p class="fi-color-gray" style="font-size: 0.75rem; margin-bottom: 0.75rem;">
                {{ __('subscribers.timeline.summary', [
                    'delivered' => $timelineSummary['delivered'],
                    'opens' => $timelineSummary['opens'],
                    'clicks' => $timelineSummary['clicks'],
                    'bounces' => $timelineSummary['bounces'],
                    'complaints' => $timelineSummary['complaints'],
                    'unsubscribes' => $timelineSummary['unsubscribes'],
                ]) }}
            </p>

            @if ($timeline === [])
                <p class="fi-color-gray" style="font-size: 0.875rem;">{{ __('subscribers.profile.timeline_placeholder') }}</p>
            @else
                <div style="overflow-x: auto;">
                    <table style="width: 100%; font-size: 0.875rem; text-align: start;">
                        <thead>
                            <tr>
                                <th style="text-align: start; padding: 0.25rem 0.5rem;">{{ __('subscribers.timeline.when') }}</th>
                                <th style="text-align: start; padding: 0.25rem 0.5rem;">{{ __('subscribers.timeline.event') }}</th>
                                <th style="text-align: start; padding: 0.25rem 0.5rem;">{{ __('subscribers.timeline.campaign') }}</th>
                                <th style="text-align: start; padding: 0.25rem 0.5rem;">{{ __('subscribers.timeline.detail') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($timeline as $event)
                                <tr>
                                    <td style="padding: 0.25rem 0.5rem; white-space: nowrap;">{{ $event['at']?->format('Y-m-d H:i') ?? $dash }}</td>
                                    <td style="padding: 0.25rem 0.5rem;">{{ __('subscribers.timeline.types.' . $event['type']) }}</td>
                                    <td style="padding: 0.25rem 0.5rem;">{{ $event['campaign_name'] ?? __('subscribers.timeline.unknown_campaign') }}</td>
                                    <td style="padding: 0.25rem 0.5rem; word-break: break-all;">{{ $event['detail'] ?? $dash }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
