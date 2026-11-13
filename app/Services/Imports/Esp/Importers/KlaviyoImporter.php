<?php

declare(strict_types=1);

namespace App\Services\Imports\Esp\Importers;

use App\Services\Imports\Esp\AbstractEspImporter;
use App\Services\Imports\Esp\EspPage;

/**
 * Klaviyo API (spec §2.2.7 F-2.2.7-006).
 *
 * Documented contract used here:
 *   GET https://a.klaviyo.com/api/profiles?page[size]=
 *   headers `Authorization: Klaviyo-API-Key {private key}` and the MANDATORY
 *   `revision: YYYY-MM-DD` date-versioning header.
 *
 * Klaviyo is cursor-paginated and returns `links.next` as an ABSOLUTE URL,
 * which this adapter uses verbatim as the next-page cursor.
 */
final class KlaviyoImporter extends AbstractEspImporter
{
    public const ENDPOINT = 'https://a.klaviyo.com/api/profiles';

    /** Klaviyo pins behaviour to a dated API revision; bumping it is a decision. */
    public const REVISION = '2024-10-15';

    /** Klaviyo caps profile pages at 100. */
    protected const PAGE_SIZE = 100;

    /**
     * Scalar profile attributes worth importing. The rest of the Klaviyo
     * profile payload is nested objects (`location`, `subscriptions`, link
     * blocks) or bookkeeping timestamps, which would otherwise auto-create a
     * pile of junk Text fields on the target list.
     */
    private const ATTRIBUTES = ['first_name', 'last_name', 'organization', 'title', 'phone_number', 'external_id'];

    public function key(): string
    {
        return 'klaviyo';
    }

    public function name(): string
    {
        return 'Klaviyo';
    }

    public function credentialKeys(): array
    {
        return ['api_key'];
    }

    public function fetch(array $credentials, ?string $cursor): EspPage
    {
        $apiKey = $this->credential($credentials, 'api_key');

        $request = $this->http()->withHeaders([
            'Authorization' => 'Klaviyo-API-Key '.$apiKey,
            'revision' => self::REVISION,
        ]);

        $response = $this->send(fn () => $cursor === null
            ? $request->get(self::ENDPOINT, ['page[size]' => self::PAGE_SIZE])
            : $request->get($cursor));

        $payload = (array) $response->json();
        $profiles = (array) ($payload['data'] ?? []);

        $rows = [];

        foreach ($profiles as $profile) {
            $attributes = (array) (((array) $profile)['attributes'] ?? []);
            $email = trim((string) ($attributes['email'] ?? ''));

            if ($email === '') {
                continue;
            }

            $properties = (array) ($attributes['properties'] ?? []);
            $scalars = array_intersect_key($attributes, array_flip(self::ATTRIBUTES));

            $rows[] = ['email' => $email, 'fields' => $this->flatten(array_merge($scalars, $properties))];
        }

        $next = trim((string) (((array) ($payload['links'] ?? []))['next'] ?? ''));

        return new EspPage($rows, ($profiles !== [] && $next !== '') ? $next : null);
    }
}
