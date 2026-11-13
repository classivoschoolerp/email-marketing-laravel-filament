<?php

declare(strict_types=1);

namespace App\Services\Templates\Stock;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Pexels stock image library (spec §2.5.4 F-2.5.4-010, WP-42).
 *
 * Documented API:
 *   GET https://api.pexels.com/v1/search?query=…&page=…&per_page=…
 *   Authorization: <api key>          (bare key, no scheme prefix — this is
 *                                      the one shape difference from Unsplash)
 */
class PexelsProvider implements StockImageProvider
{
    public const BASE_URL = 'https://api.pexels.com/v1';

    public function __construct(private readonly StockImageCredentials $credentials) {}

    public function key(): string
    {
        return 'pexels';
    }

    public function label(): string
    {
        return __('builder.stock.providers.pexels');
    }

    public function isConfigured(): bool
    {
        return $this->credentials->has($this->key());
    }

    /** @return list<string> */
    public function imageHosts(): array
    {
        return ['images.pexels.com'];
    }

    /** @return list<StockPhoto> */
    public function search(string $query, int $page = 1, int $perPage = 24): array
    {
        $apiKey = $this->credentials->get($this->key());

        if ($apiKey === null) {
            return [];
        }

        try {
            $response = Http::withHeaders(['Authorization' => $apiKey])
                ->timeout((int) config('emp-templates.stock.timeout', 10))
                ->get(self::BASE_URL.'/search', [
                    'query' => $query,
                    'page' => $page,
                    'per_page' => $perPage,
                ]);
        } catch (Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $out = [];

        foreach ((array) $response->json('photos', []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $thumb = (string) data_get($item, 'src.tiny', '');
            $full = (string) data_get($item, 'src.large', '');

            if ($thumb === '' || $full === '') {
                continue;
            }

            $out[] = new StockPhoto(
                provider: $this->key(),
                id: (string) data_get($item, 'id', ''),
                thumbUrl: $thumb,
                fullUrl: $full,
                author: (string) data_get($item, 'photographer', ''),
                authorUrl: (string) data_get($item, 'photographer_url', ''),
                alt: (string) (data_get($item, 'alt') ?? ''),
                width: (int) data_get($item, 'width', 0),
                height: (int) data_get($item, 'height', 0),
            );
        }

        return $out;
    }
}
