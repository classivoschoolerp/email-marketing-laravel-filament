<?php

declare(strict_types=1);

/*
 * §2.3.5 F-2.3.5-006 — inbox rendering preview across 50+ clients.
 *
 * Its own file because the provider registry is content-module infrastructure
 * used by both campaigns and templates, and neither module owns it.
 */

return [
    'title' => 'Inbox rendering preview',
    'action' => 'Inbox preview',
    'run' => 'Run a new preview',
    'queued' => 'Preview queued. Rendering usually takes a few minutes.',
    'none' => 'No preview has been run for this campaign yet.',
    'pending' => 'Rendering — :done of :total clients done.',
    'completed' => 'Rendered in :count clients on :date.',
    'failed' => 'The preview failed: :error',
    'provider' => 'Provider',
    'clients' => 'Clients',
    'expired_note' => 'Screenshots are served from the provider and expire on their schedule; re-run the preview if an image no longer loads.',
    'scope' => 'Rendering a real client matrix needs a rendering farm, which this application cannot host. These screenshots come from your own Litmus or Email on Acid account over their published API.',

    'providers' => [
        'null' => 'Not configured',
        'litmus' => 'Litmus',
        'email_on_acid' => 'Email on Acid',
    ],

    'state' => [
        'complete' => 'Rendered',
        'processing' => 'Rendering',
        'failed' => 'Failed',
    ],

    'errors' => [
        'not_configured' => 'No inbox-preview provider is configured. Add a Litmus or Email on Acid account under Settings → Inbox preview; both are paid services and this application cannot render clients on its own.',
        'no_content' => 'There is nothing to render — the campaign or template has no content.',
        'no_id' => ':provider accepted the request but returned no test id.',
        'vendor' => ':provider replied :status: :body',
        'timed_out' => 'The provider did not finish within :minutes minutes.',
    ],
];
