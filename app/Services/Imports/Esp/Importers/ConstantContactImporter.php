<?php

declare(strict_types=1);

namespace App\Services\Imports\Esp\Importers;

use App\Services\Imports\Esp\AbstractEspImporter;
use App\Services\Imports\Esp\EspPage;

/**
 * Constant Contact API v3 (spec §2.2.7 F-2.2.7-006).
 *
 * Documented contract used here:
 *   GET https://api.cc.email/v3/contacts?limit=&include=custom_fields
 *   header `Authorization: Bearer {access_token}` (OAuth2)
 *
 * Constant Contact is cursor-paginated: the response carries
 * `_links.next.href` as a PATH relative to the API host, which this adapter
 * carries through as the page cursor and re-attaches to the host.
 *
 * Credential note, stated plainly: v3 only accepts OAuth2 bearer tokens, so
 * the import form asks for an access token obtained from the operator's
 * Constant Contact app. No OAuth authorization-code dance is implemented
 * here — the token is pasted in and stored encrypted, and an expired token
 * surfaces as a failed import with the vendor's HTTP status.
 */
final class ConstantContactImporter extends AbstractEspImporter
{
    public const HOST = 'https://api.cc.email';

    public const ENDPOINT = self::HOST.'/v3/contacts';

    public function key(): string
    {
        return 'constant_contact';
    }

    public function name(): string
    {
        return 'Constant Contact';
    }

    public function credentialKeys(): array
    {
        return ['access_token'];
    }

    public function fetch(array $credentials, ?string $cursor): EspPage
    {
        $token = $this->credential($credentials, 'access_token');

        $request = $this->http()->withToken($token);

        $response = $this->send(fn () => $cursor === null
            ? $request->get(self::ENDPOINT, [
                'limit' => self::PAGE_SIZE,
                'include' => 'custom_fields',
            ])
            : $request->get(self::HOST.$cursor));

        $payload = (array) $response->json();
        $contacts = (array) ($payload['contacts'] ?? []);

        $rows = [];

        foreach ($contacts as $contact) {
            $contact = (array) $contact;
            $email = trim((string) (((array) ($contact['email_address'] ?? []))['address'] ?? ''));

            if ($email === '') {
                continue;
            }

            $attributes = [
                'first_name' => $contact['first_name'] ?? null,
                'last_name' => $contact['last_name'] ?? null,
                'job_title' => $contact['job_title'] ?? null,
                'company_name' => $contact['company_name'] ?? null,
            ];

            foreach ((array) ($contact['custom_fields'] ?? []) as $custom) {
                $custom = (array) $custom;
                $name = trim((string) ($custom['name'] ?? $custom['custom_field_id'] ?? ''));

                if ($name !== '') {
                    $attributes[$name] = $custom['value'] ?? null;
                }
            }

            $rows[] = ['email' => $email, 'fields' => $this->flatten($attributes)];
        }

        $next = trim((string) (((array) (((array) ($payload['_links'] ?? []))['next'] ?? []))['href'] ?? ''));

        return new EspPage($rows, ($contacts !== [] && $next !== '') ? $next : null);
    }
}
