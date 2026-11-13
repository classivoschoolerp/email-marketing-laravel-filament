<?php

declare(strict_types=1);

namespace App\Services\Imports\Esp\Importers;

use App\Services\Imports\Esp\AbstractEspImporter;
use App\Services\Imports\Esp\EspPage;
use App\Services\Imports\ImportFailedException;

/**
 * Mailchimp Marketing API v3 (spec §2.2.7 F-2.2.7-006).
 *
 * Documented contract used here:
 *   GET https://{dc}.api.mailchimp.com/3.0/lists/{list_id}/members
 *       ?count=&offset=&status=subscribed
 *   HTTP Basic auth, any username + the API key as the password.
 *
 * The datacenter prefix is NOT configured separately: Mailchimp keys carry
 * it as the suffix after the last dash ("…-us14" → host us14.api.…), which
 * is why a key without that suffix is rejected up front instead of hitting a
 * wrong host.
 *
 * Only `subscribed` members are pulled. Importing addresses Mailchimp
 * already has as unsubscribed/cleaned would resurrect them into a new list —
 * the same rule ListReader applies to list-to-list imports.
 */
final class MailchimpImporter extends AbstractEspImporter
{
    public function key(): string
    {
        return 'mailchimp';
    }

    public function name(): string
    {
        return 'Mailchimp';
    }

    public function credentialKeys(): array
    {
        return ['api_key', 'list_id'];
    }

    public function fetch(array $credentials, ?string $cursor): EspPage
    {
        $apiKey = $this->credential($credentials, 'api_key');
        $listId = $this->credential($credentials, 'list_id');
        $offset = max(0, (int) ($cursor ?? '0'));

        // Resolved BEFORE the request closure: a key without a datacenter
        // suffix is a credential error, not a transport error.
        $endpoint = $this->endpoint($apiKey, $listId);

        $response = $this->send(fn () => $this->http()
            ->withBasicAuth('emp', $apiKey)
            ->get($endpoint, [
                'count' => self::PAGE_SIZE,
                'offset' => $offset,
                'status' => 'subscribed',
            ]));

        $payload = (array) $response->json();
        $members = (array) ($payload['members'] ?? []);
        $total = (int) ($payload['total_items'] ?? 0);

        $rows = [];

        foreach ($members as $member) {
            $member = (array) $member;
            $email = trim((string) ($member['email_address'] ?? ''));

            if ($email === '') {
                continue;
            }

            $rows[] = ['email' => $email, 'fields' => $this->flatten((array) ($member['merge_fields'] ?? []))];
        }

        $next = $offset + count($members);

        return new EspPage(
            $rows,
            ($members !== [] && $next < $total) ? (string) $next : null,
        );
    }

    /** @throws ImportFailedException when the key carries no datacenter suffix */
    public function endpoint(string $apiKey, string $listId): string
    {
        $dash = strrpos($apiKey, '-');
        $datacenter = $dash === false ? '' : substr($apiKey, $dash + 1);

        if (! preg_match('/^[a-z]{2}[0-9]{1,3}$/', $datacenter)) {
            throw new ImportFailedException(__('imports.errors.esp_mailchimp_datacenter'));
        }

        return 'https://'.$datacenter.'.api.mailchimp.com/3.0/lists/'.rawurlencode($listId).'/members';
    }
}
