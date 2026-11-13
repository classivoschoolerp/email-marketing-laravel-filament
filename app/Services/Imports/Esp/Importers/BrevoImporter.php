<?php

declare(strict_types=1);

namespace App\Services\Imports\Esp\Importers;

use App\Services\Imports\Esp\AbstractEspImporter;
use App\Services\Imports\Esp\EspPage;

/**
 * Brevo (ex-Sendinblue) API v3 (spec §2.2.7 F-2.2.7-006).
 *
 * Documented contract used here:
 *   GET https://api.brevo.com/v3/contacts?limit=&offset=
 *   header `api-key: {key}`
 *
 * Contacts come back as `{email, attributes: {FIRSTNAME, …}, emailBlacklisted}`.
 * Blacklisted contacts are dropped: Brevo's blacklist IS an unsubscribe, and
 * importing it into a fresh list would silently re-subscribe the address.
 */
final class BrevoImporter extends AbstractEspImporter
{
    public const ENDPOINT = 'https://api.brevo.com/v3/contacts';

    public function key(): string
    {
        return 'brevo';
    }

    public function name(): string
    {
        return 'Brevo';
    }

    public function credentialKeys(): array
    {
        return ['api_key'];
    }

    public function fetch(array $credentials, ?string $cursor): EspPage
    {
        $apiKey = $this->credential($credentials, 'api_key');
        $offset = max(0, (int) ($cursor ?? '0'));

        $response = $this->send(fn () => $this->http()
            ->withHeaders(['api-key' => $apiKey])
            ->get(self::ENDPOINT, [
                'limit' => self::PAGE_SIZE,
                'offset' => $offset,
            ]));

        $payload = (array) $response->json();
        $contacts = (array) ($payload['contacts'] ?? []);
        $total = (int) ($payload['count'] ?? 0);

        $rows = [];

        foreach ($contacts as $contact) {
            $contact = (array) $contact;
            $email = trim((string) ($contact['email'] ?? ''));

            if ($email === '' || ($contact['emailBlacklisted'] ?? false) === true) {
                continue;
            }

            $rows[] = ['email' => $email, 'fields' => $this->flatten((array) ($contact['attributes'] ?? []))];
        }

        $next = $offset + count($contacts);

        return new EspPage(
            $rows,
            ($contacts !== [] && $next < $total) ? (string) $next : null,
        );
    }
}
